import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import type { ReactNode } from 'react';
import inputSources from '@/routes/input-sources';
import logs from '@/routes/logs';
import tasks from '@/routes/tasks';

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
    original_filename?: string | null;
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

type AiRunRecord = {
    id: number;
    status: string;
    branch_name: string | null;
    plan: string | null;
    test_cases: unknown;
    pull_request_url: string | null;
    pull_request_number: number | null;
    attempt_count: number;
    last_error: string | null;
    started_at: string | null;
    finished_at: string | null;
    logs: RunLog[];
};

type TaskRecord = {
    id: number;
    title: string;
    description: string;
    status: string;
    priority: string;
    deadline: string | null;
    assignee_user_id: number | null;
    source_input_id: number | null;
    approved_by_user_id: number | null;
    approved_at: string | null;
    rejected_at: string | null;
    pull_request_url: string | null;
    pull_request_number: number | null;
    assignee: TaskRelation | null;
    approved_by_user: TaskRelation | null;
    source_input: SourceInput | null;
    acceptance_criteria: Criterion[];
    created_at: string | null;
    updated_at: string | null;
    latest_ai_run: {
        id: number;
        status: string;
        branch_name: string | null;
        pull_request_url: string | null;
        pull_request_number: number | null;
    } | null;
    external_task_link?: {
        id: number;
        external_task_provider: string;
        external_task_id: string;
        external_url: string;
    } | null;
    ai_runs?: AiRunRecord[];
    external_messages?: {
        id: number;
        type: string;
        status: string;
        error: string | null;
        sent_at: string | null;
        payload: Record<string, unknown> | null;
    }[];
};

type IndexPageProps = {
    tasks: TaskRecord[];
    users: User[];
    sourceInputs: SourceInput[];
    selectedTask: TaskRecord | null;
    taskStatuses: string[];
    priorities: string[];
    flash?: {
        status?: string;
        errors?: Record<string, string | string[]>;
    };
    errors?: Record<string, string | string[]>;
};

const formatError = (error: string | string[] | undefined): string | null => {
    if (Array.isArray(error)) {
        return error.join(', ');
    }

    return error ?? null;
};

type TaskFormData = {
    title: string;
    description: string;
    priority: string;
    deadline: string;
    assignee_user_id: string;
    source_input_id: string;
    acceptance_criteria: Criterion[];
};

type ImportSourceType = 'text' | 'file';

const emptyCriterion = (): Criterion => ({ body: '', checked: false });

const sanitizeCriteria = (criteria: Criterion[]): Criterion[] => {
    const next = criteria
        .map((item) => ({ ...item, body: item.body.trim() }))
        .filter((item) => item.body !== '');

    return next.length > 0
        ? next
        : [{ ...emptyCriterion(), body: 'No acceptance criteria provided.' }];
};

const statusClasses: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-800',
    pending_approval: 'bg-amber-100 text-amber-800',
    approved: 'bg-sky-100 text-sky-800',
    running: 'bg-blue-100 text-blue-800',
    pr_created: 'bg-purple-100 text-purple-800',
    done: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-rose-100 text-rose-800',
    rejected: 'bg-slate-100 text-slate-500',
};

const taskStatusLabel = (status: string) => status.replace('_', ' ');

const taskPriorityLabel = (priority: string) => priority.toUpperCase();

const formatFileSize = (size: number | null): string => {
    if (size === null) {
        return 'Unknown size';
    }

    if (size < 1024) {
        return `${size} B`;
    }

    if (size < 1024 * 1024) {
        return `${(size / 1024).toFixed(1)} KB`;
    }

    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
};

const formatDate = (value: string | null): string => {
    if (!value) {
        return '—';
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return value;
    }
};

