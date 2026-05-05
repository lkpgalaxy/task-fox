import { Badge } from '@/components/ui';
import { cn } from '@/lib/utils';

export type WorkflowCheckpoint = {
    name: string;
    status: string;
    attempts: number;
    completed_at: string | null;
    failed_at: string | null;
    error: string | null;
};

type WorkflowCheckpointVisualState =
    | 'finished'
    | 'skipped'
    | 'running'
    | 'pending'
    | 'failed';

const completedCheckpointStatuses = ['completed', 'skipped'];

export const checkpointLabel = (name: string) =>
    name
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');

export const workflowCurrentCheckpoint = (
    checkpoints: WorkflowCheckpoint[] | undefined,
): WorkflowCheckpoint | null =>
    checkpoints?.find(
        (checkpoint) =>
            !completedCheckpointStatuses.includes(checkpoint.status),
    ) ?? null;

export const completedWorkflowCheckpointCount = (
    checkpoints: WorkflowCheckpoint[],
): number =>
    checkpoints.filter((checkpoint) =>
        completedCheckpointStatuses.includes(checkpoint.status),
    ).length;

export const shortWorkflowHash = (hash: string | undefined): string =>
    hash ? hash.slice(0, 12) : 'None';

const workflowCheckpointVisualState = (
    checkpoint: WorkflowCheckpoint,
): WorkflowCheckpointVisualState => {
    if (checkpoint.status === 'failed') {
        return 'failed';
    }

    if (checkpoint.status === 'running') {
        return 'running';
    }

    if (checkpoint.status === 'skipped') {
        return 'skipped';
    }

    if (completedCheckpointStatuses.includes(checkpoint.status)) {
        return 'finished';
    }

    return 'pending';
};

const checkpointTimestamp = (
    checkpoint: WorkflowCheckpoint,
    formatDate: (value: string | null) => string,
): string | null => {
    if (checkpoint.status === 'failed' && checkpoint.failed_at) {
        return `Failed ${formatDate(checkpoint.failed_at)}`;
    }

    if (
        completedCheckpointStatuses.includes(checkpoint.status) &&
        checkpoint.completed_at
    ) {
        return `Finished ${formatDate(checkpoint.completed_at)}`;
    }

    return null;
};

const attemptsLabel = (attempts: number): string =>
    `${attempts} ${attempts === 1 ? 'attempt' : 'attempts'}`;

export function WorkflowTimeline({
    checkpoints,
    formatDate,
}: {
    checkpoints: WorkflowCheckpoint[];
    formatDate: (value: string | null) => string;
}) {
    if (checkpoints.length === 0) {
        return (
            <p className="rounded-md border border-hairline bg-surface-2 p-3 text-sm text-ink-subtle">
                No workflow checkpoints recorded.
            </p>
        );
    }

    return (
        <ol className="space-y-0">
            {checkpoints.map((checkpoint, index) => {
                const visualState = workflowCheckpointVisualState(checkpoint);
                const timestamp = checkpointTimestamp(checkpoint, formatDate);

                return (
                    <li
                        key={`${checkpoint.name}-${index}`}
                        className="relative grid grid-cols-[1.5rem_minmax(0,1fr)] gap-3 pb-4 last:pb-0"
                    >
                        {index < checkpoints.length - 1 ? (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'absolute top-5 bottom-0 left-3 w-px -translate-x-1/2 bg-hairline',
                                    visualState === 'finished'
                                        ? 'bg-success/45'
                                        : null,
                                    visualState === 'skipped'
                                        ? 'bg-hairline-strong'
                                        : null,
                                    visualState === 'failed'
                                        ? 'bg-danger/45'
                                        : null,
                                    visualState === 'running'
                                        ? 'bg-primary/45'
                                        : null,
                                )}
                            />
                        ) : null}
                        <span
                            className={cn(
                                'relative z-10 mt-1 grid size-6 place-items-center rounded-full border bg-surface-1 text-[10px] font-semibold',
                                visualState === 'finished'
                                    ? 'border-success/50 bg-success/15 text-green-100'
                                    : null,
                                visualState === 'running'
                                    ? 'border-primary/60 bg-primary/20 text-indigo-100 ring-2 ring-primary-focus/25 motion-safe:animate-pulse'
                                    : null,
                                visualState === 'skipped'
                                    ? 'border-hairline-strong bg-surface-3 text-ink-subtle'
                                    : null,
                                visualState === 'pending'
                                    ? 'border-hairline-strong text-ink-tertiary'
                                    : null,
                                visualState === 'failed'
                                    ? 'border-danger/60 bg-danger/15 text-red-100'
                                    : null,
                            )}
                        >
                            {visualState === 'running' ? (
                                <span
                                    aria-hidden="true"
                                    className="absolute inset-0 rounded-full bg-primary/30 motion-safe:animate-ping motion-reduce:hidden"
                                />
                            ) : null}
                            <span className="relative z-10">
                                {checkpoint.status === 'skipped'
                                    ? '↷'
                                    : visualState === 'finished'
                                      ? '✓'
                                      : visualState === 'failed'
                                        ? '!'
                                        : index + 1}
                            </span>
                        </span>
                        <div
                            className={cn(
                                'rounded-md border border-hairline bg-surface-2 p-3',
                                visualState === 'running'
                                    ? 'border-primary/35 bg-primary/10'
                                    : null,
                                visualState === 'failed'
                                    ? 'border-danger/35 bg-danger/10'
                                    : null,
                            )}
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <p className="min-w-0 text-sm font-medium text-ink-muted">
                                    {checkpointLabel(checkpoint.name)}
                                </p>
                                <Badge value={checkpoint.status}>
                                    {checkpoint.status}
                                </Badge>
                            </div>
                            <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-subtle">
                                <span>
                                    {attemptsLabel(checkpoint.attempts)}
                                </span>
                                {timestamp ? <span>{timestamp}</span> : null}
                            </div>
                            {checkpoint.status === 'failed' &&
                            checkpoint.error ? (
                                <p className="mt-2 rounded border border-danger/30 bg-danger/10 p-2 text-xs leading-5 text-red-100">
                                    {checkpoint.error}
                                </p>
                            ) : null}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
