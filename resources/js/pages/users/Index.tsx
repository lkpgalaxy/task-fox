import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { AppShell } from '@/components/app-shell';
import {
    Alert,
    Badge,
    Button,
    DataTable,
    Field,
    Input,
    Modal,
    Select,
    TableBody,
    TableHead,
    Td,
    Th,
} from '@/components/ui';
import { formatDisplayDateTime } from '@/lib/utils';
import users from '@/routes/users';

type UserRecord = {
    id: number;
    name: string;
    email: string;
    github_username: string | null;
    role: 'admin' | 'user';
    disabled_at: string | null;
    created_at: string | null;
};

type UserFormData = {
    name: string;
    email: string;
    github_username: string;
    role: 'admin' | 'user';
    password: string;
    password_confirmation: string;
};

type PageProps = {
    users: UserRecord[];
    roles: Array<'admin' | 'user'>;
    errors?: {
        user?: string;
        role?: string;
    };
};

const emptyForm = (): UserFormData => ({
    name: '',
    email: '',
    github_username: '',
    role: 'user',
    password: '',
    password_confirmation: '',
});

export default function UsersIndex() {
    const { users: userRows, roles, errors } = usePage<PageProps>().props;
    const [showModal, setShowModal] = useState(false);
    const [editingUser, setEditingUser] = useState<UserRecord | null>(null);
    const form = useForm<UserFormData>(emptyForm());

    const openCreate = () => {
        setEditingUser(null);
        form.setData(emptyForm());
        form.clearErrors();
        setShowModal(true);
    };

    const openEdit = (user: UserRecord) => {
        setEditingUser(user);
        form.setData({
            name: user.name,
            email: user.email,
            github_username: user.github_username ?? '',
            role: user.role,
            password: '',
            password_confirmation: '',
        });
        form.clearErrors();
        setShowModal(true);
    };

    const closeModal = () => {
        setShowModal(false);
        setEditingUser(null);
        form.reset();
        form.clearErrors();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (editingUser) {
            form.patch(users.update.url(editingUser.id), {
                onSuccess: closeModal,
            });

            return;
        }

        form.post(users.store.url(), {
            onSuccess: closeModal,
        });
    };

    const toggleUser = (user: UserRecord) => {
        if (user.disabled_at) {
            router.patch(users.enable.url(user.id));

            return;
        }

        if (!window.confirm(`Disable ${user.name}?`)) {
            return;
        }

        router.patch(users.disable.url(user.id));
    };

    return (
        <AppShell
            title="Users"
            description="Manage access, roles, and account status."
            actions={
                <Button type="button" variant="primary" onClick={openCreate}>
                    New user
                </Button>
            }
        >
            <Head title="Users" />

            <div className="space-y-4">
                {errors?.user ? (
                    <Alert tone="danger">{errors.user}</Alert>
                ) : null}
                {errors?.role ? (
                    <Alert tone="danger">{errors.role}</Alert>
                ) : null}

                <DataTable>
                    <TableHead>
                        <tr>
                            <Th>Name</Th>
                            <Th>Email</Th>
                            <Th>GitHub</Th>
                            <Th>Role</Th>
                            <Th>Status</Th>
                            <Th>Created</Th>
                            <Th>Actions</Th>
                        </tr>
                    </TableHead>
                    <TableBody>
                        {userRows.map((user) => (
                            <tr key={user.id} className="hover:bg-surface-2/60">
                                <Td>
                                    <p className="font-medium text-ink">
                                        {user.name}
                                    </p>
                                    <p className="mt-1 text-xs text-ink-tertiary">
                                        #{user.id}
                                    </p>
                                </Td>
                                <Td className="text-ink-muted">{user.email}</Td>
                                <Td className="text-ink-muted">
                                    {user.github_username ?? 'None'}
                                </Td>
                                <Td>
                                    <Badge value={user.role}>{user.role}</Badge>
                                </Td>
                                <Td>
                                    <Badge
                                        value={
                                            user.disabled_at
                                                ? 'rejected'
                                                : 'done'
                                        }
                                    >
                                        {user.disabled_at
                                            ? 'disabled'
                                            : 'active'}
                                    </Badge>
                                </Td>
                                <Td className="text-ink-subtle">
                                    {formatDisplayDateTime(user.created_at)}
                                </Td>
                                <Td>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            onClick={() => openEdit(user)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            type="button"
                                            variant={
                                                user.disabled_at
                                                    ? 'success'
                                                    : 'danger'
                                            }
                                            onClick={() => toggleUser(user)}
                                        >
                                            {user.disabled_at
                                                ? 'Enable'
                                                : 'Disable'}
                                        </Button>
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </TableBody>
                </DataTable>
            </div>

            <Modal
                show={showModal}
                onClose={closeModal}
                title={editingUser ? `Edit ${editingUser.name}` : 'Create user'}
            >
                <form className="grid gap-4" onSubmit={submit}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </Field>
                        <Field label="Email" error={form.errors.email}>
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="GitHub username"
                            error={form.errors.github_username}
                        >
                            <Input
                                value={form.data.github_username}
                                onChange={(event) =>
                                    form.setData(
                                        'github_username',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field label="Role" error={form.errors.role}>
                            <Select
                                value={form.data.role}
                                onChange={(event) =>
                                    form.setData(
                                        'role',
                                        event.target.value as 'admin' | 'user',
                                    )
                                }
                            >
                                {roles.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Password" error={form.errors.password}>
                            <Input
                                type="password"
                                autoComplete="new-password"
                                placeholder={
                                    editingUser
                                        ? 'Leave blank to keep current'
                                        : 'Defaults to password'
                                }
                                value={form.data.password}
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Confirm password"
                            error={form.errors.password_confirmation}
                        >
                            <Input
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(event) =>
                                    form.setData(
                                        'password_confirmation',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button type="button" onClick={closeModal}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={form.processing}
                        >
                            {form.processing ? 'Saving...' : 'Save'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppShell>
    );
}