export default function TasksIndex() {
    const page = usePage<IndexPageProps>();
    const {
        tasks: boardTasks,
        users,
        sourceInputs,
        selectedTask,
        taskStatuses,
        priorities,
        flash,
        errors,
    } = page.props;

    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showImportModal, setShowImportModal] = useState(false);
    const [showEditModal, setShowEditModal] = useState(false);
    const [editingTask, setEditingTask] = useState<TaskRecord | null>(null);

    const createForm = useForm<TaskFormData>({
        title: '',
        description: '',
        priority: priorities.includes('medium')
            ? 'medium'
            : (priorities[0] ?? 'medium'),
        deadline: '',
        assignee_user_id: '',
        source_input_id: '',
        acceptance_criteria: [emptyCriterion()],
    });

    const editForm = useForm<TaskFormData>({
        title: '',
        description: '',
        priority: priorities.includes('medium')
            ? 'medium'
            : (priorities[0] ?? 'medium'),
        deadline: '',
        assignee_user_id: '',
        source_input_id: '',
        acceptance_criteria: [emptyCriterion()],
    });

    const analyzeForm = useForm<{
        title: string;
        source_type: ImportSourceType;
        text: string;
        upload: File | null;
    }>({
        title: '',
        source_type: 'text',
        text: '',
        upload: null,
    });

    const groupedTasks = useMemo(() => {
        const grouped: Record<string, TaskRecord[]> = {};

        taskStatuses.forEach((status) => {
            grouped[status] = [];
        });

        boardTasks.forEach((task) => {
            if (!Object.prototype.hasOwnProperty.call(grouped, task.status)) {
                grouped[task.status] = [];
            }

            grouped[task.status]?.push(task);
        });

        return grouped;
    }, [boardTasks, taskStatuses]);

    const resetCreateForm = () => {
        createForm.setData({
            title: '',
            description: '',
            priority: priorities.includes('medium')
                ? 'medium'
                : (priorities[0] ?? 'medium'),
            deadline: '',
            assignee_user_id: '',
            source_input_id: '',
            acceptance_criteria: [emptyCriterion()],
        });
        createForm.clearErrors();
        createForm.setDefaults({
            title: '',
            description: '',
            priority: priorities.includes('medium')
                ? 'medium'
                : (priorities[0] ?? 'medium'),
            deadline: '',
            assignee_user_id: '',
            source_input_id: '',
            acceptance_criteria: [emptyCriterion()],
        });
    };

    const openCreateModal = () => {
        resetCreateForm();
        createForm.clearErrors();
        setShowCreateModal(true);
    };

    const closeImportModal = () => {
        setShowImportModal(false);
        analyzeForm.clearErrors();
    };

    const updateImportSourceType = (sourceType: ImportSourceType) => {
        analyzeForm.setData({
            ...analyzeForm.data,
            source_type: sourceType,
            text: sourceType === 'text' ? analyzeForm.data.text : '',
            upload: sourceType === 'file' ? analyzeForm.data.upload : null,
        });
        analyzeForm.clearErrors('text', 'upload');
    };

    const openTaskDetails = (taskId: number) => {
        router.get(
            tasks.index.url({ query: { task: taskId } }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const closeTaskDetails = () => {
        router.get(
            tasks.index.url(),
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const startEdit = (task: TaskRecord) => {
        setEditingTask(task);
        setShowEditModal(true);
        editForm.setData({
            title: task.title,
            description: task.description,
            priority: task.priority,
            deadline: task.deadline ?? '',
            assignee_user_id: task.assignee_user_id
                ? String(task.assignee_user_id)
                : '',
            source_input_id: task.source_input_id
                ? String(task.source_input_id)
                : '',
            acceptance_criteria:
                task.acceptance_criteria.length > 0
                    ? task.acceptance_criteria
                    : [emptyCriterion()],
        });
        editForm.clearErrors();
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
            onSuccess: () => {
                closeEditModal();
            },
        });
    };

    const submitImport = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        analyzeForm.transform((data) => ({
            ...data,
            text: data.source_type === 'text' ? data.text : '',
            upload: data.source_type === 'file' ? data.upload : null,
        }));
        analyzeForm.post(inputSources.store.url(), {
            forceFormData: true,
            onSuccess: () => {
                setShowImportModal(false);
                analyzeForm.reset();
            },
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
        <div className="min-h-screen bg-slate-50 p-6 text-slate-900">
            <Head title="Tasks" />

            {flash?.status ? (
                <div className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                    {flash.status}
                </div>
            ) : null}

            {errors?.status ? (
                <div className="mb-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">
                    {formatError(errors.status)}
                </div>
            ) : null}

            <div className="mx-auto max-w-7xl space-y-5">
                <header className="rounded-lg bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-semibold">
                                Task Board
                            </h1>
                            <p className="text-sm text-slate-500">
                                Analyze input, edit tasks, and track
                                coding-agent execution in one workspace.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={openCreateModal}
                                className="rounded-md border border-slate-900 bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                            >
                                + Create task
                            </button>
                            <Link
                                href={inputSources.index.url()}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50"
                            >
                                Manage sources
                            </Link>
                            <Link
                                href={logs.index.url()}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50"
                            >
                                View logs
                            </Link>
                        </div>
                    </div>
                </header>

                <section className="rounded-lg bg-white p-4 shadow-sm">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Input sources
                            </h2>
                            <p className="text-xs text-slate-500">
                                Uploaded and pasted sources queued for task
                                analysis.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setShowImportModal(true)}
                            className="rounded-md border border-slate-900 bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                        >
                            Upload source
                        </button>
                    </div>
                    <div className="divide-y divide-slate-100">
                        {sourceInputs.slice(0, 8).map((sourceInput) => (
                            <div
                                key={sourceInput.id}
                                className="grid gap-3 py-3 sm:grid-cols-[1fr_auto] sm:items-center"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-sm font-medium">
                                            {sourceInput.title}
                                        </p>
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-xs ${statusClasses[sourceInput.analysis_status] ?? 'bg-slate-100 text-slate-700'}`}
                                        >
                                            {sourceInput.analysis_status}
                                        </span>
                                    </div>
                                    <p className="mt-1 truncate text-xs text-slate-500">
                                        {sourceInput.original_filename
                                            ? `${sourceInput.original_filename} · ${sourceInput.mime_type ?? 'unknown type'} · ${formatFileSize(sourceInput.file_size ?? null)}`
                                            : 'Pasted text'}
                                    </p>
                                </div>
                                {sourceInput.has_file ? (
                                    <a
                                        href={inputSources.preview.url(
                                            sourceInput.id,
                                        )}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="rounded-md border border-slate-300 px-3 py-2 text-center text-sm font-semibold text-slate-900 hover:bg-slate-50"
                                    >
                                        Open
                                    </a>
                                ) : null}
                            </div>
                        ))}
                        {sourceInputs.length === 0 ? (
                            <p className="py-3 text-sm text-slate-500">
                                No input sources yet.
                            </p>
                        ) : null}
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {taskStatuses.map((status) => {
                        const tasksInStatus = groupedTasks[status] ?? [];

                        if (tasksInStatus.length === 0) {
                            return null;
                        }

                        return (
                            <article
                                key={status}
                                className="rounded-lg bg-white p-4 shadow-sm"
                            >
                                <h2 className="mb-3 text-sm font-medium tracking-wide text-slate-500 uppercase">
                                    {status.replace('_', ' ')}
                                </h2>
                                <div className="space-y-3">
                                    {tasksInStatus.map((task) => (
                                        <button
                                            key={task.id}
                                            type="button"
                                            onClick={() =>
                                                openTaskDetails(task.id)
                                            }
                                            className="w-full rounded-md border border-slate-200 bg-slate-50 p-3 text-left transition hover:border-slate-300"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="font-medium">
                                                    {task.title}
                                                </p>
                                                <span
                                                    className={`rounded-full px-2 py-0.5 text-xs ${statusClasses[status] ?? 'bg-slate-100 text-slate-700'}`}
                                                >
                                                    {status}
                                                </span>
                                            </div>
                                            <p className="mt-2 text-xs text-slate-500">
                                                {task.description.slice(0, 90)}
                                            </p>
                                            <div className="mt-2 text-xs text-slate-600">
                                                Priority:{' '}
                                                {taskPriorityLabel(
                                                    task.priority,
                                                )}
                                            </div>
                                        </button>
                                    ))}
                                </div>
                            </article>
                        );
                    })}
                </section>

                <Modal
                    show={showCreateModal}
                    onClose={() => setShowCreateModal(false)}
                    title="Create task"
                >
                    <form onSubmit={submitCreate} className="space-y-4">
                        <TaskFormFields
                            form={createForm}
                            users={users}
                            sourceInputs={sourceInputs}
                            priorities={priorities}
                            onAddCriterion={() =>
                                addCriterion(
                                    createForm.setData,
                                    createForm.data,
                                )
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
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowCreateModal(false)}
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={createForm.processing}
                                className="rounded-md border border-slate-900 bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                {createForm.processing
                                    ? 'Creating...'
                                    : 'Create'}
                            </button>
                        </div>
                    </form>
                </Modal>

                <Modal
                    show={showEditModal}
                    onClose={closeEditModal}
                    title={`Edit task #${editingTask?.id ?? ''}`}
                >
                    <form onSubmit={submitEdit} className="space-y-4">
                        <TaskFormFields
                            form={editForm}
                            users={users}
                            sourceInputs={sourceInputs}
                            priorities={priorities}
                            onAddCriterion={() =>
                                addCriterion(editForm.setData, editForm.data)
                            }
                            onRemoveCriterion={(index) =>
                                removeCriterion(
                                    editForm.setData,
                                    editForm.data,
                                    index,
                                )
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
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={closeEditModal}
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={editForm.processing}
                                className="rounded-md border border-slate-900 bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                {editForm.processing ? 'Saving...' : 'Save'}
                            </button>
                        </div>
                    </form>
                </Modal>

                <Modal
                    show={showImportModal}
                    onClose={closeImportModal}
                    title="Import input source"
                >
                    <form onSubmit={submitImport} className="space-y-4">
                        <label className="grid gap-1 text-sm">
                            <span>Title (optional)</span>
                            <input
                                type="text"
                                value={analyzeForm.data.title}
                                onChange={(event) =>
                                    analyzeForm.setData(
                                        'title',
                                        event.target.value,
                                    )
                                }
                                className="rounded-md border border-slate-300 px-2 py-1"
                            />
                        </label>

                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium text-slate-800">
                                Input source
                            </legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {(['text', 'file'] as ImportSourceType[]).map(
                                    (sourceType) => (
                                        <label
                                            key={sourceType}
                                            className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm transition ${
                                                analyzeForm.data.source_type ===
                                                sourceType
                                                    ? 'border-slate-900 bg-slate-900 text-white'
                                                    : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                            }`}
                                        >
                                            <input
                                                type="radio"
                                                name="source_type"
                                                value={sourceType}
                                                checked={
                                                    analyzeForm.data
                                                        .source_type ===
                                                    sourceType
                                                }
                                                onChange={() =>
                                                    updateImportSourceType(
                                                        sourceType,
                                                    )
                                                }
                                                className="sr-only"
                                            />
                                            <span className="font-semibold">
                                                {sourceType === 'text'
                                                    ? 'Paste text'
                                                    : 'Upload file'}
                                            </span>
                                            <span
                                                className={
                                                    analyzeForm.data
                                                        .source_type ===
                                                    sourceType
                                                        ? 'text-slate-200'
                                                        : 'text-slate-500'
                                                }
                                            >
                                                {sourceType === 'text'
                                                    ? 'Manual input'
                                                    : '.txt, .md, or .pdf'}
                                            </span>
                                        </label>
                                    ),
                                )}
                            </div>
                        </fieldset>

                        {analyzeForm.data.source_type === 'text' ? (
                            <label className="grid gap-1 text-sm">
                                <span>Text</span>
                                <textarea
                                    value={analyzeForm.data.text}
                                    onChange={(event) =>
                                        analyzeForm.setData(
                                            'text',
                                            event.target.value,
                                        )
                                    }
                                    rows={8}
                                    className="rounded-md border border-slate-300 px-2 py-1"
                                    placeholder="Paste the source text to analyze into tasks..."
                                />
                            </label>
                        ) : (
                            <div className="grid gap-2 text-sm">
                                <span>File</span>
                                <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center transition hover:border-slate-400 hover:bg-white">
                                    <span className="rounded-md border border-slate-900 bg-slate-900 px-3 py-2 text-sm font-semibold text-white">
                                        Choose file
                                    </span>
                                    <span className="text-xs text-slate-500">
                                        {analyzeForm.data.upload?.name ??
                                            'Upload a .txt, .md, or .pdf file up to 10 MB'}
                                    </span>
                                    <input
                                        type="file"
                                        accept=".txt,.md,.pdf,text/plain,text/markdown,application/pdf"
                                        onChange={(event) => {
                                            analyzeForm.setData(
                                                'upload',
                                                event.currentTarget
                                                    .files?.[0] ?? null,
                                            );
                                        }}
                                        className="sr-only"
                                    />
                                </label>
                            </div>
                        )}
                        {formatError(analyzeForm.errors.text) ? (
                            <p className="text-xs text-rose-600">
                                {formatError(analyzeForm.errors.text)}
                            </p>
                        ) : null}
                        {analyzeForm.errors.upload ? (
                            <p className="text-xs text-rose-600">
                                {formatError(analyzeForm.errors.upload)}
                            </p>
                        ) : null}

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={closeImportModal}
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={analyzeForm.processing}
                                className="rounded-md border border-slate-900 bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                {analyzeForm.processing
                                    ? 'Submitting...'
                                    : 'Analyze'}
                            </button>
                        </div>
                    </form>
                </Modal>

                <Modal
                    show={selectedTask !== null && !showEditModal}
                    onClose={closeTaskDetails}
                    title={
                        selectedTask
                            ? `Task #${selectedTask.id}`
                            : 'Task details'
                    }
                >
                    {selectedTask ? (
                        <div className="space-y-5">
                            <div className="flex flex-col gap-4 border-b border-slate-200 pb-4 lg:flex-row lg:items-start lg:justify-between">
                                <div className="min-w-0 space-y-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span
                                            className={`rounded-md px-2 py-1 text-xs font-medium ${statusClasses[selectedTask.status] ?? 'bg-slate-100 text-slate-700'}`}
                                        >
                                            {taskStatusLabel(
                                                selectedTask.status,
                                            )}
                                        </span>
                                        <span className="rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                                            {taskPriorityLabel(
                                                selectedTask.priority,
                                            )}{' '}
                                            priority
                                        </span>
                                        {selectedTask.latest_ai_run ? (
                                            <span className="rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700">
                                                Run{' '}
                                                {taskStatusLabel(
                                                    selectedTask.latest_ai_run
                                                        .status,
                                                )}
                                            </span>
                                        ) : null}
                                    </div>
                                    <h2 className="text-xl leading-tight font-semibold text-slate-950">
                                        {selectedTask.title}
                                    </h2>
                                    <p className="max-w-3xl text-sm leading-6 text-slate-600">
                                        {selectedTask.description}
                                    </p>
                                </div>
                                <div className="flex shrink-0 flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => startEdit(selectedTask)}
                                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50"
                                    >
                                        Edit
                                    </button>
                                    {selectedTask.status ===
                                    'pending_approval' ? (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                submitApprove(selectedTask.id)
                                            }
                                            className="rounded-md border border-emerald-600 bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                                        >
                                            Approve
                                        </button>
                                    ) : null}
                                    {selectedTask.status !== 'done' ? (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                submitReject(selectedTask.id)
                                            }
                                            className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-900 hover:bg-rose-100"
                                        >
                                            Reject
                                        </button>
                                    ) : null}
                                    {selectedTask.pull_request_url ? (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                submitRefreshPr(selectedTask.id)
                                            }
                                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50"
                                        >
                                            Refresh PR
                                        </button>
                                    ) : null}
                                </div>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <DetailItem label="Task">
                                    #{selectedTask.id}
                                </DetailItem>
                                <DetailItem label="Deadline">
                                    {selectedTask.deadline ?? 'No deadline'}
                                </DetailItem>
                                <DetailItem label="Assignee">
                                    {selectedTask.assignee?.name ??
                                        'Unassigned'}
                                </DetailItem>
                                <DetailItem label="Approved by">
                                    {selectedTask.approved_by_user
                                        ? selectedTask.approved_by_user.name
                                        : 'Not approved'}
                                </DetailItem>
                                <DetailItem label="Source input">
                                    {selectedTask.source_input?.title ?? 'None'}
                                </DetailItem>
                                <DetailItem label="Latest PR">
                                    <span>
                                        {selectedTask.pull_request_url ? (
                                            <a
                                                href={
                                                    selectedTask.pull_request_url
                                                }
                                                target="_blank"
                                                rel="noreferrer"
                                                className="font-medium text-slate-950 underline"
                                            >
                                                #
                                                {
                                                    selectedTask.pull_request_number
                                                }
                                            </a>
                                        ) : (
                                            'None'
                                        )}
                                    </span>
                                </DetailItem>
                                <DetailItem label="Created">
                                    {formatDate(selectedTask.created_at)}
                                </DetailItem>
                                <DetailItem label="Updated">
                                    {formatDate(selectedTask.updated_at)}
                                </DetailItem>
                            </div>

                            <section className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <div className="mb-3 flex items-center justify-between gap-3">
                                    <h3 className="text-sm font-semibold text-slate-950">
                                        Acceptance criteria
                                    </h3>
                                    <span className="text-xs text-slate-500">
                                        {
                                            selectedTask.acceptance_criteria
                                                .length
                                        }{' '}
                                        items
                                    </span>
                                </div>
                                <ul className="space-y-2">
                                    {selectedTask.acceptance_criteria.map(
                                        (criterion, index) => (
                                            <li
                                                key={`${selectedTask.id}-${index}`}
                                                className="flex gap-3 rounded-md border border-slate-200 bg-white p-3 text-sm text-slate-700"
                                            >
                                                <span
                                                    className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-sm border text-[10px] font-bold ${criterion.checked ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-transparent'}`}
                                                >
                                                    ✓
                                                </span>
                                                <span>{criterion.body}</span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </section>

                            <section className="rounded-lg border border-slate-200 p-4">
                                <div className="mb-3 flex items-center justify-between gap-3">
                                    <h3 className="text-sm font-semibold text-slate-950">
                                        AI runs
                                    </h3>
                                    <span className="text-xs text-slate-500">
                                        {selectedTask.ai_runs?.length ?? 0}{' '}
                                        total
                                    </span>
                                </div>
                                {selectedTask.ai_runs?.length ? (
                                    <div className="space-y-3">
                                        {selectedTask.ai_runs.map((run) => (
                                            <div
                                                key={run.id}
                                                className="rounded-md border border-slate-200 bg-white p-3"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <p className="text-sm font-medium text-slate-950">
                                                        Run #{run.id}
                                                    </p>
                                                    <span
                                                        className={`rounded-md px-2 py-1 text-xs font-medium ${statusClasses[run.status] ?? 'bg-slate-100 text-slate-700'}`}
                                                    >
                                                        {taskStatusLabel(
                                                            run.status,
                                                        )}
                                                    </span>
                                                </div>
                                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                                    <span>
                                                        Branch:{' '}
                                                        {run.branch_name ??
                                                            'None'}
                                                    </span>
                                                    <span>
                                                        Attempts:{' '}
                                                        {run.attempt_count}
                                                    </span>
                                                </div>
                                                {run.pull_request_url ? (
                                                    <p className="mt-2 truncate text-xs">
                                                        PR:{' '}
                                                        <a
                                                            href={
                                                                run.pull_request_url
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="text-slate-950 underline"
                                                        >
                                                            {
                                                                run.pull_request_url
                                                            }
                                                        </a>
                                                    </p>
                                                ) : null}
                                                {run.last_error ? (
                                                    <p className="mt-2 rounded-md bg-rose-50 p-2 text-xs text-rose-700">
                                                        Error: {run.last_error}
                                                    </p>
                                                ) : null}
                                                <details className="mt-3">
                                                    <summary className="cursor-pointer text-xs font-medium text-slate-700">
                                                        Run logs
                                                    </summary>
                                                    {run.logs.length ? (
                                                        <ul className="mt-2 space-y-1 text-xs">
                                                            {run.logs.map(
                                                                (log) => (
                                                                    <li
                                                                        key={
                                                                            log.id
                                                                        }
                                                                        className="rounded border border-slate-200 bg-slate-50 p-2"
                                                                    >
                                                                        <span className="font-mono text-slate-700">
                                                                            {
                                                                                log.message
                                                                            }
                                                                        </span>
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    ) : (
                                                        <p className="mt-2 text-xs text-slate-500">
                                                            No logs for this
                                                            run.
                                                        </p>
                                                    )}
                                                </details>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="mt-1 text-sm text-slate-500">
                                        No runs yet.
                                    </p>
                                )}
                            </section>
                        </div>
                    ) : null}
                </Modal>
            </div>
        </div>
    );
}

function TaskFormFields({
    form,
    users,
    sourceInputs,
    priorities,
    onAddCriterion,
    onRemoveCriterion,
    onUpdateCriterion,
}: {
    form: {
        data: TaskFormData;
        setData: (
            key: keyof TaskFormData,
            value: TaskFormData[keyof TaskFormData],
        ) => void;
        errors: Record<string, string | string[]>;
    };
    users: User[];
    sourceInputs: SourceInput[];
    priorities: string[];
    onAddCriterion: () => void;
    onRemoveCriterion: (index: number) => void;
    onUpdateCriterion: (index: number, patch: Partial<Criterion>) => void;
}) {
    return (
        <div className="space-y-4">
            <label className="grid gap-1 text-sm">
                <span>Title</span>
                <input
                    type="text"
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                    className="rounded-md border border-slate-300 px-2 py-1"
                />
                {typeof form.errors.title === 'string' ? (
                    <span className="text-xs text-rose-600">
                        {form.errors.title}
                    </span>
                ) : null}
            </label>

            <label className="grid gap-1 text-sm">
                <span>Description</span>
                <textarea
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                    rows={4}
                    className="rounded-md border border-slate-300 px-2 py-1"
                />
                {typeof form.errors.description === 'string' ? (
                    <span className="text-xs text-rose-600">
                        {form.errors.description}
                    </span>
                ) : null}
            </label>

            <div className="grid gap-4 sm:grid-cols-2">
                <label className="grid gap-1 text-sm">
                    <span>Priority</span>
                    <select
                        value={form.data.priority}
                        onChange={(event) =>
                            form.setData('priority', event.target.value)
                        }
                        className="rounded-md border border-slate-300 px-2 py-1"
                    >
                        {priorities.map((priority) => (
                            <option key={priority} value={priority}>
                                {taskPriorityLabel(priority)}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    <span>Deadline</span>
                    <input
                        type="date"
                        value={form.data.deadline}
                        onChange={(event) =>
                            form.setData('deadline', event.target.value)
                        }
                        className="rounded-md border border-slate-300 px-2 py-1"
                    />
                </label>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <label className="grid gap-1 text-sm">
                    <span>Assignee</span>
                    <select
                        value={form.data.assignee_user_id}
                        onChange={(event) =>
                            form.setData('assignee_user_id', event.target.value)
                        }
                        className="rounded-md border border-slate-300 px-2 py-1"
                    >
                        <option value="">Unassigned</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>
                                {user.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    <span>Source input</span>
                    <select
                        value={form.data.source_input_id}
                        onChange={(event) =>
                            form.setData('source_input_id', event.target.value)
                        }
                        className="rounded-md border border-slate-300 px-2 py-1"
                    >
                        <option value="">None</option>
                        {sourceInputs.map((sourceInput) => (
                            <option key={sourceInput.id} value={sourceInput.id}>
                                {sourceInput.title}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            <div>
                <div className="mb-2 flex items-center justify-between">
                    <span className="text-sm font-medium">
                        Acceptance criteria
                    </span>
                    <button
                        type="button"
                        onClick={onAddCriterion}
                        className="rounded-md border border-slate-300 px-2 py-1 text-xs"
                    >
                        + Add
                    </button>
                </div>
                {form.data.acceptance_criteria.map((criterion, index) => (
                    <div
                        key={`${form.data.title}-${index}`}
                        className="mb-2 grid gap-2"
                    >
                        <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                            <input
                                type="text"
                                value={criterion.body}
                                onChange={(event) =>
                                    onUpdateCriterion(index, {
                                        body: event.target.value,
                                    })
                                }
                                className="rounded-md border border-slate-300 px-2 py-1"
                                placeholder="Acceptance criteria item"
                            />
                            <label className="flex items-center gap-2 text-sm">
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
                        <button
                            type="button"
                            onClick={() => onRemoveCriterion(index)}
                            className="justify-self-end rounded-md border border-rose-200 px-2 py-1 text-xs text-rose-700"
                        >
                            Remove
                        </button>
                    </div>
                ))}
            </div>
            {typeof form.errors.acceptance_criteria === 'string' ? (
                <p className="text-xs text-rose-600">
                    {form.errors.acceptance_criteria}
                </p>
            ) : null}
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
        <div className="rounded-lg border border-slate-200 bg-white p-3">
            <p className="text-xs font-medium text-slate-500">{label}</p>
            <p className="mt-1 truncate text-sm font-medium text-slate-900">
                {children}
            </p>
        </div>
    );
}

function Modal({
    show,
    onClose,
    title,
    children,
}: {
    show: boolean;
    onClose: () => void;
    title: string;
    children: ReactNode;
}) {
    if (!show) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-label={title}
        >
            <div className="max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-lg bg-white shadow-xl ring-1 ring-slate-950/10">
                <div className="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4">
                    <h2 className="truncate text-base font-semibold text-slate-950">
                        {title}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Close
                    </button>
                </div>
                <div className="max-h-[calc(90vh-65px)] overflow-y-auto p-5">
                    {children}
                </div>
            </div>
        </div>
    );
}
