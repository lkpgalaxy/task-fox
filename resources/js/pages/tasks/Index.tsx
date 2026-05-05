import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { AppShell } from '@/components/app-shell';
import {
    ActionLink,
    Alert,
    Badge,
    Button,
    Field,
    Input,
    Modal,
    Panel,
    Select,
    Textarea,
} from '@/components/ui';
import { UploadSourceAction } from '@/components/upload-source-modal';
import {
    checkpointLabel,
    completedWorkflowCheckpointCount,
    shortWorkflowHash,
    WorkflowTimeline,
    workflowCurrentCheckpoint,
} from '@/components/workflow-timeline';
import type { WorkflowCheckpoint } from '@/components/workflow-timeline';
import { cn, formatDisplayDate, formatDisplayDateTime } from '@/lib/utils';
import inputSources from '@/routes/input-sources';
import profile from '@/routes/profile';
import tasks from '@/routes/tasks';
import type { Auth } from '@/types';

type Criterion = {
    body: string;
    checked: boolean;
};

type User = {
    id: number;
    name: string;
    github_username: string | null;
};

type SourceInput = {
    id: number;
    title: string;
    filename?: string | null;
    mime_type?: string | null;
    file_size?: number | null;
    analysis_status: string;
    has_file?: boolean;
};

type TaskRelation = {
    id: number;
    title: string;
    name: string;
    github_username: string | null;
};

type RunLog = {
    id: number;
    level: string;
    message: string;
    context: Record<string, unknown> | null;
    created_at: string | null;
};

type WorkflowState = {
    request_hash?: string;
    checkpoints?: WorkflowCheckpoint[];
};

type TaskRunRecord = {
    id: number;
    status: string;
    branch_name: string | null;
    plan: string | null;
    pull_request_url: string | null;
    pull_request_number: number | null;
    attempt_count: number;
    review_attempt_count: number;
    automation_models: {
        analyze_source_model: string | null;
        analyze_source_reasoning_effort: string | null;
        plan_model: string | null;
        plan_reasoning_effort: string | null;
        implement_model: string | null;
        implement_reasoning_effort: string | null;
        review_model: string | null;
        review_reasoning_effort: string | null;
        commit_message_model: string | null;
        commit_message_reasoning_effort: string | null;
    };
    workflow_state: WorkflowState | null;
    last_error: string | null;
    started_at: string | null;
    finished_at: string | null;
    logs: RunLog[];
};

type TaskProject = {
    id: number;
    name: string;
    workspace_path: string;
    url: string | null;
    database_name?: string | null;
    database_username?: string | null;
    base_branch?: string | null;
    default_reviewer_user_id: number | null;
    default_reviewer?: User | null;
    has_database_password?: boolean;
    has_credential_password?: boolean;
    has_credential_username?: boolean;
    has_database_username?: boolean;
};

type TaskRecord = {
    id: number;
    title: string;
    description: string;
    status: string;
    priority: string;
    deadline: string | null;
    project_id: number | null;
    assignee_user_id: number | null;
    reviewer_user_id: number | null;
    source_input_id: number | null;
    approved_by_user_id: number | null;
    approved_at: string | null;
    rejected_at: string | null;
    assignee: TaskRelation | null;
    reviewer: TaskRelation | null;
    approved_by_user: TaskRelation | null;
    project?: TaskProject | null;
    source_input: SourceInput | null;
    acceptance_criteria: Criterion[];
    created_at: string | null;
    updated_at: string | null;
    latest_task_run: {
        id: number;
        status: string;
        branch_name: string | null;
        pull_request_url: string | null;
        pull_request_number: number | null;
        workflow_state: WorkflowState | null;
    } | null;
    latest_pull_request_run: {
        id: number;
        status: string;
        branch_name: string | null;
        pull_request_url: string | null;
        pull_request_number: number | null;
        workflow_state: WorkflowState | null;
    } | null;
    external_task_link?: {
        id: number;
        external_task_provider: string;
        external_task_id: string;
        external_url: string;
    } | null;
    task_runs?: TaskRunRecord[];
    external_messages?: {
        id: number;
        type: string;
        status: string;
        error: string | null;
        sent_at: string | null;
        payload: Record<string, unknown> | null;
    }[];
};

type ProjectSummary = {
    id: number;
    name: string;
    default_reviewer_user_id: number | null;
};

type IndexPageProps = {
    auth: Auth;
    tasks: TaskRecord[];
    users: User[];
    sourceInputs: SourceInput[];
    projects: ProjectSummary[];
    selectedTask: TaskRecord | null;
    taskStatuses: string[];
    priorities: string[];
    errors?: Record<string, string | string[]>;
};

type TaskFormData = {
    title: string;
    description: string;
    priority: string;
    deadline: string;
    assignee_user_id: string;
    reviewer_user_id: string;
    project_id: string;
    source_input_id: string;
    acceptance_criteria: Criterion[];
};

const emptyCriterion = (): Criterion => ({ body: '', checked: false });

const taskStatusLabel = (status: string) => status.replaceAll('_', ' ');

const taskPriorityLabel = (priority: string) => priority.toUpperCase();

const projectRequiredMessage = 'Assign a project before approving this task.';

const formatUser = (
    user: Pick<User, 'name' | 'github_username'> | null | undefined,
): string | null => {
    if (!user) {
        return null;
    }

    return user.github_username
        ? `${user.name} (@${user.github_username})`
        : user.name;
};

