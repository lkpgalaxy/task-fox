<?php

namespace App\Http\Controllers;

use App\Contracts\ExternalTaskProvider;
use App\Contracts\PullRequestProvider;
use App\Enums\PullRequestReviewState;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Jobs\DispatchNextAiRunJob;
use App\Models\AiRun;
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
                'approvedByUser:id,name,github_username',
                'sourceInput:id,title,analysis_status',
                'project:id,name,workspace_path,url',
                'latestAiRun' => fn ($query) => $query->select([
                    'ai_runs.id',
                    'ai_runs.task_id',
                    'ai_runs.status',
                    'ai_runs.branch_name',
                    'ai_runs.pull_request_url',
                    'ai_runs.pull_request_number',
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
                    'approvedByUser:id,name,github_username',
                    'sourceInput:id,title,analysis_status',
                    'project:id,name,workspace_path,url',
                    'aiRuns:id,task_id,status,branch_name,pull_request_url,pull_request_number,attempt_count,last_error,started_at,finished_at,updated_at',
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

        $shouldResetApproval = $task->status === Task::STATUS_APPROVED
            && ($this->isApprovedFieldChanged($task, $data, $criteria));

        $task->fill(
            Arr::only($data, [
                'title',
                'description',
                'priority',
                'deadline',
                'assignee_user_id',
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
            'source_input_id' => $task->source_input_id,
            'approved_by_user_id' => $task->approved_by_user_id,
            'approved_at' => $task->approved_at?->toIso8601String(),
            'rejected_at' => $task->rejected_at?->toIso8601String(),
            'project_id' => $task->project_id,
            'pull_request_url' => $task->pull_request_url,
            'pull_request_number' => $task->pull_request_number,
            'assignee' => optional($task->assignee)->only(['id', 'name', 'github_username']),
            'approved_by_user' => optional($task->approvedByUser)->only(['id', 'name', 'github_username']),
            'source_input' => optional($task->sourceInput)->only(['id', 'title', 'analysis_status']),
            'project' => optional($task->project)->only(['id', 'name', 'workspace_path', 'url']),
            'acceptance_criteria' => $this->normalizeCriteria($task->acceptance_criteria ?? []),
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'latest_ai_run' => optional($task->latestAiRun)?->only(['id', 'status', 'branch_name', 'pull_request_url', 'pull_request_number']),
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

    private function resolveCurrentUser(): ?User
    {
        return Auth::user() ?? User::query()->orderBy('id')->first();
    }
}
