import type {
    AnchorHTMLAttributes,
    ButtonHTMLAttributes,
    TdHTMLAttributes,
    InputHTMLAttributes,
    ReactNode,
    SelectHTMLAttributes,
    TextareaHTMLAttributes,
} from 'react';
import { cn } from '@/lib/utils';

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'success';

const buttonClasses: Record<ButtonVariant, string> = {
    primary:
        'border-primary bg-primary text-white hover:border-primary-hover hover:bg-primary-hover focus-visible:outline-primary-focus',
    secondary:
        'border-hairline-strong bg-surface-2 text-ink hover:border-hairline-strong hover:bg-surface-3 focus-visible:outline-primary-focus',
    ghost: 'border-transparent bg-transparent text-ink-muted hover:bg-surface-2 hover:text-ink focus-visible:outline-primary-focus',
    danger: 'border-danger/40 bg-danger/10 text-red-200 hover:bg-danger/20 focus-visible:outline-danger',
    success:
        'border-success/40 bg-success/15 text-green-100 hover:bg-success/25 focus-visible:outline-success',
};

export function Button({
    variant = 'secondary',
    className,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant }) {
    return (
        <button
            {...props}
            className={cn(
                'inline-flex min-h-9 cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                buttonClasses[variant],
                className,
            )}
        />
    );
}

export function ActionLink({
    variant = 'secondary',
    className,
    ...props
}: AnchorHTMLAttributes<HTMLAnchorElement> & { variant?: ButtonVariant }) {
    return (
        <a
            {...props}
            className={cn(
                'inline-flex min-h-9 cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2',
                buttonClasses[variant],
                className,
            )}
        />
    );
}

export function Panel({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'rounded-lg border border-hairline bg-surface-1 text-ink',
                className,
            )}
        >
            {children}
        </section>
    );
}

export function Alert({
    children,
    tone = 'success',
}: {
    children: ReactNode;
    tone?: 'success' | 'danger';
}) {
    return (
        <div
            className={cn(
                'rounded-md border px-3 py-2 text-sm',
                tone === 'success'
                    ? 'border-success/30 bg-success/10 text-green-100'
                    : 'border-danger/30 bg-danger/10 text-red-100',
            )}
        >
            {children}
        </div>
    );
}

const badgeClasses: Record<string, string> = {
    draft: 'border-hairline-strong bg-surface-3 text-ink-muted',
    pending_approval: 'border-warning/35 bg-warning/10 text-yellow-100',
    approved: 'border-info/35 bg-info/10 text-blue-100',
    running: 'border-primary/40 bg-primary/15 text-indigo-100',
    pr_created: 'border-primary-hover/35 bg-primary/15 text-indigo-100',
    done: 'border-success/35 bg-success/12 text-green-100',
    completed: 'border-success/35 bg-success/12 text-green-100',
    failed: 'border-danger/35 bg-danger/12 text-red-100',
    rejected: 'border-hairline-strong bg-surface-2 text-ink-tertiary',
    pending: 'border-warning/35 bg-warning/10 text-yellow-100',
    processing: 'border-info/35 bg-info/10 text-blue-100',
    info: 'border-info/35 bg-info/10 text-blue-100',
    warning: 'border-warning/35 bg-warning/10 text-yellow-100',
    error: 'border-danger/35 bg-danger/12 text-red-100',
    debug: 'border-hairline-strong bg-surface-3 text-ink-subtle',
    notice: 'border-hairline-strong bg-surface-3 text-ink-subtle',
};

export function Badge({
    value,
    children,
    className,
}: {
    value?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium',
                value ? badgeClasses[value] : null,
                value && badgeClasses[value]
                    ? null
                    : 'border-hairline-strong bg-surface-3 text-ink-muted',
                className,
            )}
        >
            {children}
        </span>
    );
}

export function DataTable({ children }: { children: ReactNode }) {
    return (
        <div className="overflow-x-auto rounded-lg border border-hairline bg-surface-1">
            <table className="min-w-full divide-y divide-hairline text-sm">
                {children}
            </table>
        </div>
    );
}

