<?php

namespace App\Http\Controllers;

use App\Contracts\ExternalTaskProvider;
use App\Contracts\PullRequestProvider;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Jobs\DispatchNextTaskRunJob;
use App\Models\InputSource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunLog;
use App\Models\User;
use App\Services\PullRequests\PullRequestStatusRefresher;
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
        private readonly PullRequestStatusRefresher $pullRequestStatusRefresher,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('tasks/Index', [
            'tasks' => fn () => $this->taskBoard(),
            'users' => fn () => User::query()->orderBy('name')->get(['id', 'name', 'github_username']),
            'sourceInputs' => fn () => $this->sourceInputs(),
            'projects' => fn () => Project::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => (string) $project->name,
                    'default_reviewer_user_id' => $project->default_reviewer_user_id,
                ]),
            'selectedTask' => fn () => $this->selectedTask($request),
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

    private function taskBoard(): Collection
    {
        return Task::query()
            ->with([
                'assignee:id,name,github_username',
                'reviewer:id,name,github_username',
                'approvedByUser:id,name,github_username',
                'sourceInput:id,title,filename,file_disk,file_path,mime_type,file_size,analysis_status',
                'project:id,name,workspace_path,url,database_name,database_username,database_password,credential_username,credential_password,base_branch,default_reviewer_user_id',
                'project.defaultReviewer:id,name,github_username',
                'latestTaskRun' => fn ($query) => $query->select([
                    'task_runs.id',
                    'task_runs.task_id',
                    'task_runs.status',
                    'task_runs.branch_name',
                    'task_runs.pull_request_url',
                    'task_runs.pull_request_number',
                    'task_runs.review_attempt_count',
                    'task_runs.workflow_state',
                ]),
                'latestPullRequestRun' => fn ($query) => $query->select([
                    'task_runs.id',
                    'task_runs.task_id',
                    'task_runs.status',
                    'task_runs.branch_name',
                    'task_runs.pull_request_url',
                    'task_runs.pull_request_number',
                    'task_runs.review_attempt_count',
                    'task_runs.workflow_state',
                ]),
                'externalTaskLink:id,task_id,external_task_provider,external_task_id,external_url',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Task $task) => $this->serializeTask($task));
    }

    private function sourceInputs(): Collection
    {
        return InputSource::query()
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
            ]);
    }

    private function selectedTask(Request $request): ?array
    {
        if (! $request->integer('task')) {
            return null;
        }

        $task = Task::query()
            ->with([
                'assignee:id,name,github_username',
                'reviewer:id,name,github_username',
                'approvedByUser:id,name,github_username',
                'sourceInput:id,title,filename,file_disk,file_path,mime_type,file_size,analysis_status',
                'project:id,name,workspace_path,url,database_name,database_username,database_password,credential_username,credential_password,base_branch,default_reviewer_user_id',
                'project.defaultReviewer:id,name,github_username',
                'latestTaskRun' => fn ($query) => $query->select([
                    'task_runs.id',
                    'task_runs.task_id',
                    'task_runs.status',
                    'task_runs.branch_name',
                    'task_runs.pull_request_url',
                    'task_runs.pull_request_number',
                    'task_runs.review_attempt_count',
                    'task_runs.workflow_state',
                ]),
                'latestPullRequestRun' => fn ($query) => $query->select([
                    'task_runs.id',
                    'task_runs.task_id',
                    'task_runs.status',
                    'task_runs.branch_name',
                    'task_runs.pull_request_url',
                    'task_runs.pull_request_number',
                    'task_runs.review_attempt_count',
                    'task_runs.workflow_state',
                ]),
                'taskRuns:id,task_id,status,plan,branch_name,pull_request_url,pull_request_number,attempt_count,review_attempt_count,workflow_state,last_error,started_at,finished_at,analyze_source_model,analyze_source_reasoning_effort,plan_model,plan_reasoning_effort,implement_model,implement_reasoning_effort,review_model,review_reasoning_effort,commit_message_model,commit_message_reasoning_effort,updated_at',
                'taskRuns.logs' => fn ($query) => $query
                    ->select(['id', 'task_run_id', 'level', 'message', 'context', 'created_at'])
                    ->orderByDesc('created_at')
                    ->orderByDesc('id'),
                'externalTaskLink.messages:id,external_task_link_id,type,status,error,sent_at,payload',
            ])
            ->find($request->integer('task'));

        return $task instanceof Task
            ? $this->serializeTask($task, true)
            : null;
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

        $approvalFieldsChanged = $this->isApprovedFieldChanged($task, $data);
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

        DispatchNextTaskRunJob::dispatch();

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

        $this->stopActiveTaskRuns(
            $task->taskRuns()->whereIn('status', TaskRun::ACTIVE_STATUSES)->get(),
            TaskRun::STATUS_REJECTED,
            'Task rejected.',
            $actor->id,
        );

        if ($task->externalTaskLink) {
            $this->recordExternalMessage(
                $task,
                'update',
                ['task_id' => $task->id, 'status' => Task::STATUS_REJECTED],
                'success',
                null,
            );
        }

        DispatchNextTaskRunJob::dispatch();

        return redirect()
            ->route('tasks.index')
            ->with('status', 'Task rejected.');
    }

    public function stop(Task $task): RedirectResponse
    {
        $actor = $this->resolveCurrentUser();

        if ($actor === null) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['actor' => 'No actor available to record the stop request.']);
        }

        $activeRuns = $task->taskRuns()
            ->whereIn('status', TaskRun::ACTIVE_STATUSES)
            ->get();

        if ($activeRuns->isEmpty()) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['stop' => 'No active task run is available to stop.']);
        }

        $task->update([
            'status' => Task::STATUS_FAILED,
            'rejected_at' => null,
        ]);

        $this->stopActiveTaskRuns(
            $activeRuns,
            TaskRun::STATUS_FAILED,
            'Task run stopped by user.',
            $actor->id,
        );

        DispatchNextTaskRunJob::dispatch();

        return redirect()
            ->route('tasks.index', ['task' => $task->id])
            ->with('status', 'Task run stop requested.');
    }

    public function retry(Task $task): RedirectResponse
    {
        $task->loadMissing('taskRuns');

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

        $run = $task->taskRuns()
            ->where('status', TaskRun::STATUS_FAILED)
            ->latest('id')
            ->get()
            ->first(function (TaskRun $candidate) use ($task): bool {
                return $candidate->requestHash() !== null && $candidate->hasMatchingRequestHash($task);
            });

        if (! $run instanceof TaskRun) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['retry' => 'This failed task has changed since its last resumable run. Submit it for approval to start a new run.']);
        }

        $task->update([
            'status' => Task::STATUS_APPROVED,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'rejected_at' => null,
        ]);

        $run->update([
            'status' => TaskRun::STATUS_QUEUED,
            'last_error' => null,
            'finished_at' => null,
        ]);
        $run->clearStopRequest();

        if ($task->externalTaskLink) {
            $this->recordExternalMessage(
                $task,
                'attempt',
                ['task_id' => $task->id, 'status' => Task::STATUS_APPROVED],
                'success',
                null,
            );
        }

        DispatchNextTaskRunJob::dispatch($task->id);

        return redirect()
            ->route('tasks.index', ['task' => $task->id])
            ->with('status', 'Task queued for retry.');
    }

    public function rerunWorkflow(Task $task): RedirectResponse
    {
        $task->loadMissing('project');

        if (! in_array($task->status, [Task::STATUS_FAILED, Task::STATUS_REJECTED], true)) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['status' => 'Only failed or rejected tasks can be rerun.']);
        }

        if ($task->project_id === null || ! $task->project) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['project' => 'Assign a project before rerunning this task.']);
        }

        $actor = $this->resolveCurrentUser();

        if ($actor === null) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['actor' => 'No actor available to record rerun approval.']);
        }

        $task->update([
            'status' => Task::STATUS_APPROVED,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'rejected_at' => null,
        ]);

        $baseBranch = trim((string) $task->project->base_branch) !== ''
            ? (string) $task->project->base_branch
            : 'main';

        $run = $task->taskRuns()->create([
            'status' => TaskRun::STATUS_QUEUED,
            'attempt_count' => 0,
            'review_attempt_count' => 0,
            'branch_name' => 'pending',
            'workspace_path' => (string) $task->project->workspace_path,
            'base_branch' => $baseBranch,
        ]);
        $run->initializeWorkflowState($task);

        if ($task->externalTaskLink) {
            $this->recordExternalMessage(
                $task,
                'attempt',
                ['task_id' => $task->id, 'status' => Task::STATUS_APPROVED, 'run_id' => $run->id, 'mode' => 'rerun_workflow'],
                'success',
                null,
            );
        }

        DispatchNextTaskRunJob::dispatch($task->id);

        return redirect()
            ->route('tasks.index', ['task' => $task->id])
            ->with('status', 'Task workflow queued for rerun.');
    }

    public function createPullRequest(Task $task): RedirectResponse
    {
        $task->loadMissing(['assignee', 'reviewer', 'externalTaskLink', 'latestTaskRun', 'latestPullRequestRun', 'project.defaultReviewer']);
        $actor = $this->resolveCurrentUser();

        if ($task->latestPullRequestRun) {
            $task->update(['status' => Task::STATUS_PR_CREATED]);

            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['pull_request' => 'This task already has a pull request.']);
        }

        $run = $task->latestTaskRun;
        if (! $run) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['pull_request' => 'No task run is available for this task.']);
        }

        if ($run->branch_name === null || $run->branch_name === '') {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['pull_request' => 'The latest task run does not have a branch to open.']);
        }

        if (! $actor || trim((string) $actor->email) === '' || ! $this->hasGithubUsername($actor) || ! $this->hasGithubToken($actor)) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors([
                    'pull_request_identity' => 'Complete your profile email, GitHub username, and GitHub token before creating a pull request.',
                ]);
        }

        if ($run->pull_request_url) {
            $task->update(['status' => Task::STATUS_PR_CREATED]);

            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->with('status', 'Task pull request was synced from the latest task run.');
        }

        try {
            $run->update(['status' => TaskRun::STATUS_CREATING_PR]);

            $pr = $this->pullRequestProvider->createPullRequest($task, $run, $actor);

            $task->update(['status' => Task::STATUS_PR_CREATED]);

            $run->update([
                'status' => TaskRun::STATUS_WAITING_FOR_MERGE,
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

            $this->recordTaskRunLog($run, 'info', 'Pull request created manually', [
                'pull_request_url' => $pr->url,
            ]);

            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->with('status', 'Pull request created.');
        } catch (Throwable $exception) {
            $run->update([
                'status' => TaskRun::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            $this->recordTaskRunLog($run, 'error', 'Manual pull request creation failed', [
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
        try {
            $state = $this->pullRequestStatusRefresher->refresh($task);
        } catch (Throwable $exception) {
            return redirect()
                ->route('tasks.index', ['task' => $task->id])
                ->withErrors(['pull_request' => $exception->getMessage()]);
        }

        return redirect()
            ->route('tasks.index', ['task' => $task->id])
            ->with('status', "Pull request state is {$state->value}.");
    }

    private function isApprovedFieldChanged(Task $task, array $data): bool
    {
        $candidate = $task->replicate();
        $candidate->fill(Arr::only($data, Task::APPROVAL_FIELDS));

        foreach (Task::APPROVAL_FIELDS as $field) {
            if ($candidate->getAttribute($field) !== $task->getAttribute($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, TaskRun>  $runs
     */
    private function stopActiveTaskRuns(
        Collection $runs,
        string $finalRunStatus,
        string $message,
        int $requestedByUserId,
    ): void {
        $runs->each(function (TaskRun $run) use ($finalRunStatus, $message, $requestedByUserId): void {
            if ($run->isExecutionActive()) {
                $run->requestStop($requestedByUserId, $message);

                $this->recordTaskRunLog($run, 'warning', 'Task run stop requested', [
                    'requested_by_user_id' => $requestedByUserId,
                    'final_status' => $finalRunStatus,
                    'message' => $message,
                ]);

                return;
            }

            $run->update([
                'status' => $finalRunStatus,
                'last_error' => $message,
                'finished_at' => now(),
            ]);
            $run->requestStop($requestedByUserId, $message);

            $this->recordTaskRunLog($run, 'warning', 'Task run stopped', [
                'requested_by_user_id' => $requestedByUserId,
                'final_status' => $finalRunStatus,
                'message' => $message,
            ]);
        });
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
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'latest_task_run' => optional($task->latestTaskRun)?->only(['id', 'status', 'branch_name', 'pull_request_url', 'pull_request_number', 'workflow_state']),
            'latest_pull_request_run' => optional($task->latestPullRequestRun)?->only(['id', 'status', 'branch_name', 'pull_request_url', 'pull_request_number', 'workflow_state']),
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

        $result['task_runs'] = $task->taskRuns->map(
            fn ($run) => [
                'id' => $run->id,
                'status' => $run->status,
                'branch_name' => $run->branch_name,
                'plan' => $run->plan,
                'pull_request_url' => $run->pull_request_url,
                'pull_request_number' => $run->pull_request_number,
                'attempt_count' => $run->attempt_count,
                'review_attempt_count' => $run->review_attempt_count,
                'automation_models' => [
                    'analyze_source_model' => $run->analyze_source_model,
                    'analyze_source_reasoning_effort' => $run->analyze_source_reasoning_effort,
                    'plan_model' => $run->plan_model,
                    'plan_reasoning_effort' => $run->plan_reasoning_effort,
                    'implement_model' => $run->implement_model,
                    'implement_reasoning_effort' => $run->implement_reasoning_effort,
                    'review_model' => $run->review_model,
                    'review_reasoning_effort' => $run->review_reasoning_effort,
                    'commit_message_model' => $run->commit_message_model,
                    'commit_message_reasoning_effort' => $run->commit_message_reasoning_effort,
                ],
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
    private function recordTaskRunLog(TaskRun $run, string $level, string $message, array $context = []): void
    {
        TaskRunLog::create([
            'task_run_id' => $run->id,
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
