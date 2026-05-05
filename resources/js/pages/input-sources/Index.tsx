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
import { UploadSourceAction } from '@/components/upload-source-modal';
import { formatDisplayDateTime } from '@/lib/utils';
import inputSources from '@/routes/input-sources';

type SourceRecord = {
    id: number;
    title: string;
    filename: string | null;
    mime_type: string | null;
    file_size: number | null;
    analysis_status: string;
    analysis_result: Record<string, unknown> | null;
    last_analysis_error: string | null;
    has_file: boolean;
    created_at: string | null;
    updated_at: string | null;
};

type PaginatedSources = {
    data: SourceRecord[];
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        per_page: number;
        to: number | null;
        total: number;
    };
    links: {
        first: string;
        last: string;
        prev: string | null;
        next: string | null;
    };
};

type PageProps = {
    sources: PaginatedSources;
};

const formatFileSize = (size: number | null): string => {
    if (size === null) {
        return 'Unknown size';
    }

    if (size < 1024) {
        return `${size} B`;
    }

    if (size < 1024 * 1024) {
        return `${(size / 1024).toFixed(1)} KB`;
    }

    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
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

export default function InputSourcesIndex() {
    const { sources } = usePage<PageProps>().props;
    const [selectedAnalysisResult, setSelectedAnalysisResult] = useState<{
        title: string;
        json: string;
    } | null>(null);

    return (
        <AppShell
            title="Input sources"
            description="Uploaded files and pasted text queued for task analysis."
            actions={<UploadSourceAction redirectTo="input-sources.index" />}
        >
            <Head title="Input sources" />

            <div className="space-y-4">
                <DataTable>
                    <TableHead>
                        <tr>
                            <Th>Source</Th>
                            <Th>Status</Th>
                            <Th>Analysis result</Th>
                            <Th>File</Th>
                            <Th>Created</Th>
                            <Th>Preview</Th>
                        </tr>
                    </TableHead>
                    <TableBody>
                        {sources.data.map((source) => (
                            <tr
                                key={source.id}
                                className="hover:bg-surface-2/60"
                            >
                                <Td>
                                    <p className="font-medium text-ink">
                                        {source.title}
                                    </p>
                                    {source.last_analysis_error ? (
                                        <p className="mt-1 max-w-md text-xs text-red-200">
                                            {source.last_analysis_error}
                                        </p>
                                    ) : null}
                                </Td>
                                <Td>
                                    <Badge value={source.analysis_status}>
                                        {source.analysis_status}
                                    </Badge>
                                </Td>
                                <Td className="text-ink-subtle">
                                    {safeJson(source.analysis_result) ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="min-h-7 px-2 py-1 text-xs"
                                            onClick={() => {
                                                const json = safeJson(
                                                    source.analysis_result,
                                                );

                                                if (json) {
                                                    setSelectedAnalysisResult({
                                                        title: `Input source #${source.id} analysis result`,
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
                                <Td className="text-ink-muted">
                                    <p>{source.filename ?? 'Unknown file'}</p>
                                    <p className="text-xs text-ink-tertiary">
                                        {source.mime_type ?? 'unknown type'} ·{' '}
                                        {formatFileSize(source.file_size)}
                                    </p>
                                </Td>
                                <Td className="text-ink-subtle">
                                    {formatDisplayDateTime(source.created_at)}
                                </Td>
                                <Td>
                                    {source.has_file ? (
                                        <a
                                            href={inputSources.preview.url(
                                                source.id,
                                            )}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex min-h-9 items-center rounded-md border border-hairline-strong bg-surface-2 px-3 py-2 text-sm font-medium text-ink hover:bg-surface-3"
                                        >
                                            Open preview
                                        </a>
                                    ) : (
                                        <span className="text-ink-tertiary">
                                            None
                                        </span>
                                    )}
                                </Td>
                            </tr>
                        ))}
                        {sources.data.length === 0 ? (
                            <tr>
                                <Td
                                    colSpan={6}
                                    className="py-8 text-center text-ink-subtle"
                                >
                                    No input sources yet.
                                </Td>
                            </tr>
                        ) : null}
                    </TableBody>
                </DataTable>

                <nav className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-hairline bg-surface-1 p-3 text-sm">
                    <p className="text-ink-subtle">
                        {sources.meta.total === 0
                            ? 'Showing 0 sources'
                            : `Showing ${sources.meta.from}-${sources.meta.to} of ${sources.meta.total} sources`}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {sources.links.prev ? (
                            <Link
                                href={sources.links.prev}
                                className="rounded-md border border-hairline-strong bg-surface-2 px-3 py-2 font-medium text-ink hover:bg-surface-3"
                            >
                                Previous
                            </Link>
                        ) : (
                            <span className="rounded-md border border-hairline bg-surface-1 px-3 py-2 font-medium text-ink-tertiary">
                                Previous
                            </span>
                        )}
                        <span className="rounded-md border border-hairline bg-surface-2 px-3 py-2 text-ink-subtle">
                            Page {sources.meta.current_page} of{' '}
                            {sources.meta.last_page}
                        </span>
                        {sources.links.next ? (
                            <Link
                                href={sources.links.next}
                                className="rounded-md border border-hairline-strong bg-surface-2 px-3 py-2 font-medium text-ink hover:bg-surface-3"
                            >
                                Next
                            </Link>
                        ) : (
                            <span className="rounded-md border border-hairline bg-surface-1 px-3 py-2 font-medium text-ink-tertiary">
                                Next
                            </span>
                        )}
                    </div>
                </nav>
            </div>

            <Modal
                show={selectedAnalysisResult !== null}
                onClose={() => setSelectedAnalysisResult(null)}
                title={selectedAnalysisResult?.title ?? 'Analysis result'}
                size="xl"
            >
                {selectedAnalysisResult ? (
                    <pre className="max-h-[70vh] overflow-auto rounded-md border border-hairline bg-canvas p-4 font-mono text-xs whitespace-pre-wrap text-ink-subtle">
                        {selectedAnalysisResult.json}
                    </pre>
                ) : null}
            </Modal>
        </AppShell>
    );
}
