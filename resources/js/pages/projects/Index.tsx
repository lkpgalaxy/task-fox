import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { AppShell } from '@/components/app-shell';
import {
    Alert,
    Badge,
    Button,
    DataTable,
    Field,
    Input,
    Modal,
    Panel,
    Select,
    TableBody,
    TableHead,
    Td,
    Th,
} from '@/components/ui';
import projects from '@/routes/projects';

type ProjectRecord = {
    id: number;
    name: string;
    workspace_path: string;
    url: string | null;
    default_reviewer_user_id: number | null;
    default_reviewer: ReviewerOption | null;
    credential_username: string | null;
    database_name: string | null;
    database_username: string | null;
    base_branch: string | null;
    has_database_password: boolean;
    has_credential_password: boolean;
    has_credential_username: boolean;
};

type ReviewerOption = {
    id: number;
    name: string;
    github_username: string | null;
};

type ProjectFormData = {
    name: string;
    workspace_path: string;
    url: string;
    database_name: string;
    database_username: string;
    database_password: string;
    credential_username: string;
    credential_password: string;
    base_branch: string;
    default_reviewer_user_id: string;
};

type PageProps = {
    projects: ProjectRecord[];
    reviewerOptions: ReviewerOption[];
    errors?: {
        [key: string]: string | string[] | undefined;
    };
};

const emptyProjectForm = (): ProjectFormData => ({
    name: '',
    workspace_path: '',
    url: '',
    database_name: '',
    database_username: '',
    database_password: '',
    credential_username: '',
    credential_password: '',
    base_branch: '',
    default_reviewer_user_id: '',
});

const formatError = (error: string | string[] | undefined): string | null => {
    if (Array.isArray(error)) {
        return error.join(', ');
    }

    return error ?? null;
};

const yesNo = (value: boolean): string => (value ? 'set' : 'not set');