const formatReviewer = (
    reviewer: Pick<User, 'name' | 'github_username'> | null | undefined,
    defaultReviewer: Pick<User, 'name' | 'github_username'> | null | undefined,
): string => {
    const reviewerName = formatUser(reviewer);

    if (reviewerName) {
        return reviewerName;
    }

    const defaultReviewerName = formatUser(defaultReviewer);

    return defaultReviewerName
        ? `${defaultReviewerName} (project default)`
        : 'No reviewer';
};

const formatError = (error: string | string[] | undefined): string | null => {
    if (Array.isArray(error)) {
        return error.join(', ');
    }

    return error ?? null;
};

const sanitizeCriteria = (criteria: Criterion[]): Criterion[] => {
    const next = criteria
        .map((item) => ({ ...item, body: item.body.trim() }))
        .filter((item) => item.body !== '');

    return next.length > 0
        ? next
        : [{ ...emptyCriterion(), body: 'No acceptance criteria provided.' }];
};

const setStatus = (value: boolean | undefined): string =>
    value ? 'set' : 'not set';

const defaultReviewerForProject = (
    projects: ProjectSummary[],
    projectId: string,
): string => {
    if (projectId === '') {
        return '';
    }

    return (
        projects
            .find((project) => project.id.toString() === projectId)
            ?.default_reviewer_user_id?.toString() ?? ''
    );
};

