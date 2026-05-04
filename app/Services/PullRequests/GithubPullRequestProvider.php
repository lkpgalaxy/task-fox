<?php

namespace App\Services\PullRequests;

use App\Contracts\PullRequestProvider;
use App\DataTransferObjects\PullRequestResult;
use App\Enums\PullRequestReviewState;
use App\Models\AiRun;
use App\Models\Task;
use App\Models\User;
use Exception;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class GithubPullRequestProvider implements PullRequestProvider
{
    public function createPullRequest(Task $task, AiRun $run): PullRequestResult
    {
        $body = $this->buildPrBody($task);
        $repositoryPath = $this->resolveRepositoryPath($run);

        $this->commitPendingChanges($repositoryPath, $task);

        $result = $this->runProcess([
            'gh',
            'pr',
            'create',
            '--title',
            $task->title,
            '--body',
            $body,
            '--head',
            $run->branch_name,
        ], $repositoryPath);

        if (! $result->isSuccessful()) {
            throw new Exception('gh pr create failed: '.trim((string) $result->getErrorOutput()));
        }

        $url = $this->parsePullRequestUrl((string) $result->getOutput());
        $number = $this->parsePullRequestNumber($url);

        if ($number === null) {
            throw new Exception('gh pr create returned invalid values.');
        }

        return new PullRequestResult(
            url: $url,
            number: $number,
        );
    }

    public function requestReview(string $pullRequestUrl, User $user): void
    {
        if ($user->github_username === null || $user->github_username === '') {
            return;
        }

        $result = $this->runProcess([
            'gh',
            'pr',
            'edit',
            $pullRequestUrl,
            '--add-reviewer',
            $user->github_username,
        ], $this->resolveExecutionPath());

        if (! $result->isSuccessful()) {
            throw new Exception('gh pr review request failed: '.trim((string) $result->getErrorOutput()));
        }
    }

    public function getReviewState(string $pullRequestUrl): PullRequestReviewState
    {
        $result = $this->runProcess([
            'gh',
            'pr',
            'view',
            $pullRequestUrl,
            '--json',
            'state',
        ], $this->resolveExecutionPath());

        if (! $result->isSuccessful()) {
            return PullRequestReviewState::UNKNOWN;
        }

        $payload = json_decode((string) $result->getOutput(), true);
        if (! is_array($payload) || ! isset($payload['state']) || ! is_string($payload['state'])) {
            return PullRequestReviewState::UNKNOWN;
        }

        return match (strtolower($payload['state'])) {
            'open' => PullRequestReviewState::OPEN,
            'closed' => PullRequestReviewState::CLOSED,
            'merged' => PullRequestReviewState::MERGED,
            'draft' => PullRequestReviewState::DRAFT,
            default => PullRequestReviewState::UNKNOWN,
        };
    }

    private function buildPrBody(Task $task): string
    {
        $criteria = collect($task->acceptance_criteria ?? [])->values();

        if ($criteria->isEmpty()) {
            $criteria = collect([['body' => 'No acceptance criteria provided.', 'checked' => false]]);
        }

        $items = $criteria
            ->filter(static fn (array $criterion): bool => isset($criterion['body']) && trim((string) $criterion['body']) !== '')
            ->map(static fn (array $criterion): string => '- ['.($criterion['checked'] ? 'x' : ' ')."] {$criterion['body']}")
            ->join("\n");

        $description = trim((string) $task->description);
        if ($description === '') {
            $description = 'No description provided.';
        }

        return <<<BODY
Task: {$task->title}

Source task:
{$task->id}

Description:
{$description}

Acceptance criteria:
{$items}
BODY;
    }

    private function parsePullRequestUrl(string $output): string
    {
        if (! preg_match('~https?://\S+~', $output, $matches)) {
            throw new Exception('Invalid gh output for PR creation');
        }

        return rtrim($matches[0], " \t\n\r\0\x0B.,)");
    }

    private function parsePullRequestNumber(string $url): ?int
    {
        if (! preg_match('~/pull/(\d+)(?:[/?#]|$)~', $url, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function commitPendingChanges(string $path, Task $task): void
    {
        $status = $this->runProcess(['git', 'status', '--short'], $path);
        if (! $status->isSuccessful()) {
            throw new Exception('Unable to inspect changes before pull request creation: '.trim((string) $status->getErrorOutput()));
        }

        if (trim((string) $status->getOutput()) === '') {
            return;
        }

        $add = $this->runProcess(['git', 'add', '--all'], $path);
        if (! $add->isSuccessful()) {
            throw new Exception('Unable to stage changes before pull request creation: '.trim((string) $add->getErrorOutput()));
        }

        $diff = $this->runProcess(['git', 'diff', '--cached', '--quiet'], $path);
        if ($diff->getExitCode() === 0) {
            return;
        }

        if ($diff->getExitCode() !== 1) {
            throw new Exception('Unable to inspect staged changes before pull request creation: '.trim((string) $diff->getErrorOutput()));
        }

        $commit = $this->runProcess(['git', 'commit', '-m', $this->makeCommitMessage($task)], $path);
        if (! $commit->isSuccessful()) {
            throw new Exception('Unable to commit changes before pull request creation: '.trim((string) $commit->getErrorOutput()));
        }
    }

    private function makeCommitMessage(Task $task): string
    {
        $slug = Str::slug((string) $task->title);

        return 'feat: complete task '.(string) $task->id.($slug !== '' ? ' '.$slug : '');
    }

    private function resolveExecutionPath(?string $path = null): string
    {
        return $path !== null && $path !== '' ? $path : base_path();
    }

    private function resolveRepositoryPath(AiRun $run): string
    {
        if ($run->workspace_path === null || $run->workspace_path === '') {
            return $this->resolveExecutionPath($run->repository_path);
        }

        return $run->workspace_path;
    }

    private function runProcess(array $command, string $path): Process
    {
        $process = new Process($command, $path);
        $process->setTimeout(null);
        $process->run();

        return $process;
    }
}