export default function ProjectsIndex() {
    const {
        projects: projectRows,
        reviewerOptions,
        errors,
    } = usePage<PageProps>().props;
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showEditModal, setShowEditModal] = useState(false);
    const [editingProject, setEditingProject] = useState<ProjectRecord | null>(
        null,
    );

    const createForm = useForm<ProjectFormData>(emptyProjectForm());
    const editForm = useForm<ProjectFormData>(emptyProjectForm());

    const resetCreateForm = () => {
        createForm.setData(emptyProjectForm());
        createForm.clearErrors();
        createForm.setDefaults(emptyProjectForm());
    };

    const openCreateModal = () => {
        resetCreateForm();
        setShowCreateModal(true);
    };

    const startEdit = (project: ProjectRecord) => {
        setEditingProject(project);
        editForm.setData({
            name: project.name,
            workspace_path: project.workspace_path,
            url: project.url ?? '',
            database_name: project.database_name ?? '',
            database_username: project.database_username ?? '',
            database_password: '',
            credential_username: project.credential_username ?? '',
            credential_password: '',
            base_branch: project.base_branch ?? '',
            default_reviewer_user_id:
                project.default_reviewer_user_id?.toString() ?? '',
        });
        editForm.clearErrors();
        setShowEditModal(true);
    };

    const closeEditModal = () => {
        setShowEditModal(false);
        setEditingProject(null);
        editForm.clearErrors();
        editForm.reset();
    };

    const submitCreate = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        createForm.post(projects.store.url(), {
            onSuccess: () => {
                setShowCreateModal(false);
                createForm.reset();
            },
        });
    };

    const submitEdit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (editingProject === null) {
            return;
        }

        editForm.patch(projects.update.url(editingProject.id), {
            onSuccess: closeEditModal,
        });
    };

    const confirmDelete = (project: ProjectRecord) => {
        if (
            !window.confirm(
                `Delete "${project.name}"? This action cannot be undone.`,
            )
        ) {
            return;
        }

        router.delete(projects.destroy.url(project.id), {
            onSuccess: () => {
                if (editingProject?.id === project.id) {
                    closeEditModal();
                }
            },
        });
    };

    return (
        <AppShell
            title="Projects"
            description="Repository, credential, and database context used by automation runs."
            actions={
                <Button
                    type="button"
                    variant="primary"
                    onClick={openCreateModal}
                >
                    New project
                </Button>
            }
        >
            <Head title="Projects" />

            <div className="space-y-4">
                {errors?.project ? (
                    <Alert tone="danger">{formatError(errors.project)}</Alert>
                ) : null}

                <DataTable>
                    <TableHead>
                        <tr>
                            <Th>Name</Th>
                            <Th>Workspace</Th>
                            <Th>URL</Th>
                            <Th>Database</Th>
                            <Th>Repo credentials</Th>
                            <Th>Base</Th>
                            <Th>Default reviewer</Th>
                            <Th>Actions</Th>
                        </tr>
                    </TableHead>
                    <TableBody>
                        {projectRows.map((project) => (
                            <tr
                                key={project.id}
                                className="hover:bg-surface-2/60"
                            >
                                <Td>
                                    <p className="font-medium text-ink">
                                        {project.name}
                                    </p>
                                    <p className="mt-1 text-xs text-ink-tertiary">
                                        #{project.id}
                                    </p>
                                </Td>
                                <Td className="font-mono text-xs text-ink-muted">
                                    {project.workspace_path}
                                </Td>
                                <Td className="max-w-xs truncate text-ink-muted">
                                    {project.url ?? 'n/a'}
                                </Td>
                                <Td className="text-ink-muted">
                                    <MetaLine
                                        label="name"
                                        value={project.database_name ?? 'n/a'}
                                    />
                                    <MetaLine
                                        label="user"
                                        value={
                                            project.database_username ?? 'n/a'
                                        }
                                    />
                                    <MetaLine
                                        label="password"
                                        value={yesNo(
                                            project.has_database_password,
                                        )}
                                    />
                                </Td>
                                <Td className="text-ink-muted">
                                    <MetaLine
                                        label="username"
                                        value={
                                            project.has_credential_username
                                                ? 'set'
                                                : 'not set'
                                        }
                                    />
                                    <MetaLine
                                        label="password"
                                        value={yesNo(
                                            project.has_credential_password,
                                        )}
                                    />
                                </Td>
                                <Td>
                                    <Badge>
                                        {project.base_branch ?? 'main'}
                                    </Badge>
                                </Td>
                                <Td className="text-ink-muted">
                                    {project.default_reviewer ? (
                                        <MetaLine
                                            label={
                                                project.default_reviewer.name
                                            }
                                            value={`@${project.default_reviewer.github_username}`}
                                        />
                                    ) : (
                                        'n/a'
                                    )}
                                </Td>
                                <Td>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            onClick={() => startEdit(project)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="danger"
                                            onClick={() =>
                                                confirmDelete(project)
                                            }
                                        >
                                            Delete
                                        </Button>
                                    </div>
                                </Td>
                            </tr>
                        ))}
                        {projectRows.length === 0 ? (
                            <tr>
                                <Td
                                    className="py-8 text-center text-ink-subtle"
                                    colSpan={8}
                                >
                                    No projects yet.
                                </Td>
                            </tr>
                        ) : null}
                    </TableBody>
                </DataTable>
            </div>

            <ProjectModal
                show={showCreateModal}
                title="Create project"
                form={createForm}
                reviewerOptions={reviewerOptions}
                isEditing={false}
                processingLabel="Creating..."
                submitLabel="Create"
                onClose={() => setShowCreateModal(false)}
                onSubmit={submitCreate}
            />

            <ProjectModal
                show={showEditModal}
                title={
                    editingProject
                        ? `Edit project #${editingProject.id}`
                        : 'Edit project'
                }
                form={editForm}
                reviewerOptions={reviewerOptions}
                isEditing
                processingLabel="Saving..."
                submitLabel="Save"
                onClose={closeEditModal}
                onSubmit={submitEdit}
            />
        </AppShell>
    );
}

