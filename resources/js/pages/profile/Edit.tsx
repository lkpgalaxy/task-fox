import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AppShell } from '@/components/app-shell';
import { Button, Field, Input, Panel } from '@/components/ui';
import profile from '@/routes/profile';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function ProfileEdit() {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    const profileForm = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        github_username: user?.github_username ?? '',
        github_token: '',
    });

    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submitProfile = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        profileForm.patch(profile.update.url(), {
            onSuccess: () => profileForm.reset('github_token'),
        });
    };

    const submitPassword = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        passwordForm.patch(profile.password.update.url(), {
            onSuccess: () => passwordForm.reset(),
        });
    };

    return (
        <AppShell
            title="Profile"
            description="Manage your account identity and password."
        >
            <Head title="Profile" />

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel className="p-5">
                    <form className="grid gap-4" onSubmit={submitProfile}>
                        <h2 className="text-base font-semibold text-ink">
                            Account details
                        </h2>
                        <Field label="Name" error={profileForm.errors.name}>
                            <Input
                                value={profileForm.data.name}
                                onChange={(event) =>
                                    profileForm.setData(
                                        'name',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field label="Email" error={profileForm.errors.email}>
                            <Input
                                type="email"
                                value={profileForm.data.email}
                                onChange={(event) =>
                                    profileForm.setData(
                                        'email',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="GitHub username"
                            error={profileForm.errors.github_username}
                        >
                            <Input
                                value={profileForm.data.github_username}
                                onChange={(event) =>
                                    profileForm.setData(
                                        'github_username',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="GitHub token"
                            error={profileForm.errors.github_token}
                        >
                            <Input
                                type="password"
                                autoComplete="off"
                                value={profileForm.data.github_token}
                                placeholder={
                                    user?.has_github_token
                                        ? 'Token saved'
                                        : 'No token saved'
                                }
                                onChange={(event) =>
                                    profileForm.setData(
                                        'github_token',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <div className="rounded-md border border-hairline bg-surface-2 px-3 py-2 text-sm text-ink-muted">
                            {user?.has_github_token
                                ? 'GitHub token saved. Leave blank to keep it.'
                                : 'No GitHub token saved. Enter a token to enable pull request creation.'}
                        </div>
                        <div>
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={profileForm.processing}
                            >
                                {profileForm.processing
                                    ? 'Saving...'
                                    : 'Save profile'}
                            </Button>
                        </div>
                    </form>
                </Panel>

                <Panel className="p-5">
                    <form className="grid gap-4" onSubmit={submitPassword}>
                        <h2 className="text-base font-semibold text-ink">
                            Password
                        </h2>
                        <Field
                            label="Current password"
                            error={passwordForm.errors.current_password}
                        >
                            <Input
                                type="password"
                                autoComplete="current-password"
                                value={passwordForm.data.current_password}
                                onChange={(event) =>
                                    passwordForm.setData(
                                        'current_password',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="New password"
                            error={passwordForm.errors.password}
                        >
                            <Input
                                type="password"
                                autoComplete="new-password"
                                value={passwordForm.data.password}
                                onChange={(event) =>
                                    passwordForm.setData(
                                        'password',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Confirm password"
                            error={passwordForm.errors.password_confirmation}
                        >
                            <Input
                                type="password"
                                autoComplete="new-password"
                                value={passwordForm.data.password_confirmation}
                                onChange={(event) =>
                                    passwordForm.setData(
                                        'password_confirmation',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <div>
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={passwordForm.processing}
                            >
                                {passwordForm.processing
                                    ? 'Updating...'
                                    : 'Update password'}
                            </Button>
                        </div>
                    </form>
                </Panel>
            </div>
        </AppShell>
    );
}
