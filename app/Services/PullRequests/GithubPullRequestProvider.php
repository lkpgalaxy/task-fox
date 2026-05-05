<?php

namespace App\Services\PullRequests;

use App\Contracts\PullRequestProvider;
use App\DataTransferObjects\PullRequestResult;
use App\Enums\PullRequestReviewState;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\User;
use Exception;
use Symfony\Component\Process\Process;

class GithubPullRequestProvider implements PullRequestProvider
{
    public function createPullRequest(Task $task, TaskRun $run, ?User $author = null): PullRequestResult
    {
        $body = $this->buildPrBody($task);
        $repositoryPath = $this->resolveRepositoryPath($run);

        $this->assertNoPendingChanges($repositoryPath);

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
        ], $repositoryPath, $this->githubTokenEnvironment($author));

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

    public function requestReview(string $pullRequestUrl, User $user, ?User $actor = null): void
    {
        if ($user->github_username === null || $user->github_username === '') {
            return;
        }

        $pullRequest = $this->parsePullRequestApiPath($pullRequestUrl);

        $result = $this->runProcess([
            'gh',
            'api',
            '--method',
            'POST',
            $pullRequest.'/requested_reviewers',
            '-f',
            'reviewers[]='.$user->github_username,
        ], $this->resolveExecutionPath(), $this->githubTokenEnvironment($actor));

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

    private function parsePullRequestApiPath(string $url): string
    {
        if (! preg_match('~github\.com/([^/\s]+)/([^/\s]+)/pull/(\d+)(?:[/?#]|$)~', $url, $matches)) {
            throw new Exception('Invalid GitHub pull request URL.');
        }

        return "repos/{$matches[1]}/{$matches[2]}/pulls/{$matches[3]}";
    }

    private function assertNoPendingChanges(string $path): void
    {
        $status = $this->runProcess(['git', 'status', '--short'], $path);
        if (! $status->isSuccessful()) {
            throw new Exception('Unable to inspect changes before pull request creation: '.trim((string) $status->getErrorOutput()));
        }

        if (trim((string) $status->getOutput()) === '') {
            return;
        }

        throw new Exception('Pull request creation requires committed changes; commit before creating a pull request.');
    }

    /**
     * @return array<string, string>
     */
    private function githubTokenEnvironment(?User $user): array
    {
        if ($user === null || $user->github_token === null || $user->github_token === '') {
            return [];
        }

        return [
            'GH_TOKEN' => $user->github_token,
        ];
    }

    private function resolveExecutionPath(?string $path = null): string
    {
        return $path !== null && $path !== '' ? $path : base_path();
    }

    private function resolveRepositoryPath(TaskRun $run): string
    {
        return $this->resolveExecutionPath($run->workspace_path);
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $environment
     */
    private function runProcess(array $command, string $path, array $environment = []): Process
    {
        $process = new Process($command, $path, $environment);
        $process->setTimeout(null);
        $process->run();

        return $process;
    }
}
