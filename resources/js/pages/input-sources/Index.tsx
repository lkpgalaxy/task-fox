import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { AppShell } from '@/components/app-shell';
import {
    Alert,
    Badge,
    Button,
    DataTable,
    Field,
    Input,
    Modal,
    TableBody,
    TableHead,
    Td,
    Textarea,
    Th,
} from '@/components/ui';
import { cn } from '@/lib/utils';
import inputSources from '@/routes/input-sources';

type SourceRecord = {
    id: number;
    title: string;
    original_filename: string | null;
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
    flash?: {
        status?: string;
    };
};

type ImportSourceType = 'text' | 'file';

type SourceFormData = {
    title: string;
    source_type: ImportSourceType;
    text: string;
    upload: File | null;
    redirect_to: string;
};

const formatError = (error: string | string[] | undefined): string | null => {
    if (Array.isArray(error)) {
        return error.join(', ');
    }

    return error ?? null;
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

const formatDate = (value: string | null): string => {
    if (!value) {
        return 'None';
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return value;
    }
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
    const { sources, flash } = usePage<PageProps>().props;
    const [showUploadModal, setShowUploadModal] = useState(false);
    const [selectedAnalysisResult, setSelectedAnalysisResult] = useState<{
        title: string;
        json: string;
    } | null>(null);

    const form = useForm<SourceFormData>({
        title: '',
        source_type: 'file',
        text: '',
        upload: null,
        redirect_to: 'input-sources.index',
    });

    const closeUploadModal = () => {
        setShowUploadModal(false);
        form.clearErrors();
    };

    const updateSourceType = (sourceType: ImportSourceType) => {
        form.setData({
            ...form.data,
            source_type: sourceType,
            text: sourceType === 'text' ? form.data.text : '',
            upload: sourceType === 'file' ? form.data.upload : null,
        });
        form.clearErrors('text', 'upload');
    };

    const submitUpload = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            text: data.source_type === 'text' ? data.text : '',
            upload: data.source_type === 'file' ? data.upload : null,
        }));
        form.post(inputSources.store.url(), {
            forceFormData: true,
            onSuccess: () => {
                setShowUploadModal(false);
                form.reset();
            },
        });
    };

    return (
        <AppShell
            title="Input sources"
            description="Uploaded files and pasted text queued for task analysis."
            actions={
                <Button
                    type="button"
                    variant="primary"
                    onClick={() => setShowUploadModal(true)}
                >
                    Upload source
                </Button>
            }
        >
            <Head title="Input sources" />

            <div className="space-y-4">
                {flash?.status ? <Alert>{flash.status}</Alert> : null}

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
                                    <p>
                                        {source.original_filename ??
                                            'Pasted text'}
                                    </p>
                                    <p className="text-xs text-ink-tertiary">
                                        {source.mime_type ?? 'unknown type'} ·{' '}
                                        {formatFileSize(source.file_size)}
                                    </p>
                                </Td>
                                <Td className="text-ink-subtle">
                                    {formatDate(source.created_at)}
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

            <UploadModal
                show={showUploadModal}
                form={form}
                onClose={closeUploadModal}
                onSubmit={submitUpload}
                onSourceTypeChange={updateSourceType}
            />

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

function UploadModal({
    show,
    form,
    onClose,
    onSubmit,
    onSourceTypeChange,
}: {
    show: boolean;
    form: ReturnType<typeof useForm<SourceFormData>>;
    onClose: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onSourceTypeChange: (sourceType: ImportSourceType) => void;
}) {
    return (
        <Modal show={show} onClose={onClose} title="Upload source">
            <form onSubmit={onSubmit} className="space-y-4">
                <Field label="Title (optional)">
                    <Input
                        type="text"
                        value={form.data.title}
                        onChange={(event) =>
                            form.setData('title', event.target.value)
                        }
                    />
                </Field>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium text-ink-muted">
                        Input source
                    </legend>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {(['file', 'text'] as ImportSourceType[]).map(
                            (sourceType) => (
                                <label
                                    key={sourceType}
                                    className={cn(
                                        'flex cursor-pointer items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm transition',
                                        form.data.source_type === sourceType
                                            ? 'border-primary bg-primary/15 text-ink'
                                            : 'border-hairline bg-surface-2 text-ink-muted hover:bg-surface-3',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="source_type"
                                        value={sourceType}
                                        checked={
                                            form.data.source_type === sourceType
                                        }
                                        onChange={() =>
                                            onSourceTypeChange(sourceType)
                                        }
                                        className="sr-only"
                                    />
                                    <span className="font-semibold">
                                        {sourceType === 'file'
                                            ? 'Upload file'
                                            : 'Paste text'}
                                    </span>
                                    <span className="text-xs text-ink-subtle">
                                        {sourceType === 'file'
                                            ? '.txt, .md, or .pdf'
                                            : 'Manual input'}
                                    </span>
                                </label>
                            ),
                        )}
                    </div>
                </fieldset>

                {form.data.source_type === 'file' ? (
                    <div className="grid gap-2 text-sm text-ink-muted">
                        <span>File</span>
                        <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-hairline-strong bg-surface-2 px-4 py-6 text-center transition hover:border-primary/60">
                            <span className="rounded-md border border-primary bg-primary px-3 py-2 text-sm font-semibold text-white">
                                Choose file
                            </span>
                            <span className="text-xs text-ink-subtle">
                                {form.data.upload?.name ??
                                    'Upload a .txt, .md, or .pdf file up to 10 MB'}
                            </span>
                            <input
                                type="file"
                                accept=".txt,.md,.pdf,text/plain,text/markdown,application/pdf"
                                onChange={(event) =>
                                    form.setData(
                                        'upload',
                                        event.currentTarget.files?.[0] ?? null,
                                    )
                                }
                                className="sr-only"
                            />
                        </label>
                    </div>
                ) : (
                    <Field label="Text">
                        <Textarea
                            value={form.data.text}
                            onChange={(event) =>
                                form.setData('text', event.target.value)
                            }
                            rows={8}
                            placeholder="Paste the source text to analyze into tasks..."
                        />
                    </Field>
                )}

                {formatError(form.errors.text) ? (
                    <p className="text-xs text-red-200">
                        {formatError(form.errors.text)}
                    </p>
                ) : null}
                {formatError(form.errors.upload) ? (
                    <p className="text-xs text-red-200">
                        {formatError(form.errors.upload)}
                    </p>
                ) : null}

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={form.processing}
                    >
                        {form.processing ? 'Submitting...' : 'Analyze'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
