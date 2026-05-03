import type { InertiaFormProps } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { cn } from '@/lib/utils';
import { Button, Field, Input, Modal, Textarea } from './ui';

export type UploadSourceType = 'text' | 'file';

export type UploadSourceFormData = {
    title: string;
    source_type: UploadSourceType;
    text: string;
    upload: File | null;
    redirect_to?: string;
};

type UploadSourceModalProps = {
    show: boolean;
    title: string;
    form: Pick<
        InertiaFormProps<UploadSourceFormData>,
        'data' | 'errors' | 'processing' | 'progress' | 'setData'
    >;
    sourceTypes?: UploadSourceType[];
    onClose: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onSourceTypeChange: (sourceType: UploadSourceType) => void;
};

const formatError = (error: string | string[] | undefined): string | null => {
    if (Array.isArray(error)) {
        return error.join(', ');
    }

    return error ?? null;
};

export function UploadSourceModal({
    show,
    title,
    form,
    sourceTypes = ['text', 'file'],
    onClose,
    onSubmit,
    onSourceTypeChange,
}: UploadSourceModalProps) {
    return (
        <Modal show={show} onClose={onClose} title={title}>
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
                        {sourceTypes.map((sourceType) => (
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
                                    {sourceType === 'text'
                                        ? 'Paste text'
                                        : 'Upload file'}
                                </span>
                                <span className="text-xs text-ink-subtle">
                                    {sourceType === 'text'
                                        ? 'Manual input'
                                        : '.txt, .md, or .pdf'}
                                </span>
                            </label>
                        ))}
                    </div>
                </fieldset>

                {form.data.source_type === 'text' ? (
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
                ) : (
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
                        {form.progress ? (
                            <div className="grid gap-1">
                                <div className="h-1.5 overflow-hidden rounded-full bg-surface-3">
                                    <div
                                        className="h-full rounded-full bg-primary transition-[width]"
                                        style={{
                                            width: `${form.progress.percentage}%`,
                                        }}
                                    />
                                </div>
                                <p className="text-xs text-ink-subtle">
                                    Uploading {form.progress.percentage}%
                                </p>
                            </div>
                        ) : null}
                    </div>
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