function ProjectModal({
    show,
    title,
    form,
    reviewerOptions,
    isEditing,
    processingLabel,
    submitLabel,
    onClose,
    onSubmit,
}: {
    show: boolean;
    title: string;
    form: ReturnType<typeof useForm<ProjectFormData>>;
    reviewerOptions: ReviewerOption[];
    isEditing: boolean;
    processingLabel: string;
    submitLabel: string;
    onClose: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
    return (
        <Modal show={show} onClose={onClose} title={title}>
            <form onSubmit={onSubmit} className="space-y-4">
                <ProjectFormFields
                    form={form}
                    reviewerOptions={reviewerOptions}
                    isEditing={isEditing}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={form.processing}
                    >
                        {form.processing ? processingLabel : submitLabel}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function ProjectFormFields({
    form,
    reviewerOptions,
    isEditing,
}: {
    form: {
        data: ProjectFormData;
        setData: (
            key: keyof ProjectFormData,
            value: ProjectFormData[keyof ProjectFormData],
        ) => void;
        errors: Record<string, string | string[]>;
    };
    reviewerOptions: ReviewerOption[];
    isEditing: boolean;
}) {
    return (
        <div className="space-y-4">
            <Field label="Name" error={formatError(form.errors.name)}>
                <Input
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </Field>

            <Field
                label="Workspace path"
                error={formatError(form.errors.workspace_path)}
            >
                <Input
                    value={form.data.workspace_path}
                    onChange={(event) =>
                        form.setData('workspace_path', event.target.value)
                    }
                />
            </Field>

            <Field label="Repository URL" error={formatError(form.errors.url)}>
                <Input
                    value={form.data.url}
                    onChange={(event) =>
                        form.setData('url', event.target.value)
                    }
                />
            </Field>

            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Database name">
                    <Input
                        value={form.data.database_name}
                        onChange={(event) =>
                            form.setData('database_name', event.target.value)
                        }
                    />
                </Field>
                <Field label="Database username">
                    <Input
                        value={form.data.database_username}
                        onChange={(event) =>
                            form.setData(
                                'database_username',
                                event.target.value,
                            )
                        }
                    />
                </Field>
            </div>

            <Field
                label="Database password"
                error={formatError(form.errors.database_password)}
            >
                <Input
                    type="password"
                    value={form.data.database_password}
                    onChange={(event) =>
                        form.setData('database_password', event.target.value)
                    }
                    placeholder={
                        isEditing ? 'Leave blank to keep existing' : ''
                    }
                    autoComplete="new-password"
                />
            </Field>

            <div className="grid gap-4 md:grid-cols-2">
                <Field
                    label="Credential username"
                    error={formatError(form.errors.credential_username)}
                >
                    <Input
                        value={form.data.credential_username}
                        onChange={(event) =>
                            form.setData(
                                'credential_username',
                                event.target.value,
                            )
                        }
                    />
                </Field>
                <Field
                    label="Credential password"
                    error={formatError(form.errors.credential_password)}
                >
                    <Input
                        type="password"
                        value={form.data.credential_password}
                        onChange={(event) =>
                            form.setData(
                                'credential_password',
                                event.target.value,
                            )
                        }
                        placeholder={
                            isEditing ? 'Leave blank to keep existing' : ''
                        }
                        autoComplete="new-password"
                    />
                </Field>
            </div>

            <Field label="Default base branch">
                <Input
                    value={form.data.base_branch}
                    onChange={(event) =>
                        form.setData('base_branch', event.target.value)
                    }
                    placeholder="main"
                />
            </Field>

            <Field
                label="Default reviewer"
                error={formatError(form.errors.default_reviewer_user_id)}
            >
                <Select
                    value={form.data.default_reviewer_user_id}
                    onChange={(event) =>
                        form.setData(
                            'default_reviewer_user_id',
                            event.target.value,
                        )
                    }
                >
                    <option value="">Use task assignee</option>
                    {reviewerOptions.map((user) => (
                        <option key={user.id} value={user.id.toString()}>
                            {user.github_username
                                ? `${user.name} (@${user.github_username})`
                                : `${user.name} (no GitHub username)`}
                        </option>
                    ))}
                </Select>
            </Field>

            <Panel className="space-y-2 p-3 text-xs text-ink-subtle">
                <p>
                    Password fields are write-only. When editing, leave them
                    blank to keep the current values.
                </p>
                <p>
                    For repository credentials, both username and password must
                    be provided or both left blank.
                </p>
            </Panel>
        </div>
    );
}

function MetaLine({ label, value }: { label: string; value: ReactNode }) {
    return (
        <p className="text-xs">
            <span className="text-ink-tertiary">{label}: </span>
            {value}
        </p>
    );
}
