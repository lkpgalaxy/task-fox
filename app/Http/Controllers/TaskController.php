<?php

namespace App\Http\Controllers;

use App\Contracts\ExternalTaskProvider;
use App\Contracts\PullRequestProvider;
use App\Enums\PullRequestReviewState;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Jobs\DispatchNextAiRunJob;
use App\Models\AiRun;
use App\Models\AiRunLog;
use App\Models\InputSource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TaskController extends Controller
{
    public function __construct(
        private readonly ExternalTaskProvider $externalTaskProvider,
        private readonly PullRequestProvider $pullRequestProvider,
    ) {}

    public function index(Request $request): Response
    {
        $tasks = Task::query()
            ->with([
                'assignee:id,name,github_username',
                'reviewer:id,name,github_username',
                'approvedByUser:id,name,github_username',
                'sourceInput:id,title,filename,file_disk,file_path,mime_type,file_size,analysis_status',
                'project:id,name,workspace_path,url,database_name,database_username,database_password,credential_username,credential_password,base_branch,default_reviewer_user_id',
                'project.defaultReviewer:id,name,github_username',
                'latestAiRun' => fn ($query) => $query->select([
                    'ai_runs.id',
                    'ai_runs.task_id',
                    'ai_runs.status',
                    'ai_runs.branch_name',
                    'ai_runs.pull_request_url',
                    'ai_runs.pull_request_number',
                    'ai_runs.review_attempt_count',
                    'ai_runs.workflow_state',
                ]),
                'externalTaskLink:id,task_id,external_task_provider,external_task_id,external_url',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Task $task) => $this->serializeTask($task));

        $selectedTask = null;
        if ($request->integer('task')) {
            $selectedTask = Task::query()
                ->with([
                    'assignee:id,name,github_username',
                    'reviewer:id,name,github_username',
                    'approvedByUser:id,name,github_username',
                    'sourceInput:id,title,filename,file_disk,file_path,mime_type,file_size,analysis_status',
                    'project:id,name,workspace_path,url,database_name,database_username,database_password,credential_username,credential_password,base_branch,default_reviewer_user_id',
                    'project.defaultReviewer:id,name,github_username',
                    'aiRuns:id,task_id,status,branch_name,pull_request_url,pull_request_number,attempt_count,review_attempt_count,workflow_state,last_error,started_at,finished_at,updated_at',
                    'aiRuns.logs:id,ai_run_id,level,message,context,created_at',
                    'externalTaskLink.messages:id,external_task_link_id,type,status,error,sent_at,payload',
                ])
                ->find($request->integer('task'));

            if ($selectedTask instanceof Task) {
                $selectedTask = $this->serializeTask($selectedTask, true);
            }
        }

        return Inertia::render('tasks/Index', [
            'tasks' => $tasks,
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'github_username']),
            'sourceInputs' => InputSource::query()
                ->whereDate('created_at', today())
                ->orderByDesc('created_at')
                ->get(['id', 'title', 'filename', 'file_path', 'mime_type', 'file_size', 'analysis_status'])
                ->map(fn (InputSource $inputSource): array => [
                    'id' => $inputSource->id,
                    'title' => $inputSource->title,
                    'filename' => $inputSource->filename,
                    'mime_type' => $inputSource->mime_type,
                    'file_size' => $inputSource->file_size,
                    'analysis_status' => $inputSource->analysis_status,
                    'has_file' => $inputSource->hasStoredFile(),
                ]),
            'projects' => Project::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => (string) $project->name,
                    'default_reviewer_user_id' => $project->default_reviewer_user_id,
                ]),
            'selectedTask' => $selectedTask,
            'taskStatuses' => [
                Task::STATUS_DRAFT,
                Task::STATUS_PENDING_APPROVAL,
                Task::STATUS_APPROVED,
                Task::STATUS_RUNNING,
                Task::STATUS_PR_CREATED,
                Task::STATUS_DONE,
                Task::STATUS_FAILED,
                Task::STATUS_REJECTED,
            ],
            'priorities' => [
                Task::PRIORITY_LOW,
                Task::PRIORITY_MEDIUM,
                Task::PRIORITY_HIGH,
                Task::PRIORITY_URGENT,
            ],
        ]);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $actor = $this->resolveCurrentUser();
        $data = $request->validated();

        $assigneeUserId = Arr::get($data, 'assignee_user_id');
        if (! $assigneeUserId && $actor?->github_username) {
            $assigneeUserId = $actor->id;
        }

        Task::create([
            'title' => (string) Arr::get($data, 'title'),
            'description' => (string) Arr::get($data, 'description'),
            'acceptance_criteria' => $this->normalizeCriteria($request->acceptanceCriteria()),
            'status' => Task::STATUS_DRAFT,
            'priority' => Arr::get($data, 'priority') ?? Task::PRIORITY_MEDIUM,
            'deadline' => Arr::get($data, 'deadline'),
            'assignee_user_id' => $assigneeUserId,
            'reviewer_user_id' => Arr::get($data, 'reviewer_user_id') ?: $this->resolveProjectDefaultReviewerId(Arr::get($data, 'project_id')),
            'project_id' => Arr::get($data, 'project_id'),
            'source_input_id' => Arr::get($data, 'source_input_id'),
        ]);

        return redirect()
            ->route('tasks.index')
            ->with('status', 'Task created.');
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        $actor = $this->resolveCurrentUser();
        $data = $request->validated();

        $criteria = $request->acceptanceCriteria();

        $approvalFieldsChanged = $this->isApprovedFieldChanged($task, $data, $criteria);
        $shouldResetApproval = in_array($task->status, [Task::STATUS_APPROVED, Task::STATUS_FAILED], true)
            && $approvalFieldsChanged;

        $task->fill(
            Arr::only($data, [
                'title',
                'description',
                'priority',
                'deadline',
                'assignee_user_id',
                'reviewer_user_id',
                'source_input_id',
                'project_id',
            ]),
        );
        $task->acceptance_criteria = $this->normalizeCriteria($criteria);
        $task->status = $shouldResetApproval ? Task::STATUS_PENDING_APPROVAL : $task->status;

        if ($shouldResetApproval) {
            if ($actor === null) {
                throw ValidationException::withMessages([
                    'actor' => ['No actor available for approval updates.'],
                ]);
            }

            $task->approved_by_user_id = null;
            $task->approved_at = null;
            $task->rejected_at = null;
        }

        $task->save();

        if ($shouldResetApproval && $task->externalTaskLink) {
            $this->recordExternalMessage(
                $task,
                'update',
                [
                    'task_id' => $task->id,
                    'note' => 'Task approval fields were edited and approval was reset.',
                ],
                'failed',
                'Task edited while approved. Requires re-approval before execution.',
            );
        }

        return redirect()
            ->route('tasks.index', ['task' => $task->id])
            ->with('status', 'Task updated.');
    }

    public function submitForApproval(Task $task): RedirectResponse
    {
        if ($task->status !== Task::STATUS_DRAFT) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['status' => 'Only draft tasks can be submitted for approval.']);
        }

        $task->update([
            'status' => Task::STATUS_PENDING_APPROVAL,
            'rejected_at' => null,
            'approved_at' => null,
            'approved_by_user_id' => null,
        ]);

        return redirect()
            ->route('tasks.index', ['task' => $task->id])
            ->with('status', 'Task submitted for approval.');
    }

    public function approve(Task $task): RedirectResponse
    {
        if ($task->project_id === null) {
            return back()->withErrors([
                'project' => 'Assign a project before approving this task.',
            ]);
        }

        $actor = $this->resolveCurrentUser();

        if ($actor === null) {
            return back()->withErrors(['actor' => 'No actor available to record approval.']);
        }

        $task->update([
            'status' => Task::STATUS_APPROVED,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'rejected_at' => null,
        ]);

        if (! empty(config('automation.external_task_provider'))) {
            try {
                $result = $this->externalTaskProvider->createTask($task);

                if ($result->handled) {
                    $link = $task->externalTaskLink()->updateOrCreate(
                        ['task_id' => $task->id],
                        [
                            'external_task_provider' => $result->provider ?? 'unknown',
                            'external_task_id' => $result->externalTaskId,
                            'external_url' => $result->externalUrl,
                        ],
                    );

                    $this->recordExternalMessage(
                        $task,
                        'create',
                        [
                            'task_id' => $task->id,
                            'status' => $task->status,
                            'external_task_id' => $result->externalTaskId,
                        ],
                        'success',
                        null,
                    );
                }
            } catch (Throwable $exception) {
                if ($task->externalTaskLink) {
                    $this->recordExternalMessage(
                        $task,
                        'create',
                        [
                            'task_id' => $task->id,
                            'error' => $exception->getMessage(),
                        ],
                        'failed',
                        $exception->getMessage(),
                    );
                }
            }
        }

        DispatchNextAiRunJob::dispatch();

        return redirect()
            ->route('tasks.index')
            ->with('status', 'Task approved and queued for execution.');
    }

    public function reject(Task $task): RedirectResponse
    {
        $actor = $this->resolveCurrentUser();

        if ($actor === null) {
            return back()->withErrors(['actor' => 'No actor available to record rejection.']);
        }

        $task->update([
            'status' => Task::STATUS_REJECTED,
            'rejected_at' => now(),
            'approved_at' => null,
            'approved_by_user_id' => null,
        ]);

        if ($task->externalTaskLink) {
            $this->recordExternalMessage(
                $task,
                'update',
                ['task_id' => $task->id, 'status' => Task::STATUS_REJECTED],
                'success',
                null,
            );
        }

        return redirect()
            ->route('tasks.index')
            ->with('status', 'Task rejected.');
    }

    public function retry(Task $task): RedirectResponse
    {
        $task->loadMissing('aiRuns');

        if ($task->status !== Task::STATUS_FAILED) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['status' => 'Only failed tasks can be retried.']);
        }

        if ($task->project_id === null) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['project' => 'Assign a project before retrying this task.']);
        }

        $actor = $this->resolveCurrentUser();

        if ($actor === null) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['actor' => 'No actor available to record retry approval.']);
        }

        $run = $task->aiRuns()
            ->where('status', AiRun::STATUS_FAILED)
            ->latest('id')
            ->get()
            ->first(function (AiRun $candidate) use ($task): bool {
                return $candidate->requestHash() !== null && $candidate->hasMatchingRequestHash($task);
            });

        if (! $run instanceof AiRun) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['retry' => 'This failed task has changed since its last resumable run. Submit it for approval to start a new run.']);
        }

        $task->update([
            'status' => Task::STATUS_APPROVED,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'rejected_at' => null,
            'pull_request_url' => null,
            'pull_request_number' => null,
        ]);

        $run->update([
            'status' => AiRun::STATUS_QUEUED,
            'last_error' => null,
            'finished_at' => null,
        ]);

        if ($task->externalTaskLink) {
            $this->recordExternalMessage(
                $task,
                'attempt',
                ['task_id' => $task->id, 'status' => Task::STATUS_APPROVED],
                'success',
                null,
            );
        }

        DispatchNextAiRunJob::dispatch($task->id);

        return redirect()
            ->route('tasks.index', ['task' => $task->id])
            ->with('status', 'Task queued for retry.');
    }

    public function createPullRequest(Task $task): RedirectResponse
    {
        $task->loadMissing(['assignee', 'reviewer', 'externalTaskLink', 'latestAiRun', 'project.defaultReviewer']);
        $actor = $this->resolveCurrentUser();

        if ($task->pull_request_url) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['pull_request' => 'This task already has a pull request.']);
        }

        $run = $task->latestAiRun;
        if (! $run) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['pull_request' => 'No AI run is available for this task.']);
        }

        if ($run->branch_name === null || $run->branch_name === '') {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['pull_request' => 'The latest AI run does not have a branch to open.']);
        }

        if (! $actor || trim((string) $actor->email) === '' || ! $this->hasGithubUsername($actor) || ! $this->hasGithubToken($actor)) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors([
                    'pull_request_identity' => 'Complete your profile email, GitHub username, and GitHub token before creating a pull request.',
                ]);
        }

        if ($run->pull_request_url) {
            $task->update([
                'status' => Task::STATUS_PR_CREATED,
                'pull_request_url' => $run->pull_request_url,
                'pull_request_number' => $run->pull_request_number,
            ]);

            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->with('status', 'Task pull request was synced from the latest AI run.');
        }

        try {
            $run->update(['status' => AiRun::STATUS_CREATING_PR]);

            $pr = $this->pullRequestProvider->createPullRequest($task, $run, $actor);

            $task->update([
                'status' => Task::STATUS_PR_CREATED,
                'pull_request_url' => $pr->url,
                'pull_request_number' => $pr->number,
            ]);

            $run->update([
                'status' => AiRun::STATUS_WAITING_FOR_MERGE,
                'pull_request_url' => $pr->url,
                'pull_request_number' => $pr->number,
                'last_error' => null,
            ]);

            $reviewer = $this->resolvePullRequestReviewer($task);

            if ($reviewer) {
                $this->pullRequestProvider->requestReview($pr->url, $reviewer, $actor);
            }

            if ($task->externalTaskLink) {
                $this->recordExternalMessage(
                    $task,
                    'pr_attached',
                    ['task_id' => $task->id, 'run_id' => $run->id, 'pull_request_url' => $pr->url],
                    'success',
                    null,
                );

                $this->externalTaskProvider->attachPullRequest($task->externalTaskLink, $pr->url);
            }

            $this->recordAiRunLog($run, 'info', 'Pull request created manually', [
                'pull_request_url' => $pr->url,
            ]);

            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->with('status', 'Pull request created.');
        } catch (Throwable $exception) {
            $run->update([
                'status' => AiRun::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            $this->recordAiRunLog($run, 'error', 'Manual pull request creation failed', [
                'error' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['pull_request' => $exception->getMessage()]);
        }
    }

    public function refreshPullRequest(Task $task): RedirectResponse
    {
        return $this->refreshPr($task);
    }

    public function refreshPr(Task $task): RedirectResponse
    {
        if (! $task->pull_request_url) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['pull_request' => 'No pull request URL available for this task.']);
        }

        $state = $this->pullRequestProvider->getReviewState($task->pull_request_url);

        if ($state === PullRequestReviewState::MERGED) {
            $task->update(['status' => Task::STATUS_DONE]);

            AiRun::query()
                ->where('task_id', $task->id)
                ->whereIn('status', [AiRun::STATUS_WAITING_FOR_MERGE, AiRun::STATUS_CREATING_PR])
                ->latest('id')
                ->update(['status' => AiRun::STATUS_DONE, 'finished_at' => now()]);
        }

        return redirect()
            ->route('tasks.index', ['task' => $task->id])
            ->with('status', "Pull request state is {$state->value}.");
    }

    private function isApprovedFieldChanged(Task $task, array $data, array $criteria): bool
    {
        $candidate = $task->replicate();
        $candidate->fill(Arr::only($data, Task::APPROVAL_FIELDS));

        foreach (Task::APPROVAL_FIELDS as $field) {
            if ($candidate->getAttribute($field) !== $task->getAttribute($field)) {
                return true;
            }
        }

        return $this->normalizeCriteria($task->acceptance_criteria ?? []) !== $this->normalizeCriteria($criteria);
    }

    private function normalizeCriteria(array $criteria): array
    {
        return Collection::make($criteria)
            ->map(fn (array $criterion): array => [
                'body' => (string) Arr::get($criterion, 'body', ''),
                'checked' => (bool) Arr::get($criterion, 'checked', false),
            ])
            ->filter(fn (array $criterion): bool => $criterion['body'] !== '')
            ->values()
            ->toArray();
    }

    private function serializeTask(Task $task, bool $withDetails = false): array
    {
        $result = [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'deadline' => $task->deadline?->toDateString(),
            'assignee_user_id' => $task->assignee_user_id,
            'reviewer_user_id' => $task->reviewer_user_id,
            'source_input_id' => $task->source_input_id,
            'approved_by_user_id' => $task->approved_by_user_id,
            'approved_at' => $task->approved_at?->toIso8601String(),
            'rejected_at' => $task->rejected_at?->toIso8601String(),
            'project_id' => $task->project_id,
            'pull_request_url' => $task->pull_request_url,
            'pull_request_number' => $task->pull_request_number,
            'assignee' => optional($task->assignee)->only(['id', 'name', 'github_username']),
            'reviewer' => optional($task->reviewer)->only(['id', 'name', 'github_username']),
            'approved_by_user' => optional($task->approvedByUser)->only(['id', 'name', 'github_username']),
            'source_input' => $task->sourceInput ? [
                'id' => $task->sourceInput->id,
                'title' => $task->sourceInput->title,
                'filename' => $task->sourceInput->filename,
                'mime_type' => $task->sourceInput->mime_type,
                'file_size' => $task->sourceInput->file_size,
                'analysis_status' => $task->sourceInput->analysis_status,
                'has_file' => $task->sourceInput->hasStoredFile(),
            ] : null,
            'project' => $task->project ? [
                ...$task->project->asSummary(),
                'default_reviewer' => $task->project->defaultReviewer ? [
                    'id' => $task->project->defaultReviewer->id,
                    'name' => $task->project->defaultReviewer->name,
                    'github_username' => $task->project->defaultReviewer->github_username,
                ] : null,
            ] : null,
            'acceptance_criteria' => $this->normalizeCriteria($task->acceptance_criteria ?? []),
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'latest_ai_run' => optional($task->latestAiRun)?->only(['id', 'status', 'branch_name', 'pull_request_url', 'pull_request_number', 'workflow_state']),
            'external_task_link' => optional($task->externalTaskLink)?->only([
                'id',
                'external_task_provider',
                'external_task_id',
                'external_url',
            ]),
        ];

        if (! $withDetails) {
            return $result;
        }

        $result['ai_runs'] = $task->aiRuns->map(
            fn ($run) => [
                'id' => $run->id,
                'status' => $run->status,
                'branch_name' => $run->branch_name,
                'plan' => $run->plan,
                'test_cases' => $run->test_cases,
                'pull_request_url' => $run->pull_request_url,
                'pull_request_number' => $run->pull_request_number,
                'attempt_count' => $run->attempt_count,
                'review_attempt_count' => $run->review_attempt_count,
                'workflow_state' => $run->workflow_state,
                'last_error' => $run->last_error,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'logs' => $run->logs->map(
                    fn ($log) => [
                        'id' => $log->id,
                        'level' => $log->level,
                        'message' => $log->message,
                        'context' => $log->context,
                        'created_at' => $log->created_at?->toIso8601String(),
                    ],
                )->values(),
            ],
        )->values();

        $result['external_messages'] = $task->externalTaskLink?->messages
            ?->map(
                fn ($message) => $message->only([
                    'id',
                    'type',
                    'status',
                    'error',
                    'sent_at',
                    'payload',
                ]),
            )
            ->values() ?? collect();

        return $result;
    }

    private function recordExternalMessage(Task $task, string $type, array $payload, string $status, ?string $error): void
    {
        $link = $task->externalTaskLink;

        if (! $link) {
            return;
        }

        $link->messages()->create([
            'type' => $type,
            'payload' => $payload,
            'status' => $status,
            'error' => $error,
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordAiRunLog(AiRun $run, string $level, string $message, array $context = []): void
    {
        AiRunLog::create([
            'ai_run_id' => $run->id,
            'level' => $level,
            'message' => $message,
            'context' => array_merge([
                'pull_request_provider' => (string) config('automation.pull_request_provider', 'github'),
            ], $context),
        ]);
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

    private function resolveProjectDefaultReviewerId(mixed $projectId): ?int
    {
        if ($projectId === null || $projectId === '') {
            return null;
        }

        return Project::query()
            ->whereKey($projectId)
            ->value('default_reviewer_user_id');
    }

    private function hasGithubUsername(User $user): bool
    {
        return $user->github_username !== null && $user->github_username !== '';
    }

    private function hasGithubToken(User $user): bool
    {
        return $user->github_token !== null && $user->github_token !== '';
    }

    private function resolveCurrentUser(): ?User
    {
        return Auth::user();
    }
}
