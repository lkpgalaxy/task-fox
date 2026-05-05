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

        $this->assertRepositoryReady($repositoryPath, $baseBranch);
        $this->createBranch($repositoryPath, $branchName, $baseBranch);

        $run->markCheckpointCompleted(TaskRun::CHECKPOINT_REPOSITORY_PREPARED);
    }

    private function planImplementation(CodingAgent $codingAgent, Task $task, TaskRun $run): void
    {
        $run->markCheckpointRunning(TaskRun::CHECKPOINT_PLANNED);
        $run->update(['status' => TaskRun::STATUS_PLANNING]);

        $this->log($run, 'info', 'Coding agent planning started');

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

            $this->log($run, 'info', 'Coding agent invocation started', [
                'attempt' => $attempt,
            ]);

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
                throw new Exception('Tests failed after retry limit reached.');
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

    private function assertRepositoryReady(string $path, string $baseBranch): void
    {
        if ($path === '') {
            throw new Exception('Repository path is missing from configuration.');
        }

        $status = $this->runProcess(['git', 'rev-parse', '--is-inside-work-tree'], $path);
        if (! $status->isSuccessful()) {
            throw new Exception('Target path is not a git repository.');
        }

        $cleanCheck = $this->runProcess(['git', 'status', '--short'], $path);
        if (! $cleanCheck->isSuccessful()) {
            throw new Exception('Unable to verify git working tree clean state: '.trim((string) $cleanCheck->getErrorOutput()));
        }

        if (trim((string) $cleanCheck->getOutput()) !== '') {
            throw new Exception('Repository is not clean; commit or stash changes before running AI agent.');
        }

        $branch = $this->normalizeBaseBranch($baseBranch);
        $baseBranchCheck = $this->runProcess(['git', 'rev-parse', '--verify', $branch], $path);
        if (! $baseBranchCheck->isSuccessful()) {
            throw new Exception("Base branch {$branch} does not exist in repository.");
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

        $run->update(['status' => TaskRun::STATUS_TESTING]);

        $this->log($run, 'info', 'Test command executed', [
            'command' => $command,
            'exit_code' => $process->getExitCode(),
        ]);

        return $process->isSuccessful();
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

    private function reviewChanges(CodingAgent $codingAgent, Task $task, TaskRun $run, string $repositoryPath): void
    {
        $maxReviewAttempts = max(1, (int) config('automation.agent.retry_limit', 3));

        for ($attempt = 1; $attempt <= $maxReviewAttempts; $attempt++) {
            $run->markCheckpointRunning(TaskRun::CHECKPOINT_CHANGES_REVIEWED);
            $run->update([
                'status' => TaskRun::STATUS_REVIEWING_CHANGES,
                'review_attempt_count' => $run->checkpointAttempts(TaskRun::CHECKPOINT_CHANGES_REVIEWED),
            ]);

            $this->log($run, 'info', 'Coding agent review started', [
                'attempt' => $attempt,
            ]);

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

                throw new Exception('Coding agent review failed after retry limit reached.');
            }

            $reviewFeedback = $this->buildReviewFeedback($agentResult);

            $this->log($run, 'warning', 'Coding agent review failed', [
                'attempt' => $attempt,
                'max_attempts' => $maxReviewAttempts,
                'error' => $agentResult->error,
            ]);

            $this->log($run, 'info', 'Coding agent review fix started', [
                'attempt' => $attempt,
            ]);

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

        throw new Exception('Coding agent review failed after retry limit reached.');
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

        $this->log($run, 'info', 'Coding agent commit message generation started');

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
            $run->markCheckpointSkipped(TaskRun::CHECKPOINT_REVIEW_REQUESTED);

            return;
        }

        if ($task->assignee && $this->isSameGithubUser($reviewer, $task->assignee)) {
            $run->markCheckpointSkipped(TaskRun::CHECKPOINT_REVIEW_REQUESTED);

            return;
        }

        if ($task->approvedByUser && $this->isSameGithubUser($reviewer, $task->approvedByUser)) {
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
        foreach ($agentResult->messages as $message) {
            $this->log($run, 'info', 'Coding agent output', ['message' => $message]);
        }
    }

    private function buildReviewFeedback(CodingAgentResult $agentResult): string
    {
        $sections = [];

        if ($agentResult->error !== null && $agentResult->error !== '') {
            $sections[] = 'Error: '.$agentResult->error;
        }

        if ($agentResult->messages !== []) {
            $sections[] = "Messages:\n".implode("\n", array_map(
                static fn (string $message): string => '- '.$message,
                $agentResult->messages,
            ));
        }

        if ($agentResult->payload !== []) {
            $sections[] = "Payload:\n".json_encode(
                $agentResult->payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        return implode("\n\n", $sections);
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