export default function TasksIndex() {
    const page = usePage<IndexPageProps>();
    const {
        tasks: boardTasks,
        users,
        sourceInputs,
        projects: projectOptions,
        selectedTask,
        taskStatuses,
        priorities,
        errors,
        auth,
    } = page.props;

    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showEditModal, setShowEditModal] = useState(false);
    const [showPrIdentityModal, setShowPrIdentityModal] = useState(false);
    const [showRerunWorkflowModal, setShowRerunWorkflowModal] =
        useState(false);
    const [rerunWorkflowTaskId, setRerunWorkflowTaskId] = useState<
        number | null
    >(null);
    const [dismissedPrIdentityError, setDismissedPrIdentityError] =
        useState(false);
    const [editingTask, setEditingTask] = useState<TaskRecord | null>(null);

    const defaultPriority = priorities.includes('medium')
        ? 'medium'
        : (priorities[0] ?? 'medium');

    const createForm = useForm<TaskFormData>({
        title: '',
        description: '',
        priority: defaultPriority,
        deadline: '',
        project_id: '',
        assignee_user_id: '',
        reviewer_user_id: '',
        source_input_id: '',
        acceptance_criteria: [emptyCriterion()],
    });

    const editForm = useForm<TaskFormData>({
        title: '',
        description: '',
        priority: defaultPriority,
        deadline: '',
        project_id: '',
        assignee_user_id: '',
        reviewer_user_id: '',
        source_input_id: '',
        acceptance_criteria: [emptyCriterion()],
    });

    const groupedTasks = useMemo(() => {
        const grouped: Record<string, TaskRecord[]> = {};

        taskStatuses.forEach((status) => {
            grouped[status] = [];
        });

        boardTasks.forEach((task) => {
            grouped[task.status] = [...(grouped[task.status] ?? []), task];
        });

        return grouped;
    }, [boardTasks, taskStatuses]);

    const resetCreateForm = () => {
        createForm.setData({
            title: '',
            description: '',
            priority: defaultPriority,
            deadline: '',
            project_id: '',
            assignee_user_id: '',
            reviewer_user_id: '',
            source_input_id: '',
            acceptance_criteria: [emptyCriterion()],
        });
        createForm.clearErrors();
        createForm.setDefaults({
            title: '',
            description: '',
            priority: defaultPriority,
            deadline: '',
            project_id: '',
            assignee_user_id: '',
            reviewer_user_id: '',
            source_input_id: '',
            acceptance_criteria: [emptyCriterion()],
        });
    };

    const openCreateModal = () => {
        resetCreateForm();
        setShowCreateModal(true);
    };

    const openTaskDetails = (taskId: number) => {
        router.get(
            tasks.index.url({ query: { task: taskId } }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    const closeTaskDetails = () => {
        router.get(
            tasks.index.url(),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    const startEdit = (task: TaskRecord) => {
        setEditingTask(task);
        editForm.setData({
            title: task.title,
            description: task.description,
            priority: task.priority,
            deadline: task.deadline ?? '',
            project_id: task.project_id ? String(task.project_id) : '',
            assignee_user_id: task.assignee_user_id
                ? String(task.assignee_user_id)
                : '',
            reviewer_user_id: task.reviewer_user_id
                ? String(task.reviewer_user_id)
                : (task.project?.default_reviewer_user_id?.toString() ?? ''),
            source_input_id: task.source_input_id
                ? String(task.source_input_id)
                : '',
            acceptance_criteria:
                task.acceptance_criteria.length > 0
                    ? task.acceptance_criteria
                    : [emptyCriterion()],
        });
        editForm.clearErrors();
        setShowEditModal(true);
    };

    const closeEditModal = () => {
        setShowEditModal(false);
        setEditingTask(null);
        editForm.clearErrors();
        editForm.reset();
    };

    const addCriterion = (
        setter: (formData: TaskFormData) => void,
        get: TaskFormData,
    ) => {
        setter({
            ...get,
            acceptance_criteria: [...get.acceptance_criteria, emptyCriterion()],
        });
    };

    const removeCriterion = (
        setter: (formData: TaskFormData) => void,
        get: TaskFormData,
        index: number,
    ) => {
        if (get.acceptance_criteria.length <= 1) {
            setter({ ...get, acceptance_criteria: [emptyCriterion()] });

            return;
        }

        const next = [...get.acceptance_criteria];
        next.splice(index, 1);
        setter({ ...get, acceptance_criteria: next });
    };

    const updateCriterion = (
        setter: (formData: TaskFormData) => void,
        get: TaskFormData,
        index: number,
        patch: Partial<Criterion>,
    ) => {
        const next = [...get.acceptance_criteria];
        const criterion = next[index];

        if (!criterion) {
            return;
        }

        next[index] = { ...criterion, ...patch };
        setter({ ...get, acceptance_criteria: next });
    };

    const submitCreate = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        createForm.setData({
            ...createForm.data,
            acceptance_criteria: sanitizeCriteria(
                createForm.data.acceptance_criteria,
            ),
        });
        createForm.post(tasks.store.url(), {
            onSuccess: () => {
                setShowCreateModal(false);
                createForm.reset();
            },
        });
    };

    const submitEdit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (editingTask === null) {
            return;
        }

        editForm.setData({
            ...editForm.data,
            acceptance_criteria: sanitizeCriteria(
                editForm.data.acceptance_criteria,
            ),
        });
        editForm.patch(tasks.update.url(editingTask.id), {
            onSuccess: closeEditModal,
        });
    };

    const submitApprove = (taskId: number) => {
        router.post(
            tasks.approve.url(taskId),
            {},
            {
                preserveScroll: true,
                onSuccess: closeTaskDetails,
            },
        );
    };

    const submitForApproval = (taskId: number) => {
        router.post(
            tasks.submitForApproval.url(taskId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => openTaskDetails(taskId),
            },
        );
    };

    const submitReject = (taskId: number) => {
        router.post(
            tasks.reject.url(taskId),
            {},
            {
                preserveScroll: true,
                onSuccess: closeTaskDetails,
            },
        );
    };

    const submitRetry = (taskId: number) => {
        router.post(
            tasks.retry.url(taskId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => openTaskDetails(taskId),
            },
        );
    };

    const openRerunWorkflowModal = (taskId: number) => {
        setRerunWorkflowTaskId(taskId);
        setShowRerunWorkflowModal(true);
    };

    const closeRerunWorkflowModal = () => {
        setShowRerunWorkflowModal(false);
        setRerunWorkflowTaskId(null);
    };

    const submitRerunWorkflow = () => {
        if (rerunWorkflowTaskId === null) {
            return;
        }

        router.post(
            tasks.rerunWorkflow.url(rerunWorkflowTaskId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    openTaskDetails(rerunWorkflowTaskId);
                    closeRerunWorkflowModal();
                },
            },
        );
    };

    const submitCreatePr = (taskId: number) => {
        if (
            !auth.user?.email ||
            auth.user.email.trim() === '' ||
            !auth.user.github_username ||
            auth.user.github_username.trim() === '' ||
            !auth.user.has_github_token
        ) {
            setDismissedPrIdentityError(false);
            setShowPrIdentityModal(true);

            return;
        }

        router.post(
            tasks.createPr.url(taskId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => openTaskDetails(taskId),
            },
        );
    };
    const hasPrIdentityError = Boolean(errors?.pull_request_identity);
    const prIdentityModalOpen =
        showPrIdentityModal ||
        (hasPrIdentityError && !dismissedPrIdentityError);
    const pullRequestError = formatError(errors?.pull_request);
    const toasts = useMemo(
        () =>
            pullRequestError
                ? [
                      {
                          id: `pull-request-${pullRequestError}`,
                          tone: 'danger' as const,
                          message: pullRequestError,
                      },
                  ]
                : [],
        [pullRequestError],
    );
    const closePrIdentityModal = () => {
        setShowPrIdentityModal(false);
        setDismissedPrIdentityError(true);
    };

    const submitRefreshPr = (taskId: number) => {
        router.post(
            tasks.refreshPr.url(taskId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (selectedTask) {
                        openTaskDetails(taskId);
                    }
                },
            },
        );
    };

    return (
        <AppShell
            title="Task Board"
            description="Analyze input, shape tasks, and track coding-agent execution across the workspace."
            width="full"
            showHeaderText={false}
            toasts={toasts}
            actions={
                <>
                    <UploadSourceAction />
                    <Button
                        type="button"
                        variant="primary"
                        onClick={openCreateModal}
                    >
                        Create task
                    </Button>
                </>
            }
        >
            <Head title="Tasks" />

            <div className="flex h-[calc(100vh-11.5rem)] min-h-[420px] flex-col gap-4 overflow-hidden">
                {errors?.status ? (
                    <Alert tone="danger">{formatError(errors.status)}</Alert>
                ) : null}

                <section className="min-h-0 flex-1 overflow-x-auto overscroll-x-contain pb-3">
                    <div className="grid h-full min-w-max auto-cols-[minmax(320px,min(420px,calc(100vw-3rem)))] grid-flow-col gap-3 pr-4 sm:auto-cols-[minmax(340px,420px)]">
                        {taskStatuses.map((status) => {
                            const tasksInStatus = groupedTasks[status] ?? [];

                            return (
                                <article
                                    key={status}
                                    className="flex min-h-0 flex-col rounded-lg border border-hairline bg-surface-1"
                                >
                                    <div className="flex items-center justify-between gap-2 border-b border-hairline px-3 py-2.5">
                                        <h2 className="truncate text-xs font-semibold tracking-[0.04em] text-ink-muted uppercase">
                                            {taskStatusLabel(status)}
                                        </h2>
                                        <span className="rounded-md border border-hairline-strong bg-surface-3 px-1.5 py-0.5 text-xs text-ink-subtle">
                                            {tasksInStatus.length}
                                        </span>
                                    </div>
                                    <div className="min-h-0 flex-1 space-y-2 overflow-y-auto p-2">
                                        {tasksInStatus.map((task) => (
                                            <TaskCard
                                                key={task.id}
                                                task={task}
                                                selected={
                                                    selectedTask?.id === task.id
                                                }
                                                onOpen={() =>
                                                    openTaskDetails(task.id)
                                                }
                                            />
                                        ))}
                                        {tasksInStatus.length === 0 ? (
                                            <div className="rounded-md border border-dashed border-hairline-strong bg-surface-2/60 px-3 py-8 text-center text-sm text-ink-tertiary">
                                                No tasks
                                            </div>
                                        ) : null}
                                    </div>
                                </article>
                            );
                        })}
                    </div>
                </section>
            </div>

            <TaskEditorModal
                show={showCreateModal}
                title="Create task"
                processing={createForm.processing}
                submitLabel="Create"
                processingLabel="Creating..."
                onClose={() => setShowCreateModal(false)}
                onSubmit={submitCreate}
            >
                <TaskFormFields
                    form={createForm}
                    users={users}
                    sourceInputs={sourceInputs}
                    projects={projectOptions}
                    priorities={priorities}
                    showSourceInput={false}
                    onAddCriterion={() =>
                        addCriterion(createForm.setData, createForm.data)
                    }
                    onRemoveCriterion={(index) =>
                        removeCriterion(
                            createForm.setData,
                            createForm.data,
                            index,
                        )
                    }
                    onUpdateCriterion={(index, patch) =>
                        updateCriterion(
                            createForm.setData,
                            createForm.data,
                            index,
                            patch,
                        )
                    }
                />
            </TaskEditorModal>

            <TaskEditorModal
                show={showEditModal}
                title={`Edit task #${editingTask?.id ?? ''}`}
                processing={editForm.processing}
                submitLabel="Save"
                processingLabel="Saving..."
                onClose={closeEditModal}
                onSubmit={submitEdit}
            >
                <TaskFormFields
                    form={editForm}
                    users={users}
                    sourceInputs={sourceInputs}
                    projects={projectOptions}
                    priorities={priorities}
                    onAddCriterion={() =>
                        addCriterion(editForm.setData, editForm.data)
                    }
                    onRemoveCriterion={(index) =>
                        removeCriterion(editForm.setData, editForm.data, index)
                    }
                    onUpdateCriterion={(index, patch) =>
                        updateCriterion(
                            editForm.setData,
                            editForm.data,
                            index,
                            patch,
                        )
                    }
                />
            </TaskEditorModal>

            <Modal
                show={selectedTask !== null && !showEditModal}
                onClose={closeTaskDetails}
                title={selectedTask ? selectedTask.title : 'Task details'}
            >
                {selectedTask ? (
                    <TaskDetails
                        task={selectedTask}
                        onEdit={() => startEdit(selectedTask)}
                        onSubmitForApproval={() =>
                            submitForApproval(selectedTask.id)
                        }
                        onApprove={() => submitApprove(selectedTask.id)}
                        onReject={() => submitReject(selectedTask.id)}
                        onRetry={() => submitRetry(selectedTask.id)}
                        onRerunWorkflow={() =>
                            openRerunWorkflowModal(selectedTask.id)
                        }
                        onCreatePr={() => submitCreatePr(selectedTask.id)}
                        onRefreshPr={() => submitRefreshPr(selectedTask.id)}
                    />
                ) : null}
            </Modal>

            <Modal
                show={showRerunWorkflowModal}
                onClose={closeRerunWorkflowModal}
                title="Rerun workflow"
                size="md"
            >
                <div className="grid gap-4">
                    <Alert tone="danger">
                        Rerunning the workflow will discard uncommitted and
                        untracked files in the project workspace before pulling
                        the latest base branch changes.
                    </Alert>
                    <p className="text-sm leading-6 text-ink-muted">
                        Previous failed runs and logs stay available. The new
                        run starts from a freshly prepared task branch.
                    </p>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={closeRerunWorkflowModal}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            onClick={submitRerunWorkflow}
                        >
                            Rerun workflow
                        </Button>
                    </div>
                </div>
            </Modal>

            <Modal
                show={prIdentityModalOpen}
                onClose={closePrIdentityModal}
                title="Complete profile before creating a PR"
                size="md"
            >
                <div className="grid gap-4">
                    <p className="text-sm leading-6 text-ink-muted">
                        Task Fox needs your profile email and GitHub username to
                        author the commit, plus a saved GitHub token to open the
                        pull request.
                    </p>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={closePrIdentityModal}
                        >
                            Close
                        </Button>
                        <ActionLink variant="primary" href={profile.edit.url()}>
                            Complete profile
                        </ActionLink>
                    </div>
                </div>
            </Modal>
        </AppShell>
    );
}

