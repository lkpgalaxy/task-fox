<?php

namespace App\Services\CodingAgents;

use App\Contracts\CodingAgent;
use App\DataTransferObjects\CodingAgentInvocation;
use App\DataTransferObjects\CodingAgentResult;
use App\Models\InputSource;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\SystemSettingsResolver;
use Illuminate\Support\Arr;
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
        private ?CodexJsonEventParser $jsonEventParser = null,
    ) {}

    public function run(Task $task, TaskRun $run): CodingAgentResult
    {
        return $this->executeJsonCommand(
            command: $this->buildCommand(
                $task,
                $run,
                $this->buildTaskPrompt($task, $run),
                $this->resolveRunSetting($run, 'implement_model'),
                $this->resolveRunSetting($run, 'implement_reasoning_effort'),
            ),
            repositoryPath: $this->resolveWorkspacePath($run),
            run: $run,
            successMessage: 'Coding agent command completed.',
            failureMessage: 'Coding agent command failed.',
            model: $this->resolveRunSetting($run, 'implement_model'),
            reasoningEffort: $this->resolveRunSetting($run, 'implement_reasoning_effort'),
            resumeCommandFactory: fn (string $sessionId): string => $this->buildResumeCommandString($sessionId, true),
        );
    }

    public function plan(Task $task, TaskRun $run): CodingAgentResult
    {
        $result = $this->executeJsonCommand(
            command: $this->buildPlanningCommand(
                $run,
                $this->buildPlanningPrompt($task, $run),
                $this->resolveRunSetting($run, 'plan_model'),
                $this->resolveRunSetting($run, 'plan_reasoning_effort'),
            ),
            repositoryPath: $this->resolveWorkspacePath($run),
            run: $run,
            successMessage: 'Coding agent planning command completed.',
            failureMessage: 'Coding agent planning command failed.',
            model: $this->resolveRunSetting($run, 'plan_model'),
            reasoningEffort: $this->resolveRunSetting($run, 'plan_reasoning_effort'),
            resumeCommandFactory: fn (string $sessionId): string => $this->buildResumeCommandString($sessionId),
        );

        if (! $result->successful) {
            return $result;
        }

        $plan = $this->extractProposedPlan($this->primaryAgentOutput($result));

        if ($plan === '') {
            return new CodingAgentResult(
                successful: false,
                messages: $result->messages,
                error: 'Coding agent did not return an implementation plan.',
                invocation: $result->invocation,
            );
        }

        return new CodingAgentResult(
            successful: true,
            messages: $result->messages,
            payload: ['plan' => $plan],
            invocation: $result->invocation,
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
            $result = $this->executeJsonCommand(
                command: $this->buildReviewCommand(
                    $task,
                    $run,
                    $this->buildReviewPrompt($task, $run, $attempt),
                    $outputPath,
                    $this->resolveRunSetting($run, 'review_model'),
                    $this->resolveRunSetting($run, 'review_reasoning_effort'),
                ),
                repositoryPath: $repositoryPath,
                run: $run,
                successMessage: 'Coding agent review command completed.',
                failureMessage: 'Coding agent review command failed.',
                model: $this->resolveRunSetting($run, 'review_model'),
                reasoningEffort: $this->resolveRunSetting($run, 'review_reasoning_effort'),
                resumeCommandFactory: fn (string $sessionId): string => $this->buildResumeCommandString($sessionId),
            );
            $rawOutput = trim((string) @file_get_contents($outputPath));
            $reviewText = $rawOutput !== '' ? $rawOutput : $this->primaryAgentOutput($result);

            if (! $result->successful) {
                return $result;
            }

            if ($reviewText === '') {
                return new CodingAgentResult(
                    successful: false,
                    messages: $result->messages,
                    error: 'Coding agent review returned no output.',
                    payload: [
                        'review_text' => $reviewText,
                    ],
                    invocation: $result->invocation,
                );
            }

            $payload = [
                'review_text' => $reviewText,
            ];

            if (! $this->reviewTextHasFindings($run, $reviewText)) {
                return new CodingAgentResult(
                    successful: true,
                    messages: $result->messages,
                    payload: $payload,
                    invocation: $result->invocation,
                );
            }

            return new CodingAgentResult(
                successful: false,
                messages: $result->messages,
                error: $reviewText,
                payload: $payload,
                invocation: $result->invocation,
            );
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    public function resumeImplementation(Task $task, TaskRun $run, string $feedback, int $attempt): CodingAgentResult
    {
        $sessionId = $run->phaseSessions()
            ->where('phase', 'implement')
            ->value('session_id');

        if (! is_string($sessionId) || trim($sessionId) === '') {
            return new CodingAgentResult(
                successful: false,
                error: 'No persisted implementation session is available to resume.',
            );
        }

        return $this->executeJsonCommand(
            command: $this->buildResumeCommand(
                $sessionId,
                $this->buildImplementationRetryPrompt($task, $run, $feedback, $attempt),
                $this->resolveRunSetting($run, 'implement_model'),
                $this->resolveRunSetting($run, 'implement_reasoning_effort'),
                true,
            ),
            repositoryPath: $this->resolveWorkspacePath($run),
            run: $run,
            successMessage: 'Coding agent implementation resume completed.',
            failureMessage: 'Coding agent implementation resume failed.',
            model: $this->resolveRunSetting($run, 'implement_model'),
            reasoningEffort: $this->resolveRunSetting($run, 'implement_reasoning_effort'),
            resumeCommandFactory: fn (string $persistedSessionId): string => $this->buildResumeCommandString($persistedSessionId, true),
        );
    }

    public function captureScreenshot(Task $task, TaskRun $run): CodingAgentResult
    {
        return $this->executeJsonCommand(
            command: $this->buildCommand(
                $task,
                $run,
                $this->buildScreenshotPrompt($task, $run),
                $this->resolveRunSetting($run, 'implement_model'),
                $this->resolveRunSetting($run, 'implement_reasoning_effort'),
            ),
            repositoryPath: $this->resolveWorkspacePath($run),
            run: $run,
            successMessage: 'Screenshot verification command completed.',
            failureMessage: 'Screenshot verification command failed.',
            model: $this->resolveRunSetting($run, 'implement_model'),
            reasoningEffort: $this->resolveRunSetting($run, 'implement_reasoning_effort'),
            resumeCommandFactory: fn (string $sessionId): string => $this->buildResumeCommandString($sessionId, true),
        );
    }

    public function smokeTestUrl(Task $task, TaskRun $run): CodingAgentResult
    {
        return $this->executeJsonCommand(
            command: $this->buildCommand(
                $task,
                $run,
                $this->buildUrlSmokeTestPrompt($task),
                $this->resolveRunSetting($run, 'implement_model'),
                $this->resolveRunSetting($run, 'implement_reasoning_effort'),
            ),
            repositoryPath: $this->resolveWorkspacePath($run),
            run: $run,
            successMessage: 'URL smoke test command completed.',
            failureMessage: 'URL smoke test command failed.',
            model: $this->resolveRunSetting($run, 'implement_model'),
            reasoningEffort: $this->resolveRunSetting($run, 'implement_reasoning_effort'),
            resumeCommandFactory: fn (string $sessionId): string => $this->buildResumeCommandString($sessionId, true),
        );
    }

    private function reviewTextHasFindings(TaskRun $run, string $output): bool
    {
        $repositoryPath = $this->resolveWorkspacePath($run);
        $command = $this->buildReviewClassificationCommand(
            $run,
            $this->buildReviewClassificationPrompt($output),
            $this->resolveRunSetting($run, 'review_model'),
            $this->resolveRunSetting($run, 'review_reasoning_effort'),
        );

        $process = new Process($command, $repositoryPath);
        $process->setTimeout(null);
        $process->setEnv(array_merge(
            $this->context,
            [
                'TASK_RUN_ID' => (string) $run->id,
                'TASK_WORKSPACE_PATH' => $repositoryPath,
            ],
        ));
        $this->runInterruptibleProcess($process, $run);

        if ($this->stopRequested($run)) {
            return true;
        }

        if (! $process->isSuccessful()) {
            return true;
        }

        try {
            $payload = $this->decodeJsonPayload(trim((string) $process->getOutput()));
        } catch (JsonException) {
            return true;
        }

        return ! filter_var(Arr::get($payload, 'pass'), FILTER_VALIDATE_BOOL);
    }

    private function buildReviewClassificationPrompt(string $output): string
    {
        $reviewText = $this->limitPromptText($output, 12000);

        return <<<PROMPT
Classify this code review output.

Return only a valid JSON object with this exact shape:
{"pass": true}

Rules:
- pass=true only when the review clearly means the patch should pass review with no blocking or actionable findings left to fix.
- pass=false when the review contains any finding, requested fix, blocker, regression risk, or unresolved issue.
- If the review is ambiguous, return pass=false.
- Do not include markdown fences or any text outside the JSON object.

Review output:
{$reviewText}
PROMPT;
    }

    public function fixReviewFindings(Task $task, TaskRun $run, string $reviewFeedback, int $attempt): CodingAgentResult
    {
        return $this->resumeImplementation(
            $task,
            $run,
            $this->buildFixReviewPrompt($task, $run, $reviewFeedback, $attempt),
            $attempt,
        );
    }

    public function generateCommitMessage(Task $task, TaskRun $run): CodingAgentResult
    {
        $result = $this->executeJsonCommand(
            command: $this->buildCommand(
                $task,
                $run,
                $this->buildCommitMessagePrompt($task),
                $this->resolveRunSetting($run, 'commit_message_model'),
                $this->resolveRunSetting($run, 'commit_message_reasoning_effort'),
            ),
            repositoryPath: $this->resolveWorkspacePath($run),
            run: $run,
            successMessage: 'Coding agent commit message command completed.',
            failureMessage: 'Coding agent commit message command failed.',
            model: $this->resolveRunSetting($run, 'commit_message_model'),
            reasoningEffort: $this->resolveRunSetting($run, 'commit_message_reasoning_effort'),
            resumeCommandFactory: fn (string $sessionId): string => $this->buildResumeCommandString($sessionId, true),
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
            invocation: $result->invocation,
        );
    }

    private function buildTaskPrompt(Task $task, TaskRun $run): string
    {
        $plan = trim((string) $run->plan);
        $planBlock = $plan !== '' ? $plan : 'No stored implementation plan was recorded.';
        $previousFailure = trim((string) $run->last_error);
        $previousFailureBlock = $previousFailure !== ''
            ? "Previous verification failure:\n".$this->limitPromptText($previousFailure, 12000)
            : 'Previous verification failure: none recorded.';

        return <<<PROMPT
Implement task {$task->id}: {$task->title}

Description:
{$task->description}

Stored implementation plan:
{$planBlock}

{$previousFailureBlock}

Plan-following instructions:
- Treat the task title, description, stored plan, previous failure, tests, screenshot verification, and code review as the implementation contract.
- Pause and fail only if the stored plan is impossible to execute or contradicts the current task description.
- Inspect the relevant Laravel/Inertia code, existing tests, DESIGN.md for UI work, and version-specific docs before planning code changes.
- Add or update Pest feature/unit tests for changed behavior.
- For frontend behavior, add backend assertions where possible and run TypeScript/lint checks for React/Inertia changes.
- Do not start the application or dev server for screenshots; screenshot verification runs in a separate workflow step.
- If a previous verification failure is recorded, diagnose that failure first and make the smallest code, test, config, or migration fix needed before continuing.
- Run targeted tests first, then broader verification: vendor/bin/pint --dirty --format agent if PHP changed, npm run types:check and npm run lint:check if frontend changed, and php artisan test --compact for the final Laravel pass.
- If tests fail because the database schema is stale or missing tables, inspect the test database configuration and run the appropriate Laravel migration or test database setup command before changing unrelated code.
- Fix failing tests instead of ignoring them.
- Final response must include the tests run and whether they passed.
PROMPT;
    }

    private function buildScreenshotPrompt(Task $task, TaskRun $run): string
    {
        $projectUrl = trim((string) $task->project?->url);
        $screenshotPath = $run->screenshotPath();

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

    private function buildUrlSmokeTestPrompt(Task $task): string
    {
        $projectUrl = trim((string) $task->project?->url);

        return <<<PROMPT
Run a Playwright MCP smoke test for task {$task->id}: {$task->title}

Project URL:
{$projectUrl}

Instructions:
1. Use Playwright browser tooling to open only the implemented surface for this task, starting from the configured project URL.
2. Do not crawl the whole application or test unrelated routes, pages, settings, or workflows.
3. If the configured URL is not the implemented surface, navigate only to the most relevant page under that URL for this task.
4. Wait for the implemented page or UI state to finish rendering.
5. Check browser console errors, page errors, failed document requests, and obvious framework error screens for that implemented surface only.
6. Do not start the application server or Vite dev server; use the configured project URL.
7. If the implemented surface is unreachable, the wrong application is served, the page shows a backend exception, or browser errors indicate the implemented surface is broken, return a clear failure reason.
8. Final response must state whether the implementation smoke test passed and mention the URL or page inspected.
PROMPT;
    }

    private function buildPlanningPrompt(Task $task, TaskRun $run): string
    {
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

Project/workspace context:
- Workspace path: {$workspacePath}
- Base branch: {$baseBranch}

Return only the final <proposed_plan> block when planning is complete.
PROMPT;
    }

    private function buildFixReviewPrompt(Task $task, TaskRun $run, string $reviewFeedback, int $attempt): string
    {
        $plan = trim((string) $run->plan);
        $planBlock = $plan !== '' ? $this->limitPromptText($plan, 8000) : 'No stored implementation plan was recorded.';
        $baseBranch = $run->base_branch ?: 'main';
        $reviewFeedback = $this->limitPromptText($reviewFeedback, 12000);

        return <<<PROMPT
Fix the review findings for task {$task->id}: {$task->title}

Task description:
{$task->description}

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

    private function buildImplementationRetryPrompt(Task $task, TaskRun $run, string $feedback, int $attempt): string
    {
        $feedback = $this->limitPromptText($feedback, 12000);

        return <<<PROMPT
Continue implementation for task {$task->id}: {$task->title}

Retry attempt: {$attempt}

Feedback to address:
{$feedback}

Instructions:
- Resume the existing implementation session instead of restarting from scratch.
- Make the smallest correct fix that addresses the feedback.
- Preserve unrelated work.
- Do not commit, push, or create a pull request.
- Run only the verification needed to confirm the fix before responding.
PROMPT;
    }

    private function buildReviewPrompt(Task $task, TaskRun $run, int $attempt): string
    {
        $baseBranch = $run->base_branch ?: 'main';

        return <<<PROMPT
Review the current code changes for task {$task->id}: {$task->title}

Task description:
{$task->description}

Base branch:
{$baseBranch}

Review attempt:
{$attempt}

Instructions:
- Review the current diff against the base branch and any related changed tests.
- Do not edit files.
- Focus on bugs, regressions, missing tests, and correctness issues.
- If the patch is ready with no actionable findings, reply with a short pass statement.
- Otherwise return concise actionable findings with enough detail to fix them.
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

            return new CodingAgentResult(
                successful: false,
                messages: [],
                error: $message,
                invocation: new CodingAgentInvocation(command: $command),
            );
        }

        try {
            $payload = $this->decodeJsonPayload($output);
        } catch (JsonException $exception) {
            return new CodingAgentResult(
                successful: false,
                messages: $output !== '' ? ["Output: {$output}"] : [],
                error: 'Coding agent returned invalid task JSON: '.$exception->getMessage(),
                invocation: new CodingAgentInvocation(command: $command),
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
            invocation: new CodingAgentInvocation(command: $command),
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
            '--json',
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
            '--json',
            ...$this->modelArguments($model),
            ...$this->reasoningEffortArguments($reasoningEffort),
            '--sandbox',
            'read-only',
            '-C',
            $repositoryPath,
            $prompt,
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  callable(string): string|null  $resumeCommandFactory
     */
    private function executeJsonCommand(
        array $command,
        string $repositoryPath,
        TaskRun $run,
        string $successMessage,
        string $failureMessage,
        ?string $model = null,
        ?string $reasoningEffort = null,
        ?callable $resumeCommandFactory = null,
    ): CodingAgentResult {
        $process = new Process($command, $repositoryPath);
        $process->setTimeout(null);
        $process->setEnv(array_merge(
            $this->context,
            [
                'TASK_ID' => (string) $run->task_id,
                'TASK_RUN_ID' => (string) $run->id,
                'TASK_WORKSPACE_PATH' => $repositoryPath,
            ],
        ));
        $this->runInterruptibleProcess($process, $run);

        $output = trim((string) $process->getOutput());
        $errorOutput = trim((string) $process->getErrorOutput());

        if ($this->stopRequested($run)) {
            return $this->stopResult($run, $command);
        }

        $summary = $this->jsonEventParser()->parse($output);
        $agentOutput = $summary->messages !== [] ? implode("\n\n", $summary->messages) : $output;
        $invocation = new CodingAgentInvocation(
            command: $command,
            sessionId: $summary->sessionId,
            resumeCommand: $summary->sessionId !== null && $resumeCommandFactory !== null
                ? $resumeCommandFactory($summary->sessionId)
                : null,
            model: $summary->model ?? $model,
            reasoningEffort: $reasoningEffort,
            usage: $summary->usage(),
        );

        if (! $process->isSuccessful()) {
            $message = $errorOutput !== '' ? $errorOutput : ($agentOutput !== '' ? $agentOutput : $failureMessage);

            return new CodingAgentResult(
                successful: false,
                messages: $this->commandMessages($successMessage, $agentOutput, $errorOutput),
                error: $message,
                invocation: $invocation,
            );
        }

        return new CodingAgentResult(
            successful: true,
            messages: $this->commandMessages($successMessage, $agentOutput, $errorOutput),
            invocation: $invocation,
        );
    }

    private function runInterruptibleProcess(Process $process, TaskRun $run): void
    {
        $process->start();

        while ($process->isRunning()) {
            if ($this->stopRequested($run)) {
                $process->stop(1);

                break;
            }

            usleep(250000);
        }

        $process->wait();
    }

    private function stopRequested(TaskRun $run): bool
    {
        $freshRun = TaskRun::query()->find($run->id);

        return $freshRun?->stopRequested() ?? false;
    }

    /**
     * @param  list<string>  $command
     */
    private function stopResult(TaskRun $run, array $command): CodingAgentResult
    {
        $freshRun = TaskRun::query()->find($run->id);
        $message = $freshRun?->stopRequestMessage() ?? 'Task run stop requested.';

        return new CodingAgentResult(
            successful: false,
            error: $message,
            invocation: new CodingAgentInvocation(command: $command),
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

    private function buildReviewClassificationCommand(TaskRun $run, string $prompt, ?string $model = null, ?string $reasoningEffort = null): array
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

    private function buildReviewCommand(Task $task, TaskRun $run, string $prompt, string $outputPath, ?string $model = null, ?string $reasoningEffort = null): array
    {
        $repositoryPath = $this->resolveWorkspacePath($run);

        return [
            $this->codexExecutable(),
            'exec',
            '--json',
            ...$this->modelArguments($model),
            ...$this->reasoningEffortArguments($reasoningEffort),
            '--sandbox',
            'read-only',
            '-C',
            $repositoryPath,
            '--output-last-message',
            $outputPath,
            $prompt,
        ];
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function buildResumeCommand(
        string $sessionId,
        string $prompt,
        ?string $model = null,
        ?string $reasoningEffort = null,
        bool $bypassApprovals = false,
    ): array {
        return [
            $this->codexExecutable(),
            'exec',
            'resume',
            $sessionId,
            '--json',
            ...$this->modelArguments($model),
            ...$this->reasoningEffortArguments($reasoningEffort),
            ...($bypassApprovals ? ['--dangerously-bypass-approvals-and-sandbox'] : []),
            $prompt,
        ];
    }

    private function buildResumeCommandString(string $sessionId, bool $bypassApprovals = false): string
    {
        $command = ['codex', 'exec', 'resume', $sessionId, '--json'];

        if ($bypassApprovals) {
            $command[] = '--dangerously-bypass-approvals-and-sandbox';
        }

        return implode(' ', $command);
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

    private function jsonEventParser(): CodexJsonEventParser
    {
        return $this->jsonEventParser ??= app(CodexJsonEventParser::class);
    }

    /**
     * @return list<string>
     */
    private function commandMessages(string $successMessage, string $agentOutput, string $errorOutput): array
    {
        return array_values(array_filter([
            $successMessage,
            $agentOutput !== '' ? "Output: {$agentOutput}" : null,
            $errorOutput !== '' ? "STDERR: {$errorOutput}" : null,
        ], static fn (?string $message): bool => $message !== null));
    }

    private function primaryAgentOutput(CodingAgentResult $result): string
    {
        foreach ($result->messages as $message) {
            if (str_starts_with($message, 'Output: ')) {
                return trim(substr($message, 8));
            }
        }

        return '';
    }
}
