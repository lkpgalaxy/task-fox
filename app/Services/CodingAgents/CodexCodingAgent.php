<?php

namespace App\Services\CodingAgents;

use App\Contracts\CodingAgent;
use App\DataTransferObjects\CodingAgentResult;
use App\Models\InputSource;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\SystemSettingsResolver;
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
        private ?SystemSettingsResolver $settingsResolver = null,
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

        $prompt = $this->buildTaskPrompt($task, $run);

        $command = $this->buildCommand(
            $task,
            $run,
            $prompt,
            $this->resolveRunSetting($run, 'implement_model'),
            $this->resolveRunSetting($run, 'implement_reasoning_effort'),
        );
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

            return new CodingAgentResult(successful: false, messages: [], error: $message, context: $this->commandLogContext($command));
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
            context: $this->commandLogContext($command),
        );
    }

    public function plan(Task $task, TaskRun $run): CodingAgentResult
    {
        $prompt = $this->buildPlanningPrompt($task, $run);
        $repositoryPath = $this->resolveWorkspacePath($run);

        $command = $this->buildPlanningCommand(
            $run,
            $prompt,
            $this->resolveRunSetting($run, 'plan_model'),
            $this->resolveRunSetting($run, 'plan_reasoning_effort'),
        );

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
            $message = $errorOutput !== '' ? $errorOutput : 'Coding agent planning command failed.';

            return new CodingAgentResult(successful: false, messages: [], error: $message, context: $this->commandLogContext($command));
        }

        $plan = $this->extractProposedPlan($output);

        if ($plan === '') {
            return new CodingAgentResult(
                successful: false,
                messages: $output !== '' ? ["Output: {$output}"] : [],
                error: 'Coding agent did not return an implementation plan.',
                context: $this->commandLogContext($command),
            );
        }

        return new CodingAgentResult(
            successful: true,
            messages: array_values(
                array_filter([
                    'Coding agent planning command completed.',
                    $output !== '' ? "Output: {$output}" : null,
                    $errorOutput !== '' ? "STDERR: {$errorOutput}" : null,
                ], static fn (?string $message) => $message !== null),
            ),
            payload: ['plan' => $plan],
            context: $this->commandLogContext($command),
        );
    }

    public function reviewChanges(Task $task, TaskRun $run, int $attempt): CodingAgentResult
    {
        $repositoryPath = $this->resolveWorkspacePath($run);
        $outputPath = $this->makeTemporaryOutputPath('codex-review-');

        if ($outputPath === '') {
            return new CodingAgentResult(
                successful: false,
                error: 'Unable to create a temporary file for Codex review output.',
            );
        }

        try {
            $command = $this->buildReviewCommand(
                $task,
                $run,
                $outputPath,
                $this->resolveRunSetting($run, 'review_model'),
                $this->resolveRunSetting($run, 'review_reasoning_effort'),
            );

            $process = new Process(
                $command,
                $repositoryPath,
            );
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

            $stdout = trim((string) $process->getOutput());
            $stderr = trim((string) $process->getErrorOutput());
            $rawOutput = trim((string) @file_get_contents($outputPath));

            $messages = array_values(
                array_filter([
                    'Coding agent review command completed.',
                    $rawOutput !== '' ? "Output: {$rawOutput}" : null,
                    $stdout !== '' ? "STDOUT: {$stdout}" : null,
                    $stderr !== '' ? "STDERR: {$stderr}" : null,
                ], static fn (?string $message): bool => $message !== null),
            );

            if ($rawOutput === '') {
                return new CodingAgentResult(
                    successful: false,
                    messages: $messages,
                    error: 'Coding agent review returned no output.',
                    payload: [
                        'review_text' => $rawOutput,
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                    ],
                    context: $this->commandLogContext($command),
                );
            }

            $payload = [
                'review_text' => $rawOutput,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];

            if (! $this->reviewTextHasFindings($rawOutput)) {
                return new CodingAgentResult(
                    successful: true,
                    messages: $messages,
                    payload: $payload,
                    context: $this->commandLogContext($command),
                );
            }

            return new CodingAgentResult(
                successful: false,
                messages: $messages,
                error: $rawOutput,
                payload: $payload,
                context: $this->commandLogContext($command),
            );
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    public function captureScreenshot(Task $task, TaskRun $run): CodingAgentResult
    {
        return $this->executeTaskCommand(
            $task,
            $run,
            $this->buildScreenshotPrompt($task, $run),
            'Screenshot verification command completed.',
            $this->resolveRunSetting($run, 'implement_model'),
            $this->resolveRunSetting($run, 'implement_reasoning_effort'),
        );
    }

    private function reviewTextHasFindings(string $output): bool
    {
        $normalizedOutput = mb_strtolower(trim($output));

        if ($normalizedOutput === '') {
            return false;
        }

        foreach ([
            'no findings',
            'no issues found',
            'no actionable findings',
            'no discrete correctness issues',
            'patch is correct',
        ] as $passingPhrase) {
            if (str_contains($normalizedOutput, $passingPhrase)) {
                return false;
            }
        }

        return true;
    }

    public function fixReviewFindings(Task $task, TaskRun $run, string $reviewFeedback, int $attempt): CodingAgentResult
    {
        return $this->executeTaskCommand(
            $task,
            $run,
            $this->buildFixReviewPrompt($task, $run, $reviewFeedback, $attempt),
            'Coding agent review fix command completed.',
            $this->resolveRunSetting($run, 'implement_model'),
            $this->resolveRunSetting($run, 'implement_reasoning_effort'),
        );
    }

    public function generateCommitMessage(Task $task, TaskRun $run): CodingAgentResult
    {
        $result = $this->executeTaskCommand(
            $task,
            $run,
            $this->buildCommitMessagePrompt($task),
            'Coding agent commit message command completed.',
            $this->resolveRunSetting($run, 'commit_message_model'),
            $this->resolveRunSetting($run, 'commit_message_reasoning_effort'),
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

    private function buildTaskPrompt(Task $task, TaskRun $run): string
    {
        $criteria = $this->acceptanceCriteria($task);
        $plan = trim((string) $run->plan);
        $planBlock = $plan !== '' ? $plan : 'No stored implementation plan was recorded.';
        $previousFailure = trim((string) $run->last_error);
        $previousFailureBlock = $previousFailure !== ''
            ? "Previous verification failure:\n".$this->limitPromptText($previousFailure, 12000)
            : 'Previous verification failure: none recorded.';

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

Stored implementation plan:
{$planBlock}

{$previousFailureBlock}

Plan-following instructions:
- Follow the stored implementation plan above as the implementation contract for this run.
- Pause and fail only if the stored plan is impossible to execute or contradicts the current task description or acceptance criteria.

Acceptance-criteria-driven workflow:
1. Before implementation, extract and list every acceptance criterion from the task.
2. Treat [x] criteria as already verified and [ ] criteria as the remaining contract to satisfy.
3. Inspect the relevant Laravel/Inertia code, existing tests, DESIGN.md for UI work, and version-specific docs before planning code changes.
4. If any criterion is missing, unclear, or not testable, pause and ask for clarification before implementation.
5. Add or update Pest feature/unit tests so each acceptance criterion has direct coverage.
6. For frontend behavior, add backend assertions where possible and run TypeScript/lint checks for React/Inertia changes.
7. Do not start the application or dev server for screenshots; screenshot verification runs in a separate workflow step.
8. If a previous verification failure is recorded, diagnose that failure first and make the smallest code, test, config, or migration fix needed before continuing.
9. Run targeted tests first, then broader verification: vendor/bin/pint --dirty --format agent if PHP changed, npm run types:check and npm run lint:check if frontend changed, and php artisan test --compact for the final Laravel pass.
10. If tests fail because the database schema is stale or missing tables, inspect the test database configuration and run the appropriate Laravel migration or test database setup command before changing unrelated code.
11. Fix failing tests instead of ignoring them.
12. Before finishing, explicitly mark every verified criterion as [x] in your final checklist.
13. Final response must include the acceptance-criteria checklist, tests run, and whether they passed.
PROMPT;
    }

    private function buildScreenshotPrompt(Task $task, TaskRun $run): string
    {
        $projectUrl = trim((string) $task->project?->url);
        $screenshotPath = storage_path("app/task-runs/{$run->id}/screenshots/implementation.png");

        return <<<PROMPT
Capture a verification screenshot for task {$task->id}: {$task->title}

Project URL:
{$projectUrl}

Screenshot path:
{$screenshotPath}

Instructions:
1. Use Playwright browser tooling to open the project URL or the most relevant page under it for this task.
2. Wait for the page to finish rendering.
3. Capture a screenshot of the implemented result and save it exactly at the screenshot path above.
4. Create the screenshot directory if it does not exist.
5. Do not start the application server or Vite dev server; use the configured project URL.
6. If the URL is unreachable or screenshot capture is impossible, return a clear failure reason.
7. Final response must include the screenshot path when captured.
PROMPT;
    }

    private function buildPlanningPrompt(Task $task, TaskRun $run): string
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

        $workspacePath = $this->resolveWorkspacePath($run);
        $baseBranch = $run->base_branch ?: 'main';

        return <<<PROMPT
You are running an automatic non-mutating planning pass before implementation.

Use these Plan Mode instructions:
- Work in Plan Mode until the final plan is complete.
- Explore and execute only non-mutating actions that improve the plan.
- Do not edit or write files, run formatters that rewrite files, apply migrations, run code generation, or otherwise implement the plan.
- Ground the plan in the repository by inspecting relevant files, configs, schemas, tests, DESIGN.md for UI work, and version-specific docs.
- Do not ask the user any questions or request clarification.
- If a detail is ambiguous or undiscoverable, choose the safest reasonable assumption and record it in the Assumptions section.
- Produce exactly one final implementation plan wrapped in <proposed_plan> and </proposed_plan>.
- The plan must be decision complete for another Codex implementation pass, concise by default, and include Summary, Key Changes, Test Plan, and Assumptions sections.

Task {$task->id}: {$task->title}

Description:
{$task->description}

Acceptance criteria:
{$criteriaList}

Project/workspace context:
- Workspace path: {$workspacePath}
- Base branch: {$baseBranch}

Return only the final <proposed_plan> block when planning is complete.
PROMPT;
    }

    private function buildFixReviewPrompt(Task $task, TaskRun $run, string $reviewFeedback, int $attempt): string
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

        $plan = trim((string) $run->plan);
        $planBlock = $plan !== '' ? $this->limitPromptText($plan, 8000) : 'No stored implementation plan was recorded.';
        $baseBranch = $run->base_branch ?: 'main';
        $reviewFeedback = $this->limitPromptText($reviewFeedback, 12000);

        return <<<PROMPT
Fix the review findings for task {$task->id}: {$task->title}

Task description:
{$task->description}

Acceptance criteria:
{$criteriaList}

Stored implementation plan:
{$planBlock}

Base branch: {$baseBranch}
Review attempt: {$attempt}

Review feedback:
{$reviewFeedback}

Instructions:
- Make the smallest correct fix that resolves the review feedback.
- Preserve unrelated changes.
- Do not commit, push, or create a pull request.
- After fixing the code, leave a final checklist of the addressed findings in your response.
PROMPT;
    }

    private function limitPromptText(string $text, int $limit): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit)."\n\n[truncated to keep the coding-agent command within OS argument limits]";
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
        $command = $this->buildAnalyzeCommand(
            $repositoryPath,
            $prompt,
            $this->resolveAnalyzeSourceModel(),
            $this->resolveAnalyzeSourceReasoningEffort(),
        );
        $process = new Process($command, $repositoryPath !== '' ? $repositoryPath : null);
        $process->setTimeout(null);
        $process->setEnv($this->context);
        $process->run();

        $output = trim((string) $process->getOutput());
        $errorOutput = trim((string) $process->getErrorOutput());

        if (! $process->isSuccessful()) {
            $message = $errorOutput !== '' ? $errorOutput : 'Coding agent analysis command failed.';

            return new CodingAgentResult(successful: false, messages: [], error: $message, context: $this->commandLogContext($command));
        }

        try {
            $payload = $this->decodeJsonPayload($output);
        } catch (JsonException $exception) {
            return new CodingAgentResult(
                successful: false,
                messages: $output !== '' ? ["Output: {$output}"] : [],
                error: 'Coding agent returned invalid task JSON: '.$exception->getMessage(),
                context: $this->commandLogContext($command),
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
            context: $this->commandLogContext($command),
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

    private function buildCommand(Task $task, TaskRun $run, string $prompt, ?string $model = null, ?string $reasoningEffort = null): array
    {
        $repositoryPath = $this->resolveWorkspacePath($run);

        return [
            $this->codexExecutable(),
            'exec',
            ...$this->modelArguments($model),
            ...$this->reasoningEffortArguments($reasoningEffort),
            '--dangerously-bypass-approvals-and-sandbox',
            '-C',
            $repositoryPath,
            $prompt,
        ];
    }

    private function buildPlanningCommand(TaskRun $run, string $prompt, ?string $model = null, ?string $reasoningEffort = null): array
    {
        $repositoryPath = $this->resolveWorkspacePath($run);

        return [
            $this->codexExecutable(),
            'exec',
            ...$this->modelArguments($model),
            ...$this->reasoningEffortArguments($reasoningEffort),
            '--sandbox',
            'read-only',
            '--ephemeral',
            '-C',
            $repositoryPath,
            $prompt,
        ];
    }

    private function executeTaskCommand(Task $task, TaskRun $run, string $prompt, string $successMessage, ?string $model = null, ?string $reasoningEffort = null): CodingAgentResult
    {
        $command = $this->buildCommand($task, $run, $prompt, $model, $reasoningEffort);
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

            return new CodingAgentResult(successful: false, messages: [], error: $message, context: $this->commandLogContext($command));
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
            context: $this->commandLogContext($command),
        );
    }

    /**
     * @param  list<string>  $command
     * @return array{command: list<string>}
     */
    private function commandLogContext(array $command): array
    {
        return [
            'command' => array_map(
                static fn (string $argument): string => str_contains($argument, "\n") || mb_strlen($argument) > 500
                    ? '[prompt omitted]'
                    : $argument,
                $command,
            ),
        ];
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

    private function extractProposedPlan(string $output): string
    {
        if (preg_match('/<proposed_plan>\s*(.*?)\s*<\/proposed_plan>/is', $output, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($output);
    }

    private function buildAnalyzeCommand(string $repositoryPath, string $prompt, ?string $model = null, ?string $reasoningEffort = null): array
    {
        return [
            $this->codexExecutable(),
            'exec',
            ...$this->modelArguments($model),
            ...$this->reasoningEffortArguments($reasoningEffort),
            '--dangerously-bypass-approvals-and-sandbox',
            '-C',
            $repositoryPath,
            $prompt,
        ];
    }

    private function buildReviewCommand(Task $task, TaskRun $run, string $outputPath, ?string $model = null, ?string $reasoningEffort = null): array
    {
        $repositoryPath = $this->resolveWorkspacePath($run);

        return [
            $this->codexExecutable(),
            'exec',
            ...$this->modelArguments($model),
            ...$this->reasoningEffortArguments($reasoningEffort),
            '-C',
            $repositoryPath,
            'review',
            '--base',
            $run->base_branch ?: 'main',
            '--title',
            "Task {$task->id}: {$task->title}",
            '--output-last-message',
            $outputPath,
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

    private function makeTemporaryOutputPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        return $path !== false ? $path : '';
    }

    private function settingsResolver(): SystemSettingsResolver
    {
        return $this->settingsResolver ??= app(SystemSettingsResolver::class);
    }

    private function resolveAnalyzeSourceModel(): ?string
    {
        return $this->settingsResolver()->analyzeSourceModel();
    }

    private function resolveAnalyzeSourceReasoningEffort(): ?string
    {
        return $this->settingsResolver()->analyzeSourceReasoningEffort();
    }

    private function resolveRunSetting(TaskRun $run, string $column): ?string
    {
        $value = $run->getAttribute($column);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function modelArguments(?string $model): array
    {
        $model = trim((string) $model);

        if ($model === '') {
            return [];
        }

        return ['--model', $model];
    }

    /**
     * @return list<string>
     */
    private function reasoningEffortArguments(?string $reasoningEffort): array
    {
        $reasoningEffort = trim((string) $reasoningEffort);

        if ($reasoningEffort === '') {
            return [];
        }

        return ['-c', 'model_reasoning_effort="'.$reasoningEffort.'"'];
    }
}
