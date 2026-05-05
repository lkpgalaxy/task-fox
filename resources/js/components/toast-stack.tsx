import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

export type Toast = {
    id: string;
    tone: 'success' | 'danger';
    message: string;
};

const autoHideMs = 5000;

const toastClasses: Record<Toast['tone'], string> = {
    success: 'border-success/35 bg-success/12 text-green-100',
    danger: 'border-danger/35 bg-danger/12 text-red-100',
};

export function ToastStack({ toasts }: { toasts: Toast[] }) {
    const [dismissedToastIds, setDismissedToastIds] = useState<string[]>([]);

    useEffect(() => {
        const timers = toasts.map((toast) =>
            window.setTimeout(() => {
                setDismissedToastIds((current) =>
                    current.includes(toast.id) ? current : [...current, toast.id],
                );
            }, autoHideMs),
        );

        return () => {
            timers.forEach((timer) => window.clearTimeout(timer));
        };
    }, [toasts]);

    const visibleToasts = toasts.filter(
        (toast) => !dismissedToastIds.includes(toast.id),
    );

    if (visibleToasts.length === 0) {
        return null;
    }

    return (
        <div
            className="fixed top-[4.5rem] right-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2 sm:right-6"
            aria-live="polite"
            aria-atomic="true"
        >
            {visibleToasts.map((toast) => (
                <div
                    key={toast.id}
                    className={cn(
                        'flex items-start gap-3 rounded-lg border bg-surface-1 px-3 py-2.5 text-sm shadow-2xl',
                        toastClasses[toast.tone],
                    )}
                >
                    <div className="min-w-0 flex-1 leading-5">
                        {toast.message}
                    </div>
                    <button
                        type="button"
                        className="grid size-6 shrink-0 cursor-pointer place-items-center rounded-md text-base leading-none text-ink-subtle transition hover:bg-surface-3 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-focus"
                        aria-label="Dismiss message"
                        onClick={() =>
                            setDismissedToastIds((current) =>
                                current.includes(toast.id)
                                    ? current
                                    : [...current, toast.id],
                            )
                        }
                    >
                        x
                    </button>
                </div>
            ))}
        </div>
    );
}
