import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '@/components/app-shell';
import {
    Badge,
    Button,
    DataTable,
    Modal,
    TableBody,
    TableHead,
    Td,
    Th,
} from '@/components/ui';
import { formatDisplayDateTime } from '@/lib/utils';
import tasks from '@/routes/tasks';

type TaskRunLogEntry = {
    id: number;
    level: string;
    message: string;
    context: Record<string, unknown> | null;
    created_at: string | null;
    task_id: number | null;
    task_title: string | null;
    run_id: number | null;
    run_status: string | null;
    input_source_id: number | null;
    input_source_title: string | null;
};

type LogsPageProps = {
    logs: TaskRunLogEntry[];
};

const safeJson = (value: unknown): string | null => {
    if (value === null || value === undefined) {
        return null;
    }

    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
};

export default function LogsIndex() {
    const { logs } = usePage<LogsPageProps>().props;
    const [selectedContext, setSelectedContext] = useState<{
        title: string;
        json: string;
    } | null>(null);

    return (
        <AppShell
            title="Task run logs"
            description="Centralized execution logs for task runs and external tooling messages."
        >
            <Head title="Logs" />

            <DataTable>
                <TableHead>
                    <tr>
                        <Th>Time</Th>
                        <Th>Level</Th>
                        <Th>Message</Th>
                        <Th>Task</Th>
                        <Th>Input source</Th>
                        <Th>Run</Th>
                        <Th>Context</Th>
                    </tr>
                </TableHead>
                <TableBody>
                    {logs.map((log) => (
                        <tr key={log.id} className="hover:bg-surface-2/60">
                            <Td className="text-ink-subtle">
                                <time dateTime={log.created_at ?? undefined}>
                                    {formatDisplayDateTime(log.created_at)}
                                </time>
                            </Td>
                            <Td>
                                <Badge value={log.level}>{log.level}</Badge>
                            </Td>
                            <Td className="max-w-xl text-ink-muted">
                                {log.message}
                            </Td>
                            <Td className="text-ink-muted">
                                {log.task_id ? (
                                    <Link
                                        href={tasks.index.url({
                                            query: {
                                                task: log.task_id,
                                            },
                                        })}
                                        className="text-primary-hover underline"
                                    >
                                        {log.task_title
                                            ? `#${log.task_id} ${log.task_title}`
                                            : `Task #${log.task_id}`}
                                    </Link>
                                ) : (
                                    'None'
                                )}
                            </Td>
                            <Td className="text-ink-muted">
                                {log.input_source_id
                                    ? `#${log.input_source_id} ${log.input_source_title ?? 'Input source'}`
                                    : 'None'}
                            </Td>
                            <Td className="text-ink-muted">
                                {log.run_id
                                    ? `${log.run_id} (${log.run_status ?? 'unknown'})`
                                    : 'None'}
                            </Td>
                            <Td className="text-ink-subtle">
                                {safeJson(log.context) ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="min-h-7 px-2 py-1 text-xs"
                                        onClick={() => {
                                            const json = safeJson(log.context);

                                            if (json) {
                                                setSelectedContext({
                                                    title: `Log #${log.id} context`,
                                                    json,
                                                });
                                            }
                                        }}
                                    >
                                        View
                                    </Button>
                                ) : (
                                    'None'
                                )}
                            </Td>
                        </tr>
                    ))}
                    {logs.length === 0 ? (
                        <tr>
                            <Td
                                colSpan={7}
                                className="py-8 text-center text-ink-subtle"
                            >
                                No logs available.
                            </Td>
                        </tr>
                    ) : null}
                </TableBody>
            </DataTable>

            <Modal
                show={selectedContext !== null}
                onClose={() => setSelectedContext(null)}
                title={selectedContext?.title ?? 'Log context'}
                size="xl"
            >
                {selectedContext ? (
                    <pre className="max-h-[70vh] overflow-auto rounded-md border border-hairline bg-canvas p-4 font-mono text-xs whitespace-pre-wrap text-ink-subtle">
                        {selectedContext.json}
                    </pre>
                ) : null}
            </Modal>
        </AppShell>
    );
}