export function TableHead({ children }: { children: ReactNode }) {
    return (
        <thead className="bg-surface-2 text-left text-xs text-ink-subtle">
            {children}
        </thead>
    );
}

export function TableBody({ children }: { children: ReactNode }) {
    return <tbody className="divide-y divide-hairline">{children}</tbody>;
}

export function Th({ children }: { children: ReactNode }) {
    return <th className="px-3 py-2 font-medium">{children}</th>;
}

export function Td({
    children,
    className,
    ...props
}: TdHTMLAttributes<HTMLTableCellElement>) {
    return (
        <td {...props} className={cn('px-3 py-3 align-top', className)}>
            {children}
        </td>
    );
}

export function Modal({
    show,
    onClose,
    title,
    children,
    size = 'lg',
    closeButton = 'text',
}: {
    show: boolean;
    onClose: () => void;
    title: string;
    children: ReactNode;
    size?: 'md' | 'lg' | 'xl';
    closeButton?: 'text' | 'icon';
}) {
    if (!show) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-overlay/70 p-4"
            role="dialog"
            aria-modal="true"
            aria-label={title}
        >
            <div
                className={cn(
                    'max-h-[90vh] w-full overflow-hidden rounded-lg border border-hairline bg-surface-1 shadow-2xl',
                    size === 'md' && 'max-w-2xl',
                    size === 'lg' && 'max-w-4xl',
                    size === 'xl' && 'max-w-5xl',
                )}
            >
                <div className="flex items-center justify-between gap-3 border-b border-hairline bg-surface-2 px-5 py-4">
                    <h2 className="truncate text-base font-semibold text-ink">
                        {title}
                    </h2>
                    {closeButton === 'icon' ? (
                        <Button
                            type="button"
                            variant="ghost"
                            aria-label="Close"
                            onClick={onClose}
                            className="size-8 min-h-8 px-0 py-0 text-lg leading-none"
                        >
                            &times;
                        </Button>
                    ) : (
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Close
                        </Button>
                    )}
                </div>
                <div className="max-h-[calc(90vh-73px)] overflow-y-auto p-5">
                    {children}
                </div>
            </div>
        </div>
    );
}

export function Drawer({
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
            className="fixed inset-0 z-50 bg-overlay/70"
            role="dialog"
            aria-modal="true"
            aria-label={title}
        >
            <button
                type="button"
                aria-label="Close details"
                onClick={onClose}
                className="absolute inset-0 h-full w-full cursor-default"
            />
            <aside className="absolute top-0 right-0 flex h-full w-full max-w-3xl flex-col border-l border-hairline bg-surface-1 shadow-2xl sm:w-[min(760px,92vw)]">
                <div className="flex items-center justify-between gap-3 border-b border-hairline bg-surface-2 px-5 py-4">
                    <h2 className="truncate text-base font-semibold text-ink">
                        {title}
                    </h2>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Close
                    </Button>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                    {children}
                </div>
            </aside>
        </div>
    );
}

export function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string | null;
    children: ReactNode;
}) {
    return (
        <label className="grid gap-1.5 text-sm text-ink-muted">
            <span>{label}</span>
            {children}
            {error ? (
                <span className="text-xs text-red-200">{error}</span>
            ) : null}
        </label>
    );
}

export function Input(props: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            className={cn(
                'min-h-9 rounded-md border border-hairline-strong bg-surface-2 px-3 py-2 text-sm text-ink transition outline-none placeholder:text-ink-tertiary focus:border-primary-focus focus:ring-2 focus:ring-primary-focus/30',
                props.className,
            )}
        />
    );
}

export function Textarea(props: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            {...props}
            className={cn(
                'rounded-md border border-hairline-strong bg-surface-2 px-3 py-2 text-sm text-ink transition outline-none placeholder:text-ink-tertiary focus:border-primary-focus focus:ring-2 focus:ring-primary-focus/30',
                props.className,
            )}
        />
    );
}

export function Select(props: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select
            {...props}
            className={cn(
                'min-h-9 rounded-md border border-hairline-strong bg-surface-2 px-3 py-2 text-sm text-ink transition outline-none focus:border-primary-focus focus:ring-2 focus:ring-primary-focus/30',
                props.className,
            )}
        />
    );
}
