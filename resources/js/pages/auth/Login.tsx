import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, Button, Field, Input } from '@/components/ui';
import login from '@/routes/login';

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
};

type PageProps = {
    errors?: {
        email?: string;
        password?: string;
    };
};

export default function Login() {
    const { errors } = usePage<PageProps>().props;
    const form = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.post(login.store.url(), {
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <main className="grid min-h-screen place-items-center bg-canvas px-4 py-10 text-ink">
            <Head title="Login" />

            <section className="w-full max-w-md rounded-lg border border-hairline bg-surface-1 p-6">
                <div className="mb-6">
                    <div className="mb-4 grid size-9 place-items-center rounded-md border border-primary/40 bg-primary/20 text-sm font-semibold text-primary-hover">
                        TF
                    </div>
                    <h1 className="text-2xl leading-tight font-semibold">
                        Sign in to Task Fox
                    </h1>
                    <p className="mt-2 text-sm text-ink-subtle">
                        Use your workspace account to continue.
                    </p>
                </div>

                {errors?.email ? (
                    <div className="mb-4">
                        <Alert tone="danger">{errors.email}</Alert>
                    </div>
                ) : null}

                <form className="grid gap-4" onSubmit={submit}>
                    <Field label="Email" error={form.errors.email}>
                        <Input
                            type="email"
                            autoComplete="email"
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                            autoFocus
                        />
                    </Field>
                    <Field label="Password" error={form.errors.password}>
                        <Input
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(event) =>
                                form.setData('password', event.target.value)
                            }
                        />
                    </Field>
                    <label className="flex items-center gap-2 text-sm text-ink-muted">
                        <input
                            type="checkbox"
                            checked={form.data.remember}
                            onChange={(event) =>
                                form.setData('remember', event.target.checked)
                            }
                            className="size-4 rounded border-hairline-strong bg-surface-2 accent-primary"
                        />
                        Remember me
                    </label>
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={form.processing}
                        className="w-full"
                    >
                        {form.processing ? 'Signing in...' : 'Sign in'}
                    </Button>
                </form>
            </section>
        </main>
    );
}
