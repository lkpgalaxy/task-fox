<?php

namespace App\Jobs;

use App\Contracts\CodingAgent;
use App\Contracts\ExternalTaskProvider;
use App\Contracts\PullRequestProvider;
use App\DataTransferObjects\CodingAgentResult;
use App\Models\AiRun;
use App\Models\AiRunLog;
use App\Models\Task;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class RunApprovedTaskWithCodingAgentJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $aiRunId) {}

    public function handle(
        CodingAgent $codingAgent,
        ExternalTaskProvider $externalTaskProvider,
        PullRequestProvider $pullRequestProvider,
    ): void {
        $run = AiRun::with(['task.assignee', 'task.externalTaskLink'])->find($this->aiRunId);

        if ($run === null || ! $run->task) {
            return;
        }

        $task = $run->task;
        $repositoryPath = $this->resolveExecutionPath($run);
        $branchName = $this->makeBranchName($task);
        $baseBranch = $this->resolveBaseBranch($run);

        try {
            $run->update([
                'status' => AiRun::STATUS_PREPARING,
                'branch_name' => $branchName,
                'started_at' => now(),
                'last_error' => null,
            ]);

            $task->update(['status' => Task::STATUS_RUNNING]);

            $this->log($run, 'info', 'AI run started', [
                'task_id' => $task->id,
                'run_id' => $run->id,
            ]);

            $this->assertRepositoryReady($repositoryPath, $baseBranch);
            $this->createBranch($repositoryPath, $branchName, $baseBranch);

            $maxAttempts = max(1, (int) config('automation.agent.retry_limit', 2));
            $passed = false;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $run->increment('attempt_count');
                $run->update(['status' => AiRun::STATUS_IMPLEMENTING]);

                $this->log($run, 'info', 'Coding agent invocation started', [
                    'attempt' => $attempt,
                ]);

                $agentResult = $codingAgent->run($task, $run);
                $this->logAgentMessages($run, $agentResult);

                if (! $agentResult->successful) {
                    throw new Exception((string) $agentResult->error ?: 'Coding agent execution failed.');
                }

                if ($this->runTests($repositoryPath, $run)) {
                    $passed = true;

                    break;
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

                $run->update(['status' => AiRun::STATUS_PLANNING]);
            }

            if (! $passed) {
                throw new Exception('Tests failed after retry limit reached.');
            }

            $run->update(['status' => AiRun::STATUS_CREATING_PR]);
            $pr = $pullRequestProvider->createPullRequest($task, $run);

            $task->update([
                'status' => Task::STATUS_PR_CREATED,
                'pull_request_url' => $pr->url,
                'pull_request_number' => $pr->number,
            ]);

            $run->update([
                'status' => AiRun::STATUS_WAITING_FOR_MERGE,
                'pull_request_url' => $pr->url,
                'pull_request_number' => $pr->number,
            ]);

            if ($task->assignee) {
                $pullRequestProvider->requestReview($pr->url, $task->assignee);
            }

            if ($task->externalTaskLink) {
                $task->externalTaskLink->messages()->create([
                    'type' => 'pr_attached',
                    'payload' => ['run_id' => $run->id, 'pull_request_url' => $pr->url],
                    'status' => 'success',
                    'error' => null,
                    'sent_at' => now(),
                ]);

                $externalTaskProvider->attachPullRequest($task->externalTaskLink, $pr->url);
            }

            $this->log($run, 'info', 'Pull request created and waiting for merge', [
                'pull_request_url' => $pr->url,
            ]);
        } catch (Throwable $exception) {
            $task->update([
                'status' => Task::STATUS_FAILED,
                'approved_at' => null,
                'approved_by_user_id' => null,
            ]);

            $run->update([
                'status' => AiRun::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            $this->log($run, 'error', 'AI run failed', [
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
            DispatchNextAiRunJob::dispatch();
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

    private function runTests(string $path, AiRun $run): bool
    {
        $command = (string) config('automation.tests.command', 'php artisan test --compact');
        $process = $this->runProcess($command, $path);

        $run->update(['status' => AiRun::STATUS_TESTING]);

        $this->log($run, 'info', 'Test command executed', [
            'command' => $command,
            'exit_code' => $process->getExitCode(),
        ]);

        return $process->isSuccessful();
    }

    private function log(AiRun $run, string $level, string $message, array $context = []): void
    {
        AiRunLog::create([
            'ai_run_id' => $run->id,
            'level' => $level,
            'message' => $message,
            'context' => array_merge([
                'coding_agent' => (string) config('automation.coding_agent.driver', 'codex'),
            ], $context),
        ]);
    }

    private function logAgentMessages(AiRun $run, CodingAgentResult $agentResult): void
    {
        foreach ($agentResult->messages as $message) {
            $this->log($run, 'info', 'Coding agent output', ['message' => $message]);
        }
    }

    private function resolveBaseBranch(AiRun $run): string
    {
        return $this->normalizeBaseBranch((string) $run->base_branch);
    }

    private function normalizeBaseBranch(string $baseBranch): string
    {
        $trimmed = trim($baseBranch);

        return $trimmed === '' ? 'main' : $trimmed;
    }

    private function resolveExecutionPath(AiRun $run): string
    {
        if ($run->workspace_path !== null && $run->workspace_path !== '') {
            return $run->workspace_path;
        }

        if ($run->repository_path !== null && $run->repository_path !== '') {
            return $run->repository_path;
        }

        return base_path();
    }

    private function runProcess(array|string $command, string $path): Process
    {
        $process = is_array($command)
            ? new Process($command, $path)
            : Process::fromShellCommandline($command, $path);

        $process->setTimeout(null);
        $process->run();

        return $process;
    }
}
