<?php

namespace App\Jobs;

use App\Contracts\TaskExtractor;
use App\Models\InputSource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRunLog;
use App\Models\User;
use App\Services\SystemSettingsResolver;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyzeInputSourceJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $inputSourceId) {}

    public function handle(TaskExtractor $extractor, SystemSettingsResolver $settingsResolver): void
    {
        $inputSource = InputSource::find($this->inputSourceId);

        if ($inputSource === null) {
            return;
        }

        $agentDriver = is_string($inputSource->agent_driver) && trim($inputSource->agent_driver) !== ''
            ? trim($inputSource->agent_driver)
            : $settingsResolver->effectiveAgentDriver();

        $analyzeSourceModel = $settingsResolver->analyzeSourceModel();
        $analyzeSourceReasoningEffort = $settingsResolver->analyzeSourceReasoningEffort();

        $inputSource->update([
            'analysis_status' => 'processing',
            'analysis_result' => null,
            'last_analysis_error' => null,
        ]);
        $this->log($inputSource, 'info', 'Input source analysis started', [
            'analyze_source_model' => $analyzeSourceModel,
            'analyze_source_reasoning_effort' => $analyzeSourceReasoningEffort,
        ]);

        try {
            $projects = Project::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project): array => $project->asSummary())
                ->values()
                ->toArray();

            $extractedItems = $extractor->extract($inputSource, $projects, $agentDriver);
            $items = $this->normalizeExtractedItems($extractedItems);
            $validProjectIds = Project::query()->pluck('id')->all();

            if ($items === []) {
                throw new Exception('No tasks could be extracted.');
            }

            DB::transaction(function () use ($inputSource, $items, $validProjectIds): void {
                foreach ($items as $item) {
                    $assignee = $this->resolveAssignee((string) Arr::get($item, 'assignee_github_username'));
                    $projectId = $this->resolveProjectId(Arr::get($item, 'project_id'), $validProjectIds);
                    $description = trim((string) Arr::get($item, 'description', ''));
                    $questions = $this->normalizeQuestions((array) Arr::get($item, 'questions', []));

                    if ($description === '') {
                        $description = (string) Arr::get($item, 'title', '');
                    }

                    if (! $description) {
                        $description = 'No description provided.';
                    }

                    if (! $assignee && Arr::get($item, 'assignee_github_username') !== null) {
                        $questions[] = sprintf(
                            'Unresolved assignee: %s. Please assign a user from local users.',
                            (string) Arr::get($item, 'assignee_github_username'),
                        );
                    }

                    if ($questions !== []) {
                        $description .= "\n\nQuestions:\n".implode(
                            "\n",
                            array_map(
                                static fn (string $question): string => sprintf('- %s', $question),
                                $questions,
                            ),
                        );
                    }

                    Task::create([
                        'title' => (string) Arr::get($item, 'title', 'Unnamed task'),
                        'description' => $description,
                        'status' => Task::STATUS_PENDING_APPROVAL,
                        'priority' => $this->normalizePriority((string) Arr::get($item, 'priority')),
                        'deadline' => $this->normalizeDate((string) Arr::get($item, 'deadline')),
                        'assignee_user_id' => $assignee?->id,
                        'project_id' => $projectId,
                        'source_input_id' => $inputSource->id,
                    ]);
                }
            });

            $inputSource->update([
                'analysis_status' => 'completed',
                'analysis_result' => [
                    'tasks' => $this->analysisResultTasks($items),
                    'task_count' => count($items),
                    'analyzed_at' => now()->toIso8601String(),
                ],
            ]);
            $this->log($inputSource, 'info', 'Input source analysis completed', [
                'task_count' => count($items),
                'analyze_source_model' => $analyzeSourceModel,
                'analyze_source_reasoning_effort' => $analyzeSourceReasoningEffort,
            ]);
        } catch (\Throwable $exception) {
            $inputSource->update([
                'analysis_status' => 'failed',
                'last_analysis_error' => $exception->getMessage(),
            ]);
            $this->log($inputSource, 'error', 'Input source analysis failed', [
                'error' => $exception->getMessage(),
                'analyze_source_model' => $analyzeSourceModel,
                'analyze_source_reasoning_effort' => $analyzeSourceReasoningEffort,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function analysisResultTasks(array $items): array
    {
        return Collection::make($items)
            ->map(static function (array $item): array {
                return Arr::except($item, ['questions']);
            })
            ->values()
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(InputSource $inputSource, string $level, string $message, array $context = []): void
    {
        TaskRunLog::create([
            'input_source_id' => $inputSource->id,
            'level' => $level,
            'message' => $message,
            'context' => array_merge([
                'agent' => is_string($inputSource->agent_driver) && trim($inputSource->agent_driver) !== ''
                    ? trim($inputSource->agent_driver)
                    : (string) config('automation.agent.driver', config('automation.coding_agent.driver', 'codex')),
                'input_source_id' => $inputSource->id,
                'input_source_title' => $inputSource->title,
                'analysis_status' => $inputSource->analysis_status,
            ], $context),
        ]);
    }

    private function resolveAssignee(?string $githubUsername): ?User
    {
        if (! $githubUsername) {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(github_username) = ?', [mb_strtolower($githubUsername)])
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeExtractedItems(array $items): array
    {
        return Collection::make($items)
            ->filter(static fn (mixed $item) => is_array($item))
            ->values()
            ->toArray();
    }

    /**
     * @param  array<int, string>  $questions
     * @return array<int, string>
     */
    private function normalizeQuestions(array $questions): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map('trim', $questions),
                    static fn (string $question) => $question !== '',
                ),
            ),
        );
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = date_create_from_format('Y-m-d', $value);

        if ($normalized === false) {
            return null;
        }

        return $normalized->format('Y-m-d');
    }

    private function normalizePriority(string $priority): string
    {
        if (
            in_array($priority, [
                Task::PRIORITY_LOW,
                Task::PRIORITY_MEDIUM,
                Task::PRIORITY_HIGH,
                Task::PRIORITY_URGENT,
            ], true)
        ) {
            return $priority;
        }

        return Task::PRIORITY_MEDIUM;
    }

    private function resolveProjectId(mixed $projectId, array $validProjectIds): ?int
    {
        if (! is_numeric((string) $projectId)) {
            return null;
        }

        $id = (int) $projectId;

        return in_array($id, $validProjectIds, true)
            ? $id
            : null;
    }
}
