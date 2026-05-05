<?php

namespace App\Jobs;

use App\Contracts\CodingAgent;
use App\Contracts\ExternalTaskProvider;
use App\Contracts\PullRequestProvider;
use App\DataTransferObjects\CodingAgentResult;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunLog;
use App\Models\User;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class RunApprovedTaskWithCodingAgentJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $taskRunId) {}

    public function handle(
        CodingAgent $codingAgent,
        ExternalTaskProvider $externalTaskProvider,
        PullRequestProvider $pullRequestProvider,
    ): void {
        $run = TaskRun::with(['task.assignee', 'task.reviewer', 'task.approvedByUser', 'task.externalTaskLink', 'task.project.defaultReviewer'])->find($this->taskRunId);

        if ($run === null || ! $run->task) {
            return;
        }

        $task = $run->task;
        $repositoryPath = $this->resolveExecutionPath($run);
        $branchName = $this->resolveBranchName($run, $task);
        $baseBranch = $this->resolveBaseBranch($run);

        try {
            $run->initializeWorkflowState($task);

            if (! $run->hasMatchingRequestHash($task)) {
                throw new Exception('Task request changed since this task run was created.');
            }

            $run->update([
                'status' => TaskRun::STATUS_PREPARING,
                'branch_name' => $branchName,
                'started_at' => now(),
                'last_error' => null,
            ]);

            $task->update(['status' => Task::STATUS_RUNNING]);

            $this->log($run, 'info', 'Task run started', [
                'task_id' => $task->id,
                'run_id' => $run->id,
            ]);

            if ($run->isCheckpointComplete(TaskRun::CHECKPOINT_REPOSITORY_PREPARED)) {
                $this->checkoutExistingBranch($repositoryPath, $branchName);
            }

            while ($checkpoint = $run->nextRunnableCheckpoint()) {
                match ($checkpoint) {
                    TaskRun::CHECKPOINT_REPOSITORY_PREPARED => $this->prepareRepository($run, $repositoryPath, $branchName, $baseBranch),
                    TaskRun::CHECKPOINT_PLANNED => $this->planImplementation($codingAgent, $task, $run),
                    TaskRun::CHECKPOINT_IMPLEMENTATION_VERIFIED => $this->verifyImplementation($codingAgent, $externalTaskProvider, $task, $run, $repositoryPath),
                    TaskRun::CHECKPOINT_SCREENSHOT_VERIFIED => $this->verifyScreenshot($codingAgent, $task, $run),
                    TaskRun::CHECKPOINT_CHANGES_REVIEWED => $this->reviewChanges($codingAgent, $task, $run, $repositoryPath),
                    TaskRun::CHECKPOINT_ACCEPTANCE_CRITERIA_VERIFIED => $this->verifyAcceptanceCriteria($task, $run),
                    TaskRun::CHECKPOINT_CHANGES_COMMITTED => $this->commitChanges($codingAgent, $task, $run, $repositoryPath, $branchName),
                    TaskRun::CHECKPOINT_PULL_REQUEST_CREATED => $this->createPullRequest($pullRequestProvider, $task, $run),
                    TaskRun::CHECKPOINT_REVIEW_REQUESTED => $this->requestReview($pullRequestProvider, $task, $run),
                    TaskRun::CHECKPOINT_EXTERNAL_TASK_UPDATED => $this->updateExternalTask($externalTaskProvider, $task, $run),
                    default => throw new Exception("Unknown task run checkpoint [{$checkpoint}]."),
                };
            }

            $this->markRunWaitingForMerge($task, $run);

            $this->log($run, 'info', 'Pull request created and waiting for merge', [
                'pull_request_url' => $run->pull_request_url,
            ]);
        } catch (Throwable $exception) {
            $checkpoint = $run->runningCheckpoint() ?? $run->nextRunnableCheckpoint();
            if ($checkpoint !== null) {
                $run->markCheckpointFailed($checkpoint, $exception->getMessage());
            }

            $task->update([
                'status' => Task::STATUS_FAILED,
            ]);

            $run->update([
                'status' => TaskRun::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            $this->log($run, 'error', 'Task run failed', [
                'error' => $exception->getMessage(),
            ]);

            if ($task->externalTaskLink) {
                $task->externalTaskLink->messages()->create([
                    'type' => 'attempt',
                    'payload' => ['run_id' => $run->id],
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                    'sent_at' => now(),
                ]);
            }
        } finally {
            DispatchNextTaskRunJob::dispatch();
        }
    }

    private function makeBranchName(Task $task): string
    {
        $slug = Str::slug((string) $task->title);

        if ($slug === '') {
            $slug = 'task';
        }

        return 'ai-task-'.(string) $task->id.'-'.Str::limit($slug, 40, '');
    }

    private function resolveBranchName(TaskRun $run, Task $task): string
    {
        $branchName = trim((string) $run->branch_name);

        if ($branchName === '' || $branchName === 'pending') {
            return $this->makeBranchName($task);
        }

        return $branchName;
    }

    private function prepareRepository(TaskRun $run, string $repositoryPath, string $branchName, string $baseBranch): void
    {
        $run->markCheckpointRunning(TaskRun::CHECKPOINT_REPOSITORY_PREPARED);
        $run->update(['status' => TaskRun::STATUS_PREPARING]);

        $this->assertRepositoryExists($repositoryPath);
        $this->resetBaseBranch($repositoryPath, $baseBranch);
        $this->createBranch($repositoryPath, $branchName, $baseBranch);

        $run->markCheckpointCompleted(TaskRun::CHECKPOINT_REPOSITORY_PREPARED);
    }

    private function planImplementation(CodingAgent $codingAgent, Task $task, TaskRun $run): void
    {
        $run->markCheckpointRunning(TaskRun::CHECKPOINT_PLANNED);
        $run->update(['status' => TaskRun::STATUS_PLANNING]);

        $this->log($run, 'info', 'Coding agent planning started', $this->agentLogContext($run, 'plan'));

        $agentResult = $codingAgent->plan($task, $run);
        $this->logAgentMessages($run, $agentResult);

        if (! $agentResult->successful) {
            throw new Exception((string) $agentResult->error ?: 'Coding agent planning failed.');
        }

        $plan = trim((string) ($agentResult->payload['plan'] ?? ''));

        if ($plan === '') {
            throw new Exception('Coding agent did not return an implementation plan.');
        }

        $run->update(['plan' => $plan]);
        $run->markCheckpointCompleted(TaskRun::CHECKPOINT_PLANNED);
    }

    private function verifyImplementation(
        CodingAgent $codingAgent,
        ExternalTaskProvider $externalTaskProvider,
        Task $task,
        TaskRun $run,
        string $repositoryPath,
    ): void {
        $maxAttempts = max(1, (int) config('automation.agent.retry_limit', 3));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $run->markCheckpointRunning(TaskRun::CHECKPOINT_IMPLEMENTATION_VERIFIED);
            $run->update([
                'status' => TaskRun::STATUS_IMPLEMENTING,
                'attempt_count' => $run->checkpointAttempts(TaskRun::CHECKPOINT_IMPLEMENTATION_VERIFIED),
            ]);

            $this->log($run, 'info', 'Coding agent invocation started', array_merge(
                $this->agentLogContext($run, 'implement'),
                ['attempt' => $attempt],
            ));

            $agentResult = $codingAgent->run($task, $run);
            $this->logAgentMessages($run, $agentResult);

            if (! $agentResult->successful) {
                throw new Exception((string) $agentResult->error ?: 'Coding agent execution failed.');
            }

            if ($this->runTests($repositoryPath, $run)) {
                $run->markCheckpointCompleted(TaskRun::CHECKPOINT_IMPLEMENTATION_VERIFIED);

                return;
            }

            if ($attempt >= $maxAttempts) {
                $testFailure = trim((string) $run->refresh()->last_error);

                throw new Exception($testFailure !== ''
                    ? "Tests failed after retry limit reached.\n\n{$testFailure}"
                    : 'Tests failed after retry limit reached.');
            }

            $this->log($run, 'warning', 'Tests failed; retrying', [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
            ]);

            if ($task->externalTaskLink) {
                $task->externalTaskLink->messages()->create([
                    'type' => 'attempt',
                    'payload' => ['run_id' => $run->id, 'attempt' => $attempt, 'outcome' => 'retrying'],
                    'status' => 'failed',
                    'error' => 'Tests failed, scheduling retry.',
                    'sent_at' => now(),
                ]);

                $externalTaskProvider->addComment($task->externalTaskLink, "Run {$run->id} failed tests; retrying ({$attempt}/{$maxAttempts}).");
            }

            $run->update(['status' => TaskRun::STATUS_PLANNING]);
        }
    }

    private function verifyAcceptanceCriteria(Task $task, TaskRun $run): void
    {
        $run->markCheckpointRunning(TaskRun::CHECKPOINT_ACCEPTANCE_CRITERIA_VERIFIED);

        $this->markAcceptanceCriteriaVerified($task, $run);

        $run->markCheckpointCompleted(TaskRun::CHECKPOINT_ACCEPTANCE_CRITERIA_VERIFIED);
    }

    private function verifyScreenshot(CodingAgent $codingAgent, Task $task, TaskRun $run): void
    {
        $run->markCheckpointRunning(TaskRun::CHECKPOINT_SCREENSHOT_VERIFIED);
        $run->update(['status' => TaskRun::STATUS_SCREENSHOTTING]);

        $projectUrl = trim((string) $task->project?->url);
        if ($projectUrl === '') {
            $this->log($run, 'info', 'Screenshot verification skipped', [
                'reason' => 'missing_project_url',
            ]);

            $run->markCheckpointSkipped(TaskRun::CHECKPOINT_SCREENSHOT_VERIFIED);

            return;
        }

        $screenshotPath = $this->screenshotPath($run);
        $screenshotDirectory = dirname($screenshotPath);

        if (! is_dir($screenshotDirectory) && ! mkdir($screenshotDirectory, 0755, true) && ! is_dir($screenshotDirectory)) {
            throw new Exception("Unable to create screenshot directory [{$screenshotDirectory}].");
        }

        $this->log($run, 'info', 'Screenshot verification started', [
            'project_url' => $projectUrl,
            'screenshot_path' => $screenshotPath,
        ]);

        $agentResult = $codingAgent->captureScreenshot($task, $run);
        $this->logAgentMessages($run, $agentResult);

        if (! $agentResult->successful) {
            throw new Exception((string) $agentResult->error ?: 'Screenshot verification failed.');
        }

        clearstatcache(true, $screenshotPath);

        if (! is_file($screenshotPath) || filesize($screenshotPath) === 0) {
            throw new Exception("Screenshot verification did not create [{$screenshotPath}].");
        }

        $this->log($run, 'info', 'Screenshot captured', [
            'screenshot_path' => $screenshotPath,
            'bytes' => filesize($screenshotPath),
        ]);

        $run->markCheckpointCompleted(TaskRun::CHECKPOINT_SCREENSHOT_VERIFIED);
    }

    private function resolvePullRequestReviewer(Task $task): ?User
    {
        if ($task->reviewer && $this->hasGithubUsername($task->reviewer)) {
            return $task->reviewer;
        }

        $defaultReviewer = $task->project?->defaultReviewer;

        if ($defaultReviewer && $this->hasGithubUsername($defaultReviewer)) {
            return $defaultReviewer;
        }

        return $task->assignee;
    }

    private function hasGithubUsername(User $user): bool
    {
        return $user->github_username !== null && $user->github_username !== '';
    }

    private function assertRepositoryExists(string $path): void
    {
        if ($path === '') {
            throw new Exception('Repository path is missing from configuration.');
        }

        $status = $this->runProcess(['git', 'rev-parse', '--is-inside-work-tree'], $path);
        if (! $status->isSuccessful()) {
            throw new Exception('Target path is not a git repository.');
        }
    }

    private function resetBaseBranch(string $path, string $baseBranch): void
    {
        $branch = $this->normalizeBaseBranch($baseBranch);
        $baseBranchCheck = $this->runProcess(['git', 'rev-parse', '--verify', $branch], $path);
        if (! $baseBranchCheck->isSuccessful()) {
            throw new Exception("Base branch {$branch} does not exist in repository.");
        }

        $result = $this->runProcess(['git', 'checkout', '-f', $branch], $path);
        if (! $result->isSuccessful()) {
            throw new Exception('Unable to checkout base branch: '.trim((string) $result->getErrorOutput()));
        }

        $result = $this->runProcess(['git', 'reset', '--hard'], $path);
        if (! $result->isSuccessful()) {
            throw new Exception('Unable to reset base branch: '.trim((string) $result->getErrorOutput()));
        }

        $result = $this->runProcess(['git', 'clean', '-fd'], $path);
        if (! $result->isSuccessful()) {
            throw new Exception('Unable to clean repository: '.trim((string) $result->getErrorOutput()));
        }

        $result = $this->runProcess(['git', 'pull', '--rebase', 'origin', $branch], $path);
        if (! $result->isSuccessful()) {
            throw new Exception('Unable to update base branch: '.trim((string) $result->getErrorOutput()));
        }
    }

    private function createBranch(string $path, string $branchName, string $baseBranch): void
    {
        $branch = $this->normalizeBaseBranch($baseBranch);
        $result = $this->runProcess(['git', 'checkout', $branch], $path);
        if (! $result->isSuccessful()) {
            throw new Exception('Unable to checkout base branch: '.trim((string) $result->getErrorOutput()));
        }

        $result = $this->runProcess(['git', 'checkout', '-B', $branchName], $path);

        if (! $result->isSuccessful()) {
            throw new Exception('Unable to create branch: '.trim((string) $result->getErrorOutput()));
        }
    }

    private function checkoutExistingBranch(string $path, string $branchName): void
    {
        $result = $this->runProcess(['git', 'checkout', $branchName], $path);
        if (! $result->isSuccessful()) {
            throw new Exception('Unable to checkout existing task branch: '.trim((string) $result->getErrorOutput()));
        }
    }

    private function runTests(string $path, TaskRun $run): bool
    {
        $command = (string) config('automation.tests.command', 'php artisan test --compact');
        $process = $this->runProcess($command, $path);
        $failureOutput = $this->formatTestFailure($command, $process);

        $run->update(['status' => TaskRun::STATUS_TESTING]);

        $this->log($run, 'info', 'Test command executed', [
            'command' => $command,
            'exit_code' => $process->getExitCode(),
            'stdout' => $this->limitProcessOutput($process->getOutput()),
            'stderr' => $this->limitProcessOutput($process->getErrorOutput()),
        ]);

        if ($process->isSuccessful()) {
            $run->update(['last_error' => null]);

            return true;
        }

        $run->update(['last_error' => $failureOutput]);

        return false;
    }

    private function formatTestFailure(string $command, Process $process): string
    {
        $sections = [
            "Verification command failed: {$command}",
            'Exit code: '.(string) $process->getExitCode(),
        ];

        $output = trim($process->getOutput());
        if ($output !== '') {
            $sections[] = "STDOUT:\n".$this->limitProcessOutput($output, 8000);
        }

        $errorOutput = trim($process->getErrorOutput());
        if ($errorOutput !== '') {
            $sections[] = "STDERR:\n".$this->limitProcessOutput($errorOutput, 8000);
        }

        return implode("\n\n", $sections);
    }

    private function limitProcessOutput(string $output, int $limit = 6000): string
    {
        $output = trim($output);

        if (Str::length($output) <= $limit) {
            return $output;
        }

        return Str::substr($output, 0, $limit)."\n\n[truncated]";
    }

    private function markAcceptanceCriteriaVerified(Task $task, TaskRun $run): void
    {
        $criteria = collect($task->refresh()->acceptance_criteria ?? [])
            ->map(fn (array $criterion): array => [
                'body' => (string) Arr::get($criterion, 'body', ''),
                'checked' => (bool) Arr::get($criterion, 'checked', false),
            ])
            ->filter(fn (array $criterion): bool => $criterion['body'] !== '')
            ->values();

        $uncheckedCount = $criteria
            ->filter(fn (array $criterion): bool => ! $criterion['checked'])
            ->count();

        if ($criteria->isEmpty()) {
            throw new Exception('Acceptance criteria are required before implementation.');
        }

        $task->forceFill([
            'acceptance_criteria' => $criteria
                ->map(fn (array $criterion): array => [
                    'body' => $criterion['body'],
                    'checked' => true,
                ])
                ->all(),
        ])->save();

        $this->log($run, 'info', 'Acceptance criteria verified', [
            'verified_count' => $criteria->count(),
            'newly_verified_count' => $uncheckedCount,
        ]);
    }

    private function screenshotPath(TaskRun $run): string
    {
        return storage_path("app/task-runs/{$run->id}/screenshots/implementation.png");
    }

    private function reviewChanges(CodingAgent $codingAgent, Task $task, TaskRun $run, string $repositoryPath): void
    {
        $maxReviewAttempts = max(1, (int) config('automation.agent.retry_limit', 3));

        for ($attempt = 1; $attempt <= $maxReviewAttempts; $attempt++) {
            $run->markCheckpointRunning(TaskRun::CHECKPOINT_CHANGES_REVIEWED);
            $run->update([
                'status' => TaskRun::STATUS_REVIEWING_CHANGES,
                'review_attempt_count' => $run->checkpointAttempts(TaskRun::CHECKPOINT_CHANGES_REVIEWED),
            ]);

            $this->log($run, 'info', 'Coding agent review started', array_merge(
                $this->agentLogContext($run, 'review'),
                ['attempt' => $attempt],
            ));

            $agentResult = $codingAgent->reviewChanges($task, $run, $attempt);
            $this->logAgentMessages($run, $agentResult);

            if ($agentResult->successful) {
                $this->log($run, 'info', 'Coding agent review passed', [
                    'attempt' => $attempt,
                ]);

                $run->markCheckpointCompleted(TaskRun::CHECKPOINT_CHANGES_REVIEWED);

                return;
            }

            if ($attempt >= $maxReviewAttempts) {
                $this->log($run, 'warning', 'Coding agent review failed after retry limit', [
                    'max_attempts' => $maxReviewAttempts,
                    'error' => $agentResult->error,
                ]);

                $run->markCheckpointSkipped(TaskRun::CHECKPOINT_CHANGES_REVIEWED);

                return;
            }

            $reviewFeedback = $this->buildReviewFeedback($agentResult);

            $this->log($run, 'warning', 'Coding agent review failed', [
                'attempt' => $attempt,
                'max_attempts' => $maxReviewAttempts,
                'error' => $agentResult->error,
            ]);

            $this->log($run, 'info', 'Coding agent review fix started', array_merge(
                $this->agentLogContext($run, 'review_fix'),
                ['attempt' => $attempt],
            ));

            $fixResult = $codingAgent->fixReviewFindings($task, $run, $reviewFeedback, $attempt);
            $this->logAgentMessages($run, $fixResult);

            if (! $fixResult->successful) {
                throw new Exception((string) $fixResult->error ?: 'Coding agent review fix failed.');
            }

            $this->log($run, 'info', 'Coding agent review fix completed', [
                'attempt' => $attempt,
            ]);

            if (! $this->runTests($repositoryPath, $run)) {
                throw new Exception('Tests failed after review fix.');
            }
        }

        $run->markCheckpointSkipped(TaskRun::CHECKPOINT_CHANGES_REVIEWED);
    }

    private function commitChanges(
        CodingAgent $codingAgent,
        Task $task,
        TaskRun $run,
        string $repositoryPath,
        string $branchName,
    ): void {
        $run->markCheckpointRunning(TaskRun::CHECKPOINT_CHANGES_COMMITTED);

        $commitMessage = $this->generateCommitMessage($codingAgent, $task, $run);
        $this->commitPendingChanges($repositoryPath, $commitMessage, $task->assignee);
        $this->pushBranch($repositoryPath, $branchName);

        $run->markCheckpointCompleted(TaskRun::CHECKPOINT_CHANGES_COMMITTED);
    }

    private function generateCommitMessage(CodingAgent $codingAgent, Task $task, TaskRun $run): string
    {
        $run->update(['status' => TaskRun::STATUS_GENERATING_COMMIT_MESSAGE]);

        $this->log($run, 'info', 'Coding agent commit message generation started', $this->agentLogContext($run, 'commit_message'));

        $agentResult = $codingAgent->generateCommitMessage($task, $run);
        $this->logAgentMessages($run, $agentResult);

        if (! $agentResult->successful) {
            throw new Exception((string) $agentResult->error ?: 'Coding agent commit message generation failed.');
        }

        $message = trim((string) ($agentResult->payload['message'] ?? ''));

        if ($message === '') {
            throw new Exception('Coding agent did not return a commit message.');
        }

        return $message;
    }

    private function commitPendingChanges(string $path, string $message, ?User $author): void
    {
        $runStatus = ['status' => TaskRun::STATUS_COMMITTING_CHANGES];

        $status = $this->runProcess(['git', 'status', '--short'], $path);
        if (! $status->isSuccessful()) {
            throw new Exception('Unable to inspect changes before commit: '.trim((string) $status->getErrorOutput()));
        }

        if (trim((string) $status->getOutput()) === '') {
            return;
        }

        TaskRun::query()->whereKey($this->taskRunId)->update($runStatus);

        $add = $this->runProcess(['git', 'add', '--all'], $path);
        if (! $add->isSuccessful()) {
            throw new Exception('Unable to stage changes before commit: '.trim((string) $add->getErrorOutput()));
        }

        $diff = $this->runProcess(['git', 'diff', '--cached', '--quiet'], $path);
        if ($diff->getExitCode() === 0) {
            return;
        }

        if ($diff->getExitCode() !== 1) {
            throw new Exception('Unable to inspect staged changes before commit: '.trim((string) $diff->getErrorOutput()));
        }

        $commit = $this->runProcess(
            ['git', 'commit', '-m', $message],
            $path,
            $this->gitAuthorEnvironment($author),
        );
        if (! $commit->isSuccessful()) {
            throw new Exception('Unable to commit changes: '.trim((string) $commit->getErrorOutput()));
        }
    }

    private function pushBranch(string $path, string $branchName): void
    {
        $push = $this->runProcess(['git', 'push', '-u', 'origin', $branchName], $path);
        if (! $push->isSuccessful()) {
            throw new Exception('Unable to push committed changes: '.trim((string) $push->getErrorOutput()));
        }
    }

    private function createPullRequest(PullRequestProvider $pullRequestProvider, Task $task, TaskRun $run): void
    {
        $run->markCheckpointRunning(TaskRun::CHECKPOINT_PULL_REQUEST_CREATED);

        if ($run->pull_request_url) {
            $run->update([
                'status' => TaskRun::STATUS_WAITING_FOR_MERGE,
                'pull_request_url' => $run->pull_request_url,
                'pull_request_number' => $run->pull_request_number,
            ]);

            $task->update(['status' => Task::STATUS_PR_CREATED]);

            $run->markCheckpointSkipped(TaskRun::CHECKPOINT_PULL_REQUEST_CREATED);

            return;
        }

        $run->update(['status' => TaskRun::STATUS_CREATING_PR]);
        $pr = $pullRequestProvider->createPullRequest($task, $run, $task->assignee);

        $task->update(['status' => Task::STATUS_PR_CREATED]);

        $run->update([
            'status' => TaskRun::STATUS_WAITING_FOR_MERGE,
            'pull_request_url' => $pr->url,
            'pull_request_number' => $pr->number,
        ]);

        $run->markCheckpointCompleted(TaskRun::CHECKPOINT_PULL_REQUEST_CREATED);
    }

    private function requestReview(PullRequestProvider $pullRequestProvider, Task $task, TaskRun $run): void
    {
        $run->markCheckpointRunning(TaskRun::CHECKPOINT_REVIEW_REQUESTED);

        $reviewer = $this->resolvePullRequestReviewer($task);
        if (! $reviewer || ! $run->pull_request_url) {
            $this->logReviewRequestSkipped(
                $run,
                $reviewer,
                $reviewer ? 'missing_pull_request_url' : 'missing_reviewer',
            );
            $run->markCheckpointSkipped(TaskRun::CHECKPOINT_REVIEW_REQUESTED);

            return;
        }

        if ($task->assignee && $this->isSameGithubUser($reviewer, $task->assignee)) {
            $this->logReviewRequestSkipped($run, $reviewer, 'reviewer_is_pull_request_author', $task->assignee);
            $run->markCheckpointSkipped(TaskRun::CHECKPOINT_REVIEW_REQUESTED);

            return;
        }

        if ($task->approvedByUser && $this->isSameGithubUser($reviewer, $task->approvedByUser)) {
            $this->logReviewRequestSkipped($run, $reviewer, 'reviewer_is_task_approver', $task->approvedByUser);
            $run->markCheckpointSkipped(TaskRun::CHECKPOINT_REVIEW_REQUESTED);

            return;
        }

        $pullRequestProvider->requestReview($run->pull_request_url, $reviewer, $task->assignee);
        $run->markCheckpointCompleted(TaskRun::CHECKPOINT_REVIEW_REQUESTED);
    }

    private function markRunWaitingForMerge(Task $task, TaskRun $run): void
    {
        if ($run->pull_request_url === null || $run->pull_request_url === '') {
            return;
        }

        $task->update(['status' => Task::STATUS_PR_CREATED]);

        $run->update([
            'status' => TaskRun::STATUS_WAITING_FOR_MERGE,
            'last_error' => null,
        ]);
    }

    private function isSameGithubUser(User $first, User $second): bool
    {
        return mb_strtolower((string) $first->github_username) === mb_strtolower((string) $second->github_username);
    }

    private function logReviewRequestSkipped(TaskRun $run, ?User $reviewer, string $reason, ?User $matchingUser = null): void
    {
        $this->log($run, 'info', 'Pull request review request skipped', [
            'checkpoint' => TaskRun::CHECKPOINT_REVIEW_REQUESTED,
            'reason' => $reason,
            'reviewer_user_id' => $reviewer?->id,
            'reviewer_github_username' => $reviewer?->github_username,
            'matching_user_id' => $matchingUser?->id,
            'matching_github_username' => $matchingUser?->github_username,
        ]);
    }

    private function updateExternalTask(ExternalTaskProvider $externalTaskProvider, Task $task, TaskRun $run): void
    {
        $run->markCheckpointRunning(TaskRun::CHECKPOINT_EXTERNAL_TASK_UPDATED);

        if (! $task->externalTaskLink || ! $run->pull_request_url) {
            $run->markCheckpointSkipped(TaskRun::CHECKPOINT_EXTERNAL_TASK_UPDATED);

            return;
        }

        $task->externalTaskLink->messages()->create([
            'type' => 'pr_attached',
            'payload' => ['run_id' => $run->id, 'pull_request_url' => $run->pull_request_url],
            'status' => 'success',
            'error' => null,
            'sent_at' => now(),
        ]);

        $externalTaskProvider->attachPullRequest($task->externalTaskLink, $run->pull_request_url);
        $run->markCheckpointCompleted(TaskRun::CHECKPOINT_EXTERNAL_TASK_UPDATED);
    }

    /**
     * @return array<string, string>
     */
    private function gitAuthorEnvironment(?User $author): array
    {
        if ($author === null || $author->github_username === null || $author->github_username === '' || $author->email === '') {
            return [];
        }

        return [
            'GIT_AUTHOR_NAME' => $author->github_username,
            'GIT_AUTHOR_EMAIL' => $author->email,
            'GIT_COMMITTER_NAME' => $author->github_username,
            'GIT_COMMITTER_EMAIL' => $author->email,
        ];
    }

    private function log(TaskRun $run, string $level, string $message, array $context = []): void
    {
        TaskRunLog::create([
            'task_run_id' => $run->id,
            'level' => $level,
            'message' => $message,
            'context' => array_merge([
                'coding_agent' => (string) config('automation.coding_agent.driver', 'codex'),
            ], $context),
        ]);
    }

    private function logAgentMessages(TaskRun $run, CodingAgentResult $agentResult): void
    {
        if ($agentResult->messages === [] && $agentResult->context !== []) {
            $this->log($run, 'info', 'Coding agent output', array_merge(
                $agentResult->context,
                ['message' => 'Coding agent command invoked.'],
            ));
        }

        foreach ($agentResult->messages as $message) {
            $this->log($run, 'info', 'Coding agent output', array_merge(
                $agentResult->context,
                ['message' => $message],
            ));
        }
    }

    /**
     * @return array{agent_phase: string, agent_model: string|null, agent_reasoning_effort: string|null}
     */
    private function agentLogContext(TaskRun $run, string $phase): array
    {
        [$model, $reasoningEffort] = match ($phase) {
            'plan' => [$run->plan_model, $run->plan_reasoning_effort],
            'implement', 'review_fix' => [$run->implement_model, $run->implement_reasoning_effort],
            'review' => [$run->review_model, $run->review_reasoning_effort],
            'commit_message' => [$run->commit_message_model, $run->commit_message_reasoning_effort],
            default => [null, null],
        };

        return [
            'agent_phase' => $phase,
            'agent_model' => $this->nullableAgentSetting($model),
            'agent_reasoning_effort' => $this->nullableAgentSetting($reasoningEffort),
        ];
    }

    private function nullableAgentSetting(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function buildReviewFeedback(CodingAgentResult $agentResult): string
    {
        $sections = [];
        $reviewText = trim((string) Arr::get($agentResult->payload, 'review_text', ''));

        if ($agentResult->error !== null && $agentResult->error !== '' && $agentResult->error !== $reviewText) {
            $sections[] = 'Error: '.$this->limitReviewFeedback($agentResult->error, 12000);
        }

        if ($reviewText !== '') {
            $sections[] = "Review text:\n".$this->limitReviewFeedback($reviewText, 12000);
        } elseif ($agentResult->messages !== []) {
            $sections[] = "Messages:\n".implode("\n", array_map(
                fn (string $message): string => '- '.$this->limitReviewFeedback($message, 3000),
                $agentResult->messages,
            ));
        }

        if ($agentResult->payload !== [] && $reviewText === '') {
            $sections[] = "Payload:\n".json_encode(
                $agentResult->payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        return $this->limitReviewFeedback(implode("\n\n", $sections), 16000);
    }

    private function limitReviewFeedback(string $text, int $limit): string
    {
        $text = trim($text);

        if (Str::length($text) <= $limit) {
            return $text;
        }

        return Str::substr($text, 0, $limit)."\n\n[truncated to keep review-fix prompt within OS argument limits]";
    }

    private function resolveBaseBranch(TaskRun $run): string
    {
        return $this->normalizeBaseBranch((string) $run->base_branch);
    }

    private function normalizeBaseBranch(string $baseBranch): string
    {
        $trimmed = trim($baseBranch);

        return $trimmed === '' ? 'main' : $trimmed;
    }

    private function resolveExecutionPath(TaskRun $run): string
    {
        if ($run->workspace_path !== null && $run->workspace_path !== '') {
            return $run->workspace_path;
        }

        return base_path();
    }

    /**
     * @param  array<int, string>|string  $command
     * @param  array<string, string>  $environment
     */
    private function runProcess(array|string $command, string $path, array $environment = []): Process
    {
        $process = is_array($command)
            ? new Process($command, $path, $environment)
            : Process::fromShellCommandline($command, $path, $environment);

        $process->setTimeout(null);
        $process->run();

        return $process;
    }
}
