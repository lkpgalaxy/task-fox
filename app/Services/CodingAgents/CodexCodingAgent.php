<?php

namespace App\Services\CodingAgents;

use App\Contracts\CodingAgent;
use App\DataTransferObjects\CodingAgentResult;
use App\Models\InputSource;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Symfony\Component\Process\Process;

class CodexCodingAgent implements CodingAgent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private readonly array $context = [],
    ) {}

    public function run(Task $task, TaskRun $run): CodingAgentResult
    {
        if ($this->acceptanceCriteria($task)->isEmpty()) {
            return new CodingAgentResult(
                successful: false,
                messages: [],
                error: 'Acceptance criteria are required before implementation.',
            );
        }

        $prompt = $this->buildTaskPrompt($task);

        $command = $this->buildCommand($task, $run, $prompt);
        $repositoryPath = $this->resolveWorkspacePath($run);

        $process = new Process($command, $repositoryPath);
        $process->setTimeout(null);
        $process->setEnv(array_merge(
            $this->context,
            [
                'TASK_ID' => (string) $task->id,
                'TASK_RUN_ID' => (string) $run->id,
                'TASK_WORKSPACE_PATH' => $repositoryPath,
            ],
        ));
        $process->run();

        $output = trim((string) $process->getOutput());
        $errorOutput = trim((string) $process->getErrorOutput());

        if (! $process->isSuccessful()) {
            $message = $errorOutput !== '' ? $errorOutput : 'Coding agent command failed.';

            return new CodingAgentResult(successful: false, messages: [], error: $message);
        }

        $messages = array_values(
            array_filter([
                'Coding agent command completed.',
                $output !== '' ? "Output: {$output}" : null,
                $errorOutput !== '' ? "STDERR: {$errorOutput}" : null,
            ], static fn (?string $message) => $message !== null),
        );

        return new CodingAgentResult(
            successful: true,
            messages: $messages,
        );
    }

    public function reviewChanges(Task $task, TaskRun $run, int $attempt): CodingAgentResult
    {
        return $this->executeTaskCommand(
            $task,
            $run,
            $this->buildReviewPrompt($task, $run, $attempt),
            'Coding agent review command completed.',
        );
    }

    public function generateCommitMessage(Task $task, TaskRun $run): CodingAgentResult
    {
        $result = $this->executeTaskCommand(
            $task,
            $run,
            $this->buildCommitMessagePrompt($task),
            'Coding agent commit message command completed.',
        );

        if (! $result->successful) {
            return $result;
        }

        $subject = $this->extractCommitSubject($result->messages);

        if ($subject === '') {
            return new CodingAgentResult(
                successful: false,
                messages: $result->messages,
                error: 'Coding agent did not return a commit message.',
            );
        }

        return new CodingAgentResult(
            successful: true,
            messages: $result->messages,
            payload: ['message' => $subject],
        );
    }

    private function buildTaskPrompt(Task $task): string
    {
        $criteria = $this->acceptanceCriteria($task);

        $criteriaList = $criteria->isEmpty()
            ? '- No acceptance criteria were provided.'
            : $criteria
                ->map(static function (array $criterion, int $index): string {
                    $status = $criterion['checked'] ? '[x]' : '[ ]';

                    return ($index + 1).". {$status} {$criterion['body']}";
                })
                ->join("\n");

        return <<<PROMPT
Implement task {$task->id}: {$task->title}

Description:
{$task->description}

Acceptance criteria:
{$criteriaList}

Acceptance-criteria-driven workflow:
1. Before implementation, extract and list every acceptance criterion from the task.
2. Treat [x] criteria as already verified and [ ] criteria as the remaining contract to satisfy.
3. Inspect the relevant Laravel/Inertia code, existing tests, DESIGN.md for UI work, and version-specific docs before planning code changes.
4. If any criterion is missing, unclear, or not testable, pause and ask for clarification before implementation.
5. Add or update Pest feature/unit tests so each acceptance criterion has direct coverage.
6. For frontend behavior, add backend assertions where possible and run TypeScript/lint checks for React/Inertia changes.
7. Run targeted tests first, then broader verification: vendor/bin/pint --dirty --format agent if PHP changed, npm run types:check and npm run lint:check if frontend changed, and php artisan test --compact for the final Laravel pass.
8. Fix failing tests instead of ignoring them.
9. Before finishing, explicitly mark every verified criterion as [x] in your final checklist.
10. Final response must include the acceptance-criteria checklist, tests run, and whether they passed.
PROMPT;
    }

    private function buildReviewPrompt(Task $task, TaskRun $run, int $attempt): string
    {
        $baseBranch = $run->base_branch ?: 'main';

        return <<<PROMPT
Review changes for task {$task->id}: {$task->title}

Base branch: {$baseBranch}
Review attempt: {$attempt}

Inspect all changes against the base branch. Verify the implementation is scoped to the task description and acceptance criteria, check for regressions or missing tests, fix any issues you find, and rerun relevant checks after fixes.

Report whether review passed. Exit successfully only when the reviewed changes are ready to commit.
PROMPT;
    }

    private function buildCommitMessagePrompt(Task $task): string
    {
        return <<<PROMPT
Inspect the final staged and unstaged diff for task {$task->id}: {$task->title}

Return exactly one Conventional Commit subject line that matches this repository's recent commit history. Do not include markdown, explanation, quotes, or a body.
PROMPT;
    }

    /**
     * @return Collection<int, array{body: string, checked: bool}>
     */
    private function acceptanceCriteria(Task $task): Collection
    {
        return collect($task->acceptance_criteria ?? [])
            ->map(static fn (array $criterion): array => [
                'body' => trim((string) Arr::get($criterion, 'body', '')),
                'checked' => (bool) Arr::get($criterion, 'checked', false),
            ])
            ->filter(static fn (array $criterion): bool => $criterion['body'] !== '')
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $projectSummaries
     */
    public function analyzeInputSource(InputSource $inputSource, array $projectSummaries): CodingAgentResult
    {
        $inputPayload = $this->buildInputSourcePayload($inputSource);

        $projectContext = $this->buildProjectContext($projectSummaries);

        $prompt = <<<PROMPT
{$projectContext}

Analyze this input source and extract implementation tasks.

Return ONLY a valid JSON object with this exact shape:
{
  "tasks": [
    {
      "title": "Short actionable task title",
      "description": "Detailed task description preserving relevant context",
      "project_id": null,
      "assignee_github_username": null,
      "priority": "medium",
      "deadline": null,
      "acceptance_criteria": [
        {"body": "Specific verifiable criterion", "checked": false}
      ],
      "questions": []
    }
  ]
}

Rules:
- Break the source into useful implementation subtasks, not one task per line.
- priority must be one of urgent, high, medium, low, or null.
- project_id can be null or an existing project id.
- deadline must be YYYY-MM-DD or null.
- assignee_github_username must omit @, or be null.
- acceptance_criteria must contain concrete verification steps.
- questions must contain unresolved product or technical questions.
- If a project is obvious from context, use its id; otherwise leave project_id null.
- Do not include markdown fences, commentary, or any text outside the JSON object.

{$inputPayload}
PROMPT;

        $repositoryPath = (string) base_path();
        $process = new Process($this->buildAnalyzeCommand($repositoryPath, $prompt), $repositoryPath !== '' ? $repositoryPath : null);
        $process->setTimeout(null);
        $process->setEnv($this->context);
        $process->run();

        $output = trim((string) $process->getOutput());
        $errorOutput = trim((string) $process->getErrorOutput());

        if (! $process->isSuccessful()) {
            $message = $errorOutput !== '' ? $errorOutput : 'Coding agent analysis command failed.';

            return new CodingAgentResult(successful: false, messages: [], error: $message);
        }

        try {
            $payload = $this->decodeJsonPayload($output);
        } catch (JsonException $exception) {
            return new CodingAgentResult(
                successful: false,
                messages: $output !== '' ? ["Output: {$output}"] : [],
                error: 'Coding agent returned invalid task JSON: '.$exception->getMessage(),
            );
        }

        return new CodingAgentResult(
            successful: true,
            messages: array_values(
                array_filter([
                    'Coding agent input analysis completed.',
                    $errorOutput !== '' ? "STDERR: {$errorOutput}" : null,
                ], static fn (?string $message) => $message !== null),
            ),
            payload: $payload,
        );
    }

    private function buildProjectContext(array $projectSummaries): string
    {
        if ($projectSummaries === []) {
            return 'Known projects: none available.';
        }

        $lines = array_map(
            static function (array $project): string {
                $id = (string) Arr::get($project, 'id', '');
                $name = (string) Arr::get($project, 'name', '');
                $workspacePath = (string) Arr::get($project, 'workspace_path', '');
                $url = (string) Arr::get($project, 'url', '');
                $databaseName = (string) Arr::get($project, 'database_name', '');
                $databaseUsername = (string) Arr::get($project, 'database_username', '');
                $baseBranch = (string) Arr::get($project, 'base_branch', 'main');
                $databasePassword = (Arr::get($project, 'has_database_password') ? 'set' : 'not set');
                $credentialPassword = (Arr::get($project, 'has_credential_password') ? 'set' : 'not set');
                $credentialUsername = (Arr::get($project, 'has_credential_username') ? 'set' : 'not set');

                return "Project {$id}: {$name}; workspace_path: {$workspacePath}; url: {$url}; database_name: {$databaseName}; database_username: {$databaseUsername}; base_branch: {$baseBranch}; database_password: {$databasePassword}; credential_username: {$credentialUsername}; credential_password: {$credentialPassword}.";
            },
            $projectSummaries,
        );

        return "Known projects:\n".implode("\n", array_filter($lines, static fn (string $line) => $line !== ''));
    }

    private function buildInputSourcePayload(InputSource $inputSource): string
    {
        if ($inputSource->hasStoredFile()) {
            $absolutePath = Storage::disk((string) $inputSource->file_disk)->path((string) $inputSource->file_path);
            $filename = $inputSource->filename ?: basename((string) $inputSource->file_path);
            $mimeType = $inputSource->mime_type ?: 'unknown';
            $fileSize = $inputSource->file_size !== null ? "{$inputSource->file_size} bytes" : 'unknown';

            return <<<PAYLOAD
Input source title: {$inputSource->title}
Input source kind: stored uploaded file
Filename: {$filename}
MIME type: {$mimeType}
File size: {$fileSize}
Stored absolute file path: {$absolutePath}

Read and analyze the stored file at the path above. If the file is a PDF, analyze the PDF directly from disk; do not assume extracted text is available in the input source body.
PAYLOAD;
        }

        return <<<PAYLOAD
Input source title: {$inputSource->title}
Input source kind: unavailable

No stored file metadata is available for this input source.
PAYLOAD;
    }

    private function buildCommand(Task $task, TaskRun $run, string $prompt): array
    {
        $repositoryPath = $this->resolveWorkspacePath($run);

        return [
            $this->codexExecutable(),
            'exec',
            '--dangerously-bypass-approvals-and-sandbox',
            '-C',
            $repositoryPath,
            $prompt,
        ];
    }

    private function executeTaskCommand(Task $task, TaskRun $run, string $prompt, string $successMessage): CodingAgentResult
    {
        $command = $this->buildCommand($task, $run, $prompt);
        $repositoryPath = $this->resolveWorkspacePath($run);

        $process = new Process($command, $repositoryPath);
        $process->setTimeout(null);
        $process->setEnv(array_merge(
            $this->context,
            [
                'TASK_ID' => (string) $task->id,
                'TASK_RUN_ID' => (string) $run->id,
                'TASK_WORKSPACE_PATH' => $repositoryPath,
            ],
        ));
        $process->run();

        $output = trim((string) $process->getOutput());
        $errorOutput = trim((string) $process->getErrorOutput());

        if (! $process->isSuccessful()) {
            $message = $errorOutput !== '' ? $errorOutput : 'Coding agent command failed.';

            return new CodingAgentResult(successful: false, messages: [], error: $message);
        }

        return new CodingAgentResult(
            successful: true,
            messages: array_values(
                array_filter([
                    $successMessage,
                    $output !== '' ? "Output: {$output}" : null,
                    $errorOutput !== '' ? "STDERR: {$errorOutput}" : null,
                ], static fn (?string $message) => $message !== null),
            ),
        );
    }

    /**
     * @param  list<string>  $messages
     */
    private function extractCommitSubject(array $messages): string
    {
        foreach ($messages as $message) {
            if (! str_starts_with($message, 'Output: ')) {
                continue;
            }

            $lines = preg_split('/\R/', substr($message, 8)) ?: [];
            $subject = trim((string) ($lines[0] ?? ''));

            return trim($subject, "\"'` \t\n\r\0\x0B");
        }

        return '';
    }

    private function buildAnalyzeCommand(string $repositoryPath, string $prompt): array
    {
        return [
            $this->codexExecutable(),
            'exec',
            '--dangerously-bypass-approvals-and-sandbox',
            '-C',
            $repositoryPath,
            $prompt,
        ];
    }

    private function codexExecutable(): string
    {
        $path = (string) Arr::get($this->context, 'PATH', '');

        foreach (array_filter(explode(PATH_SEPARATOR, $path)) as $directory) {
            $executable = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'codex';

            if (is_file($executable) && is_executable($executable)) {
                return $executable;
            }
        }

        return 'codex';
    }

    private function resolveWorkspacePath(TaskRun $run): string
    {
        if ($run->workspace_path !== null && $run->workspace_path !== '') {
            return $run->workspace_path;
        }

        return base_path();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeJsonPayload(string $output): array
    {
        $json = trim($output);

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches) === 1) {
            $json = trim($matches[1]);
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
