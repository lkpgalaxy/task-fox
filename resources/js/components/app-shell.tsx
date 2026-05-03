import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import inputSources from '@/routes/input-sources';
import logs from '@/routes/logs';
import projects from '@/routes/projects';
import tasks from '@/routes/tasks';

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

export function AppShell({
    title,
    description,
    actions,
    children,
    width = 'wide',
    showHeaderText = true,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
    width?: 'wide' | 'full';
    showHeaderText?: boolean;
}) {
    const { url } = usePage();
    const pathname = url.split('?')[0] ?? url;

    return (
        <div className="min-h-screen bg-canvas text-ink">
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
                        {navigation.map((item) => {
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
