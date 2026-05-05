<?php

namespace App\Services\Extraction;

use App\Contracts\Agent;
use App\Contracts\TaskExtractor;
use App\Models\InputSource;
use App\Models\Task;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AgentTaskExtractor implements TaskExtractor
{
    public function __construct(private readonly Agent $agent) {}

    /**
     * @return array<int, array{
     *     title: string,
     *     description: string,
     *     project_id: int|null,
     *     assignee_github_username: string|null,
     *     priority: string|null,
     *     deadline: string|null,
     *     questions: array<int, string>,
     * }>
     */
    public function extract(InputSource $inputSource, array $projectSummaries = []): array
    {
        $result = $this->agent->analyzeInputSource($inputSource, $projectSummaries);

        if (! $result->successful) {
            throw new Exception($result->error ?: 'Agent could not analyze the input source.');
        }

        $tasks = Arr::get($result->payload, 'tasks', []);

        if (! is_array($tasks)) {
            return [];
        }

        return Collection::make($tasks)
            ->filter(static fn (mixed $task): bool => is_array($task))
            ->map(fn (array $task): array => $this->normalizeTask($task))
            ->filter(static fn (array $task): bool => $task['title'] !== '' || $task['description'] !== '')
            ->map(static function (array $task): array {
                if ($task['title'] === '') {
                    $task['title'] = Str::of($task['description'])->limit(80)->toString();
                }

                if ($task['description'] === '') {
                    $task['description'] = $task['title'];
                }

                return $task;
            })
            ->values()
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{
     *     title: string,
     *     description: string,
     *     assignee_github_username: string|null,
     *     priority: string|null,
     *     deadline: string|null,
     *     questions: array<int, string>,
     * }
     */
    private function normalizeTask(array $task): array
    {
        return [
            'title' => $this->normalizeString(Arr::get($task, 'title', '')),
            'description' => $this->normalizeString(Arr::get($task, 'description', '')),
            'project_id' => $this->normalizeProjectId(Arr::get($task, 'project_id')),
            'assignee_github_username' => $this->normalizeAssignee(Arr::get($task, 'assignee_github_username')),
            'priority' => $this->normalizePriority(Arr::get($task, 'priority')),
            'deadline' => $this->normalizeDeadline(Arr::get($task, 'deadline')),
            'questions' => $this->normalizeQuestions((array) Arr::get($task, 'questions', [])),
        ];
    }

    private function normalizeProjectId(mixed $projectId): ?int
    {
        if (is_int($projectId)) {
            return $projectId;
        }

        if (is_numeric((string) $projectId)) {
            return (int) $projectId;
        }

        return null;
    }

    private function normalizeAssignee(mixed $assignee): ?string
    {
        $assignee = $this->normalizeString($assignee);

        if ($assignee === '') {
            return null;
        }

        return ltrim($assignee, '@');
    }

    private function normalizePriority(mixed $priority): ?string
    {
        $priority = Str::lower($this->normalizeString($priority));

        if (in_array($priority, [
            Task::PRIORITY_LOW,
            Task::PRIORITY_MEDIUM,
            Task::PRIORITY_HIGH,
            Task::PRIORITY_URGENT,
        ], true)) {
            return $priority;
        }

        return null;
    }

    private function normalizeDeadline(mixed $deadline): ?string
    {
        $deadline = $this->normalizeString($deadline);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) !== 1) {
            return null;
        }

        return $deadline;
    }

    /**
     * @param  array<int, mixed>  $questions
     * @return array<int, string>
     */
    private function normalizeQuestions(array $questions): array
    {
        return Collection::make($questions)
            ->map(static fn (mixed $question): string => self::stringFromMixed($question))
            ->filter(static fn (string $question): bool => $question !== '')
            ->unique()
            ->values()
            ->toArray();
    }

    private function normalizeString(mixed $value): string
    {
        return self::stringFromMixed($value);
    }

    private static function stringFromMixed(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            $flattened = Arr::flatten($value);

            return trim(implode(' ', array_filter(
                array_map(
                    static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
                    $flattened,
                ),
                static fn (string $item): bool => $item !== '',
            )));
        }

        return '';
    }
}
