import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AppShell } from '@/components/app-shell';
import { Button, Field, Input, Panel, Select } from '@/components/ui';
import profile from '@/routes/profile';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
    automationSettings: AutomationSettings | null;
};

type AutomationSettings = {
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

export default function ProfileEdit() {
    const { auth, automationSettings } = usePage<PageProps>().props;
    const user = auth.user;
    const isAdmin = user?.role === 'admin';

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

    const automationForm = useForm({
        analyze_source_model: automationSettings?.analyze_source_model ?? '',
        analyze_source_reasoning_effort:
            automationSettings?.analyze_source_reasoning_effort ?? '',
        plan_model: automationSettings?.plan_model ?? '',
        plan_reasoning_effort: automationSettings?.plan_reasoning_effort ?? '',
        implement_model: automationSettings?.implement_model ?? '',
        implement_reasoning_effort:
            automationSettings?.implement_reasoning_effort ?? '',
        review_model: automationSettings?.review_model ?? '',
        review_reasoning_effort:
            automationSettings?.review_reasoning_effort ?? '',
        commit_message_model: automationSettings?.commit_message_model ?? '',
        commit_message_reasoning_effort:
            automationSettings?.commit_message_reasoning_effort ?? '',
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

    const submitAutomation = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        automationForm.patch(profile.automation.update.url(), {
            preserveScroll: true,
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

                {isAdmin ? (
                    <Panel className="p-5 lg:col-span-2">
                        <form
                            className="grid gap-4"
                            onSubmit={submitAutomation}
                        >
                            <div className="space-y-1">
                                <h2 className="text-base font-semibold text-ink">
                                    Automation models
                                </h2>
                                <p className="text-sm text-ink-muted">
                                    Leave model or effort blank to use
                                    Codex&apos;s default for that step.
                                </p>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <AutomationStepFields
                                    label="Analyze source"
                                    model={
                                        automationForm.data.analyze_source_model
                                    }
                                    effort={
                                        automationForm.data
                                            .analyze_source_reasoning_effort
                                    }
                                    modelError={
                                        automationForm.errors
                                            .analyze_source_model
                                    }
                                    effortError={
                                        automationForm.errors
                                            .analyze_source_reasoning_effort
                                    }
                                    onModelChange={(value) =>
                                        automationForm.setData(
                                            'analyze_source_model',
                                            value,
                                        )
                                    }
                                    onEffortChange={(value) =>
                                        automationForm.setData(
                                            'analyze_source_reasoning_effort',
                                            value,
                                        )
                                    }
                                />
                                <AutomationStepFields
                                    label="Plan"
                                    model={automationForm.data.plan_model}
                                    effort={
                                        automationForm.data
                                            .plan_reasoning_effort
                                    }
                                    modelError={
                                        automationForm.errors.plan_model
                                    }
                                    effortError={
                                        automationForm.errors
                                            .plan_reasoning_effort
                                    }
                                    onModelChange={(value) =>
                                        automationForm.setData(
                                            'plan_model',
                                            value,
                                        )
                                    }
                                    onEffortChange={(value) =>
                                        automationForm.setData(
                                            'plan_reasoning_effort',
                                            value,
                                        )
                                    }
                                />
                                <AutomationStepFields
                                    label="Implement"
                                    model={automationForm.data.implement_model}
                                    effort={
                                        automationForm.data
                                            .implement_reasoning_effort
                                    }
                                    modelError={
                                        automationForm.errors.implement_model
                                    }
                                    effortError={
                                        automationForm.errors
                                            .implement_reasoning_effort
                                    }
                                    onModelChange={(value) =>
                                        automationForm.setData(
                                            'implement_model',
                                            value,
                                        )
                                    }
                                    onEffortChange={(value) =>
                                        automationForm.setData(
                                            'implement_reasoning_effort',
                                            value,
                                        )
                                    }
                                />
                                <AutomationStepFields
                                    label="Review"
                                    model={automationForm.data.review_model}
                                    effort={
                                        automationForm.data
                                            .review_reasoning_effort
                                    }
                                    modelError={
                                        automationForm.errors.review_model
                                    }
                                    effortError={
                                        automationForm.errors
                                            .review_reasoning_effort
                                    }
                                    onModelChange={(value) =>
                                        automationForm.setData(
                                            'review_model',
                                            value,
                                        )
                                    }
                                    onEffortChange={(value) =>
                                        automationForm.setData(
                                            'review_reasoning_effort',
                                            value,
                                        )
                                    }
                                />
                                <AutomationStepFields
                                    label="Commit message"
                                    model={
                                        automationForm.data.commit_message_model
                                    }
                                    effort={
                                        automationForm.data
                                            .commit_message_reasoning_effort
                                    }
                                    modelError={
                                        automationForm.errors
                                            .commit_message_model
                                    }
                                    effortError={
                                        automationForm.errors
                                            .commit_message_reasoning_effort
                                    }
                                    onModelChange={(value) =>
                                        automationForm.setData(
                                            'commit_message_model',
                                            value,
                                        )
                                    }
                                    onEffortChange={(value) =>
                                        automationForm.setData(
                                            'commit_message_reasoning_effort',
                                            value,
                                        )
                                    }
                                />
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={automationForm.processing}
                                >
                                    {automationForm.processing
                                        ? 'Saving...'
                                        : 'Save automation models'}
                                </Button>
                            </div>
                        </form>
                    </Panel>
                ) : null}
            </div>
        </AppShell>
    );
}

type AutomationStepFieldsProps = {
    label: string;
    model: string;
    effort: string;
    modelError?: string;
    effortError?: string;
    onModelChange: (value: string) => void;
    onEffortChange: (value: string) => void;
};

function AutomationStepFields({
    label,
    model,
    effort,
    modelError,
    effortError,
    onModelChange,
    onEffortChange,
}: AutomationStepFieldsProps) {
    return (
        <div className="grid gap-3">
            <Field label={`${label} model`} error={modelError}>
                <Input
                    value={model}
                    onChange={(event) => onModelChange(event.target.value)}
                />
            </Field>
            <Field label={`${label} effort`} error={effortError}>
                <Select
                    value={effort}
                    onChange={(event) => onEffortChange(event.target.value)}
                >
                    <option value="">Codex default</option>
                    <option value="low">low</option>
                    <option value="medium">medium</option>
                    <option value="high">high</option>
                    <option value="xhigh">xhigh</option>
                </Select>
            </Field>
        </div>
    );
}
