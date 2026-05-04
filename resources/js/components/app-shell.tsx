import { Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type { ReactNode } from 'react';
import { ToastStack } from '@/components/toast-stack';
import type { Toast } from '@/components/toast-stack';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import inputSources from '@/routes/input-sources';
import logs from '@/routes/logs';
import profile from '@/routes/profile';
import projects from '@/routes/projects';
import tasks from '@/routes/tasks';
import users from '@/routes/users';
import type { Auth } from '@/types';

const navigation = [
    { label: 'Tasks', href: tasks.index.url(), match: '/tasks' },
    { label: 'Projects', href: projects.index.url(), match: '/projects' },
    {
        label: 'Sources',
        href: inputSources.index.url(),
        match: '/input-sources',
    },
    { label: 'Logs', href: logs.index.url(), match: '/logs' },
];

const emptyToasts: Toast[] = [];

export function AppShell({
    title,
    description,
    actions,
    children,
    toasts = emptyToasts,
    width = 'wide',
    showHeaderText = true,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
    toasts?: Toast[];
    width?: 'wide' | 'full';
    showHeaderText?: boolean;
}) {
    const { url, props } = usePage<{
        auth: Auth;
        flash?: { status?: string };
    }>();
    const pathname = url.split('?')[0] ?? url;
    const authUser = props.auth.user;
    const toastMessages = useMemo(
        () => [
            ...(props.flash?.status
                ? [
                      {
                          id: `flash-status-${props.flash.status}`,
                          tone: 'success' as const,
                          message: props.flash.status,
                      },
                  ]
                : []),
            ...toasts,
        ],
        [props.flash?.status, toasts],
    );
    const visibleNavigation =
        authUser?.role === 'admin'
            ? [
                  ...navigation,
                  { label: 'Users', href: users.index.url(), match: '/users' },
              ]
            : navigation;

    return (
        <div className="min-h-screen bg-canvas text-ink">
            <ToastStack toasts={toastMessages} />

            <header className="sticky top-0 z-30 border-b border-hairline bg-canvas/95 backdrop-blur">
                <div className="mx-auto flex h-14 max-w-[1500px] items-center justify-between gap-4 px-4 sm:px-6">
                    <Link
                        href={tasks.index.url()}
                        className="flex items-center gap-2 text-sm font-semibold text-ink"
                    >
                        <span className="grid size-6 place-items-center rounded-md border border-primary/40 bg-primary/20 text-[11px] text-primary-hover">
                            TF
                        </span>
                        <span>Task Fox</span>
                    </Link>
                    <nav className="flex min-w-0 flex-1 items-center justify-center gap-1 overflow-x-auto">
                        {visibleNavigation.map((item) => {
                            const isActive = pathname.startsWith(item.match);

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    prefetch
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition',
                                        isActive
                                            ? 'bg-surface-3 text-ink'
                                            : 'text-ink-subtle hover:bg-surface-2 hover:text-ink-muted',
                                    )}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>
                    {authUser ? (
                        <div className="flex shrink-0 items-center gap-2">
                            <Link
                                href={profile.edit.url()}
                                className="hidden max-w-44 truncate rounded-md px-3 py-1.5 text-sm font-medium text-ink-subtle transition hover:bg-surface-2 hover:text-ink sm:block"
                            >
                                {authUser.name}
                            </Link>
                            <Link
                                href={logout.url()}
                                method="post"
                                as="button"
                                className="rounded-md border border-hairline-strong bg-surface-2 px-3 py-1.5 text-sm font-medium text-ink transition hover:bg-surface-3"
                            >
                                Logout
                            </Link>
                        </div>
                    ) : null}
                </div>
            </header>

            <main
                className={cn(
                    'mx-auto w-full px-4 py-5 sm:px-6',
                    width === 'wide' ? 'max-w-[1500px]' : 'max-w-none',
                )}
            >
                {showHeaderText || actions ? (
                    <div className="mb-5 flex flex-col gap-4 border-b border-hairline pb-5 lg:flex-row lg:items-end lg:justify-between">
                        {showHeaderText ? (
                            <div className="min-w-0">
                                <h1 className="text-xl leading-tight font-semibold text-ink sm:text-2xl">
                                    {title}
                                </h1>
                                {description ? (
                                    <p className="mt-1 max-w-3xl text-sm leading-6 text-ink-subtle">
                                        {description}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                        {actions ? (
                            <div className="flex flex-wrap items-center gap-2">
                                {actions}
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {children}
            </main>
        </div>
    );
}
