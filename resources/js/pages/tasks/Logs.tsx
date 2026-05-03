import { Head, Link, usePage } from '@inertiajs/react';
import tasks from '@/routes/tasks';

type AiRunLogEntry = {
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
    logs: AiRunLogEntry[];
};

const levelClass: Record<string, string> = {
    info: 'bg-sky-100 text-sky-800',
    warning: 'bg-amber-100 text-amber-800',
    error: 'bg-rose-100 text-rose-800',
    debug: 'bg-slate-100 text-slate-700',
    notice: 'bg-slate-100 text-slate-700',
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
    const page = usePage<LogsPageProps>();
    const { logs } = page.props;

    return (
        <div className="min-h-screen bg-slate-50 p-6 text-slate-900">
            <Head title="Logs" />

            <div className="mx-auto max-w-7xl space-y-4">
                <header className="rounded-lg bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-semibold">AI run logs</h1>
                            <p className="text-sm text-slate-500">
                                Centralized execution logs for task runs and external tooling messages.
                            </p>
                        </div>
                        <Link
                            href={tasks.index.url()}
                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50"
                        >
                            Back to tasks
                        </Link>
                    </div>
                </header>

                <section className="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-100">
                            <tr className="text-left text-xs text-slate-600">
                                <th className="px-3 py-2 font-medium">Time</th>
                                <th className="px-3 py-2 font-medium">Level</th>
                                <th className="px-3 py-2 font-medium">Message</th>
                                <th className="px-3 py-2 font-medium">Task</th>
                                <th className="px-3 py-2 font-medium">Input source</th>
                                <th className="px-3 py-2 font-medium">Run</th>
                                <th className="px-3 py-2 font-medium">Context</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {logs.map((log) => (
                                <tr key={log.id} className="align-top">
                                    <td className="px-3 py-2 text-slate-500">{log.created_at}</td>
                                    <td className="px-3 py-2">
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-xs ${
                                                levelClass[log.level] ?? 'bg-slate-100 text-slate-700'
                                            }`}
                                        >
                                            {log.level}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">{log.message}</td>
                                    <td className="px-3 py-2 text-slate-700">
                                        {log.task_id ? (
                                            <Link
                                                href={tasks.index.url({
                                                    query: {
                                                        task: log.task_id,
                                                    },
                                                })}
                                                className="underline"
                                            >
                                                {log.task_title ? `#${log.task_id} ${log.task_title}` : `Task #${log.task_id}`}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-slate-700">
                                        {log.input_source_id
                                            ? `#${log.input_source_id} ${log.input_source_title ?? 'Input source'}`
                                            : '—'}
                                    </td>
                                    <td className="px-3 py-2 text-slate-700">
                                        {log.run_id ? `${log.run_id} (${log.run_status ?? 'unknown'})` : '—'}
                                    </td>
                                    <td className="px-3 py-2 text-slate-500">
                                        {safeJson(log.context) ? (
                                            <details className="max-w-xs">
                                                <summary className="cursor-pointer text-xs">View</summary>
                                                <pre className="mt-1 max-w-xs overflow-x-auto whitespace-pre-wrap rounded border border-slate-200 bg-slate-50 p-2 text-xs">
                                                    {safeJson(log.context)}
                                                </pre>
                                            </details>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {logs.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-3 py-6 text-center text-sm text-slate-500">
                                        No logs available.
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    );
}
