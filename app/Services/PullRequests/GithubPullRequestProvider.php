<?php

namespace App\Services\PullRequests;

use App\Contracts\PullRequestProvider;
use App\DataTransferObjects\PullRequestResult;
use App\Enums\PullRequestReviewState;
use App\Models\AiRun;
use App\Models\Task;
use App\Models\User;
use Exception;
use Illuminate\Support\Arr;
use Symfony\Component\Process\Process;

class GithubPullRequestProvider implements PullRequestProvider
{
    public function createPullRequest(Task $task, AiRun $run): PullRequestResult
    {
        $body = $this->buildPrBody($task);

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
            '--json',
            'url,number',
        ], $this->resolveRepositoryPath($run));

        if (! $result->isSuccessful()) {
            throw new Exception('gh pr create failed: '.trim((string) $result->getErrorOutput()));
        }

        $payload = json_decode((string) $result->getOutput(), true);
        if (! is_array($payload)) {
            throw new Exception('Invalid gh output for PR creation');
        }

        $url = Arr::get($payload, 'url');
        $number = Arr::get($payload, 'number', 0);

        if (! is_string($url) || $url === '' || ! is_int($number) && ! ctype_digit((string) $number)) {
            throw new Exception('gh pr create returned invalid values.');
        }

        return new PullRequestResult(
            url: $url,
            number: (int) $number,
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