function TaskCard({
    task,
    selected,
    onOpen,
}: {
    task: TaskRecord;
    selected: boolean;
    onOpen: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className={cn(
                'w-full cursor-pointer rounded-md border bg-surface-2 p-3 text-left transition hover:border-hairline-strong hover:bg-surface-3',
                selected
                    ? 'border-primary/70 ring-2 ring-primary-focus/25'
                    : 'border-hairline',
            )}
        >
            <p className="min-w-0 text-sm leading-5 font-medium text-ink">
                {task.title}
            </p>
            <p className="mt-2 line-clamp-2 text-xs leading-5 text-ink-subtle">
                {task.description || 'No description'}
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-ink-tertiary">
                <Badge value={task.priority}>
                    {taskPriorityLabel(task.priority)}
                </Badge>
                {task.project ? <Badge>{task.project.name}</Badge> : null}
                {task.latest_task_run ? (
                    <Badge value={task.latest_task_run.status}>
                        run {taskStatusLabel(task.latest_task_run.status)}
                    </Badge>
                ) : null}
            </div>
        </button>
    );
}

function TaskDetails({
    task,
    onEdit,
    onSubmitForApproval,
    onApprove,
    onReject,
    onRetry,
    onRerunWorkflow,
    onCreatePr,
    onRefreshPr,
}: {
    task: TaskRecord;
    onEdit: () => void;
    onSubmitForApproval: () => void;
    onApprove: () => void;
    onReject: () => void;
    onRetry: () => void;
    onRerunWorkflow: () => void;
    onCreatePr: () => void;
    onRefreshPr: () => void;
}) {
    const latestPullRequestRun = task.latest_pull_request_run;
    const canAttemptPrCreation =
        latestPullRequestRun === null && task.latest_task_run !== null;
    const createPrDisabled =
        task.latest_task_run?.branch_name === null ||
        task.latest_task_run?.branch_name === undefined ||
        task.latest_task_run.branch_name === '';
    const taskRuns = [...(task.task_runs ?? [])].sort((first, second) => {
        return second.id - first.id;
    });
    const latestRun = taskRuns[0] ?? null;
    const latestWorkflow = latestRun?.workflow_state ?? null;
    const latestCheckpoints = latestWorkflow?.checkpoints ?? [];
    const currentCheckpoint = workflowCurrentCheckpoint(latestCheckpoints);
    const completedCheckpoints =
        completedWorkflowCheckpointCount(latestCheckpoints);
    const failedCheckpoint =
        latestCheckpoints.find(
            (checkpoint) => checkpoint.status === 'failed',
        ) ?? null;
    const [showProjectModal, setShowProjectModal] = useState(false);

    return (
        <div className="space-y-5">
            <div className="space-y-4 border-b border-hairline pb-5">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge value={task.status}>
                        {taskStatusLabel(task.status)}
                    </Badge>
                    <Badge value={task.priority}>
                        {taskPriorityLabel(task.priority)} priority
                    </Badge>
                    {task.latest_task_run ? (
                        <Badge value={task.latest_task_run.status}>
                            Run {taskStatusLabel(task.latest_task_run.status)}
                        </Badge>
                    ) : null}
                </div>
                <div>
                    <p className="text-sm leading-6 text-ink-muted">
                        {task.description}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button type="button" onClick={onEdit}>
                        Edit
                    </Button>
                    {task.status === 'draft' ? (
                        <Button
                            type="button"
                            variant="primary"
                            onClick={onSubmitForApproval}
                        >
                            Submit for approval
                        </Button>
                    ) : null}
                    {task.status === 'pending_approval' ? (
                        <Button
                            type="button"
                            variant="success"
                            title={
                                task.project_id === null
                                    ? projectRequiredMessage
                                    : undefined
                            }
                            disabled={task.project_id === null}
                            onClick={onApprove}
                        >
                            Approve
                        </Button>
                    ) : null}
                    {task.status !== 'done' ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={onReject}
                        >
                            Reject
                        </Button>
                    ) : null}
                    {task.status === 'failed' ? (
                        <Button
                            type="button"
                            variant="success"
                            title={
                                task.project_id === null
                                    ? 'Assign a project before retrying this task.'
                                    : undefined
                            }
                            disabled={task.project_id === null}
                            onClick={onRetry}
                        >
                            Retry failed checkpoint
                        </Button>
                    ) : null}
                    {task.status === 'failed' ? (
                        <Button
                            type="button"
                            variant="danger"
                            title={
                                task.project_id === null
                                    ? 'Assign a project before rerunning this task.'
                                    : undefined
                            }
                            disabled={task.project_id === null}
                            onClick={onRerunWorkflow}
                        >
                            Rerun workflow
                        </Button>
                    ) : null}
                    {latestPullRequestRun ? (
                        <Button type="button" onClick={onRefreshPr}>
                            Refresh PR
                        </Button>
                    ) : null}
                    {canAttemptPrCreation ? (
                        <Button
                            type="button"
                            variant="success"
                            title={
                                createPrDisabled
                                    ? 'The latest task run does not have a branch to open.'
                                    : undefined
                            }
                            disabled={createPrDisabled}
                            onClick={onCreatePr}
                        >
                            Create PR
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <DetailItem label="Deadline">
                    {task.deadline
                        ? formatDisplayDate(task.deadline)
                        : 'No deadline'}
                </DetailItem>
                <DetailItem label="Assignee">
                    {task.assignee?.name ?? 'Unassigned'}
                </DetailItem>
                <DetailItem label="Reviewer">
                    {formatReviewer(
                        task.reviewer,
                        task.project?.default_reviewer,
                    )}
                </DetailItem>
                <DetailItem label="Approved by">
                    {task.approved_by_user?.name ?? 'Not approved'}
                </DetailItem>
                <DetailItem label="Source input">
                    {task.source_input ? (
                        task.source_input.has_file ? (
                            <ActionLink
                                href={inputSources.preview.url(
                                    task.source_input.id,
                                )}
                                target="_blank"
                                rel="noreferrer"
                                className="min-h-0 px-2 py-1 text-xs"
                            >
                                {task.source_input.title}
                            </ActionLink>
                        ) : (
                            task.source_input.title
                        )
                    ) : (
                        'None'
                    )}
                </DetailItem>
                <DetailItem label="Project">
                    {task.project ? (
                        <button
                            type="button"
                            onClick={() => setShowProjectModal(true)}
                            className="inline-flex min-h-0 items-center rounded-md border border-hairline-strong bg-surface-2 px-2 py-1 text-xs font-medium text-ink transition hover:bg-surface-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-focus"
                        >
                            {task.project.name}
                        </button>
                    ) : (
                        'Unassigned'
                    )}
                </DetailItem>
                <DetailItem label="Latest PR">
                    {latestPullRequestRun?.pull_request_url ? (
                        <ActionLink
                            href={latestPullRequestRun.pull_request_url}
                            target="_blank"
                            rel="noreferrer"
                            className="min-h-0 px-2 py-1 text-xs"
                        >
                            #{latestPullRequestRun.pull_request_number}
                        </ActionLink>
                    ) : (
                        'None'
                    )}
                </DetailItem>
                <DetailItem label="Created">
                    {formatDisplayDateTime(task.created_at)}
                </DetailItem>
                <DetailItem label="Updated">
                    {formatDisplayDateTime(task.updated_at)}
                </DetailItem>
            </div>

            <TaskProjectModal
                project={task.project ?? null}
                show={showProjectModal}
                onClose={() => setShowProjectModal(false)}
            />

            <Panel className="p-4">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold text-ink">
                        Workflow state
                    </h3>
                    {latestRun ? (
                        <Badge value={latestRun.status}>
                            Run #{latestRun.id}{' '}
                            {taskStatusLabel(latestRun.status)}
                        </Badge>
                    ) : null}
                </div>
                {latestRun && latestWorkflow ? (
                    <div className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <DetailItem label="Current checkpoint">
                                {currentCheckpoint
                                    ? checkpointLabel(currentCheckpoint.name)
                                    : 'Complete'}
                            </DetailItem>
                            <DetailItem label="Progress">
                                {completedCheckpoints}/
                                {latestCheckpoints.length} checkpoints
                            </DetailItem>
                            <DetailItem label="Request hash">
                                <span className="font-mono">
                                    {shortWorkflowHash(
                                        latestWorkflow.request_hash,
                                    )}
                                </span>
                            </DetailItem>
                        </div>
                        {failedCheckpoint?.error ? (
                            <p className="rounded-md border border-danger/30 bg-danger/10 p-2 text-xs text-red-100">
                                {checkpointLabel(failedCheckpoint.name)} failed:{' '}
                                {failedCheckpoint.error}
                            </p>
                        ) : null}
                        <WorkflowTimeline
                            checkpoints={latestCheckpoints}
                            formatDate={formatDisplayDateTime}
                        />
                    </div>
                ) : (
                    <p className="text-sm text-ink-subtle">
                        No workflow state has been recorded yet.
                    </p>
                )}
            </Panel>

            <Panel className="p-4">
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="text-sm font-semibold text-ink">
                        Acceptance criteria
                    </h3>
                    <span className="text-xs text-ink-subtle">
                        {task.acceptance_criteria.length} items
                    </span>
                </div>
                <ul className="space-y-2">
                    {task.acceptance_criteria.map((criterion, index) => (
                        <li
                            key={`${task.id}-${index}`}
                            className="flex gap-3 rounded-md border border-hairline bg-surface-2 p-3 text-sm text-ink-muted"
                        >
                            <span
                                className={cn(
                                    'mt-0.5 grid size-4 shrink-0 place-items-center rounded-sm border text-[10px] font-bold',
                                    criterion.checked
                                        ? 'border-success bg-success text-white'
                                        : 'border-hairline-strong text-transparent',
                                )}
                            >
                                ✓
                            </span>
                            <span>{criterion.body}</span>
                        </li>
                    ))}
                </ul>
            </Panel>

            <Panel className="p-4">
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="text-sm font-semibold text-ink">
                        Task runs
                    </h3>
                    <span className="text-xs text-ink-subtle">
                        {taskRuns.length} total, latest first
                    </span>
                </div>
                {taskRuns.length ? (
                    <div className="space-y-3">
                        {taskRuns.map((run, index) => (
                            <details
                                key={run.id}
                                className="group rounded-md border border-hairline bg-surface-2"
                            >
                                <summary className="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 p-3 marker:hidden">
                                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                                        <span className="text-xs text-ink-tertiary transition group-open:rotate-90">
                                            &gt;
                                        </span>
                                        <span className="text-sm font-medium text-ink">
                                            Run #{run.id}
                                        </span>
                                        {index === 0 ? (
                                            <Badge>Latest</Badge>
                                        ) : null}
                                        <Badge value={run.status}>
                                            {taskStatusLabel(run.status)}
                                        </Badge>
                                    </div>
                                    <span>
                                        <span className="max-w-full truncate text-xs text-ink-subtle">
                                            {run.branch_name ?? 'No branch'}
                                        </span>
                                    </span>
                                </summary>
                                <div className="border-t border-hairline px-3 pt-3 pb-3">
                                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-subtle">
                                        <span>
                                            Branch: {run.branch_name ?? 'None'}
                                        </span>
                                        <span>
                                            Attempts: {run.attempt_count}
                                        </span>
                                        <span>
                                            Reviews: {run.review_attempt_count}
                                        </span>
                                    </div>
                                    <div className="mt-3">
                                        <WorkflowTimeline
                                            checkpoints={
                                                run.workflow_state
                                                    ?.checkpoints ?? []
                                            }
                                            formatDate={formatDisplayDateTime}
                                        />
                                    </div>
                                    <div className="mt-3">
                                        <p className="text-xs font-medium text-ink-muted">
                                            Automation models
                                        </p>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                            <DetailItem label="Analyze source">
                                                {run.automation_models
                                                    .analyze_source_model ??
                                                    'Codex default'}
                                                {' / '}
                                                {run.automation_models
                                                    .analyze_source_reasoning_effort ??
                                                    'Codex default'}
                                            </DetailItem>
                                            <DetailItem label="Plan">
                                                {run.automation_models
                                                    .plan_model ??
                                                    'Codex default'}
                                                {' / '}
                                                {run.automation_models
                                                    .plan_reasoning_effort ??
                                                    'Codex default'}
                                            </DetailItem>
                                            <DetailItem label="Implement">
                                                {run.automation_models
                                                    .implement_model ??
                                                    'Codex default'}
                                                {' / '}
                                                {run.automation_models
                                                    .implement_reasoning_effort ??
                                                    'Codex default'}
                                            </DetailItem>
                                            <DetailItem label="Review">
                                                {run.automation_models
                                                    .review_model ??
                                                    'Codex default'}
                                                {' / '}
                                                {run.automation_models
                                                    .review_reasoning_effort ??
                                                    'Codex default'}
                                            </DetailItem>
                                            <DetailItem label="Commit message">
                                                {run.automation_models
                                                    .commit_message_model ??
                                                    'Codex default'}
                                                {' / '}
                                                {run.automation_models
                                                    .commit_message_reasoning_effort ??
                                                    'Codex default'}
                                            </DetailItem>
                                        </div>
                                    </div>
                                    <div className="mt-3 rounded-md border border-hairline bg-surface-1 p-3">
                                        <p className="text-xs font-medium text-ink-muted">
                                            Plan
                                        </p>
                                        <p className="mt-2 text-xs leading-5 whitespace-pre-wrap text-ink-subtle">
                                            {run.plan?.trim() ||
                                                'No plan recorded.'}
                                        </p>
                                    </div>
                                    {run.pull_request_url ? (
                                        <p className="mt-2 truncate text-xs text-ink-subtle">
                                            PR:{' '}
                                            <a
                                                href={run.pull_request_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-primary-hover underline"
                                            >
                                                {run.pull_request_url}
                                            </a>
                                        </p>
                                    ) : null}
                                    {run.last_error ? (
                                        <p className="mt-2 rounded-md border border-danger/30 bg-danger/10 p-2 text-xs text-red-100">
                                            Error: {run.last_error}
                                        </p>
                                    ) : null}
                                    <div className="mt-3">
                                        <p className="text-xs font-medium text-ink-muted">
                                            Run logs
                                        </p>
                                        {run.logs.length ? (
                                            <ul className="mt-2 space-y-1 text-xs">
                                                {run.logs.map((log) => (
                                                    <li
                                                        key={log.id}
                                                        className="flex items-start justify-between gap-3 rounded border border-hairline bg-surface-1 p-2"
                                                    >
                                                        <span className="min-w-0 font-mono text-ink-muted">
                                                            {log.message}
                                                        </span>
                                                        <time
                                                            dateTime={
                                                                log.created_at ??
                                                                undefined
                                                            }
                                                            className="shrink-0 text-ink-tertiary"
                                                        >
                                                            {formatDisplayDateTime(
                                                                log.created_at,
                                                            )}
                                                        </time>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <p className="mt-2 text-xs text-ink-subtle">
                                                No logs for this run.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </details>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-ink-subtle">No runs yet.</p>
                )}
            </Panel>
        </div>
    );
}

function TaskProjectModal({
    project,
    show,
    onClose,
}: {
    project: TaskProject | null;
    show: boolean;
    onClose: () => void;
}) {
    if (!show || project === null) {
        return null;
    }

    return (
        <Modal
            show
            onClose={onClose}
            title={project.name}
            size="md"
            closeButton="icon"
        >
            <div className="space-y-4">
                <p className="font-mono text-xs break-all text-ink-muted">
                    {project.workspace_path}
                </p>

                <div className="grid gap-3 sm:grid-cols-2">
                    <ProjectDetailItem label="Repository URL">
                        {project.url ? (
                            <a
                                href={project.url}
                                target="_blank"
                                rel="noreferrer"
                                className="break-all text-primary hover:text-primary-hover"
                            >
                                {project.url}
                            </a>
                        ) : (
                            'n/a'
                        )}
                    </ProjectDetailItem>
                    <ProjectDetailItem label="Base branch">
                        <Badge>{project.base_branch ?? 'main'}</Badge>
                    </ProjectDetailItem>
                    <ProjectDetailItem label="Database name">
                        {project.database_name ?? 'n/a'}
                    </ProjectDetailItem>
                    <ProjectDetailItem label="Database username">
                        {project.database_username ?? 'n/a'}
                    </ProjectDetailItem>
                    <ProjectDetailItem label="Database password">
                        {setStatus(project.has_database_password)}
                    </ProjectDetailItem>
                    <ProjectDetailItem label="Credential username">
                        {setStatus(project.has_credential_username)}
                    </ProjectDetailItem>
                    <ProjectDetailItem label="Credential password">
                        {setStatus(project.has_credential_password)}
                    </ProjectDetailItem>
                    <ProjectDetailItem label="Default reviewer">
                        {formatUser(project.default_reviewer) ?? 'n/a'}
                    </ProjectDetailItem>
                </div>
            </div>
        </Modal>
    );
}

function TaskEditorModal({
    show,
    title,
    processing,
    submitLabel,
    processingLabel,
    onClose,
    onSubmit,
    children,
}: {
    show: boolean;
    title: string;
    processing: boolean;
    submitLabel: string;
    processingLabel: string;
    onClose: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    children: ReactNode;
}) {
    return (
        <Modal show={show} onClose={onClose} title={title}>
            <form onSubmit={onSubmit} className="space-y-4">
                {children}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={processing}
                    >
                        {processing ? processingLabel : submitLabel}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function TaskFormFields({
    form,
    users,
    sourceInputs,
    projects,
    priorities,
    showSourceInput = true,
    onAddCriterion,
    onRemoveCriterion,
    onUpdateCriterion,
}: {
    form: {
        data: TaskFormData;
        setData: (
            keyOrData: keyof TaskFormData | TaskFormData,
            value?: TaskFormData[keyof TaskFormData],
        ) => void;
        errors: Record<string, string | string[]>;
    };
    users: User[];
    sourceInputs: SourceInput[];
    projects: ProjectSummary[];
    priorities: string[];
    showSourceInput?: boolean;
    onAddCriterion: () => void;
    onRemoveCriterion: (index: number) => void;
    onUpdateCriterion: (index: number, patch: Partial<Criterion>) => void;
}) {
    const changeProject = (projectId: string) => {
        const currentProjectDefaultReviewer = defaultReviewerForProject(
            projects,
            form.data.project_id,
        );
        const nextProjectDefaultReviewer = defaultReviewerForProject(
            projects,
            projectId,
        );

        form.setData({
            ...form.data,
            project_id: projectId,
            reviewer_user_id:
                form.data.reviewer_user_id === '' ||
                form.data.reviewer_user_id === currentProjectDefaultReviewer
                    ? nextProjectDefaultReviewer
                    : form.data.reviewer_user_id,
        });
    };

    return (
        <div className="space-y-4">
            <Field label="Title" error={formatError(form.errors.title)}>
                <Input
                    type="text"
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
            </Field>

            <Field
                label="Description"
                error={formatError(form.errors.description)}
            >
                <Textarea
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                    rows={4}
                />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Priority">
                    <Select
                        value={form.data.priority}
                        onChange={(event) =>
                            form.setData('priority', event.target.value)
                        }
                    >
                        {priorities.map((priority) => (
                            <option key={priority} value={priority}>
                                {taskPriorityLabel(priority)}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="Deadline">
                    <Input
                        type="date"
                        value={form.data.deadline}
                        onChange={(event) =>
                            form.setData('deadline', event.target.value)
                        }
                    />
                </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Assignee"
                    error={formatError(form.errors.assignee_user_id)}
                >
                    <Select
                        value={form.data.assignee_user_id}
                        onChange={(event) =>
                            form.setData('assignee_user_id', event.target.value)
                        }
                    >
                        <option value="">Unassigned</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>
                                {user.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field
                    label="Project"
                    error={formatError(form.errors.project_id)}
                >
                    <Select
                        value={form.data.project_id}
                        onChange={(event) => changeProject(event.target.value)}
                    >
                        <option value="">No project</option>
                        {projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                            </option>
                        ))}
                    </Select>
                </Field>
            </div>

            <Field
                label="Reviewer"
                error={formatError(form.errors.reviewer_user_id)}
            >
                <Select
                    value={form.data.reviewer_user_id}
                    onChange={(event) =>
                        form.setData('reviewer_user_id', event.target.value)
                    }
                >
                    <option value="">No reviewer</option>
                    {users.map((user) => (
                        <option key={user.id} value={user.id}>
                            {user.name}
                            {user.github_username
                                ? ` (@${user.github_username})`
                                : ''}
                        </option>
                    ))}
                </Select>
            </Field>

            {showSourceInput ? (
                <Field
                    label="Source input"
                    error={formatError(form.errors.source_input_id)}
                >
                    <Select
                        value={form.data.source_input_id}
                        onChange={(event) =>
                            form.setData('source_input_id', event.target.value)
                        }
                    >
                        <option value="">None</option>
                        {sourceInputs.map((sourceInput) => (
                            <option key={sourceInput.id} value={sourceInput.id}>
                                {sourceInput.title}
                            </option>
                        ))}
                    </Select>
                </Field>
            ) : null}

            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <span className="text-sm font-medium text-ink-muted">
                        Acceptance criteria
                    </span>
                    <Button
                        type="button"
                        variant="secondary"
                        className="min-h-8 px-2 py-1 text-xs"
                        onClick={onAddCriterion}
                    >
                        Add
                    </Button>
                </div>
                {form.data.acceptance_criteria.map((criterion, index) => (
                    <div
                        key={`${index}-${criterion.checked}`}
                        className="grid gap-2 rounded-md border border-hairline bg-surface-2 p-2"
                    >
                        <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                            <Input
                                type="text"
                                value={criterion.body}
                                onChange={(event) =>
                                    onUpdateCriterion(index, {
                                        body: event.target.value,
                                    })
                                }
                                placeholder="Acceptance criteria item"
                            />
                            <label className="flex min-h-9 items-center gap-2 text-sm text-ink-muted">
                                <input
                                    type="checkbox"
                                    checked={criterion.checked}
                                    onChange={(event) =>
                                        onUpdateCriterion(index, {
                                            checked: event.target.checked,
                                        })
                                    }
                                />
                                Done
                            </label>
                        </div>
                        <Button
                            type="button"
                            variant="danger"
                            className="min-h-8 justify-self-end px-2 py-1 text-xs"
                            onClick={() => onRemoveCriterion(index)}
                        >
                            Remove
                        </Button>
                    </div>
                ))}
                {formatError(form.errors.acceptance_criteria) ? (
                    <p className="text-xs text-red-200">
                        {formatError(form.errors.acceptance_criteria)}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

function DetailItem({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="rounded-md border border-hairline bg-surface-2 p-3">
            <p className="text-xs font-medium text-ink-tertiary">{label}</p>
            <div className="mt-1 min-w-0 truncate text-sm font-medium text-ink-muted">
                {children}
            </div>
        </div>
    );
}

function ProjectDetailItem({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="rounded-md border border-hairline bg-surface-2 p-3">
            <p className="text-xs font-medium text-ink-tertiary">{label}</p>
            <div className="mt-1 text-sm text-ink">{children}</div>
        </div>
    );
}
