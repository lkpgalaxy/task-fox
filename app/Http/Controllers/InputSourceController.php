<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInputSourceRequest;
use App\Jobs\AnalyzeInputSourceJob;
use App\Models\InputSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InputSourceController extends Controller
{
    public function index(): Response
    {
        $sources = InputSource::query()
            ->select([
                'id',
                'title',
                'filename',
                'file_disk',
                'file_path',
                'mime_type',
                'file_size',
                'analysis_status',
                'analysis_result',
                'last_analysis_error',
                'created_at',
                'updated_at',
            ])
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (InputSource $inputSource): array => [
                'id' => $inputSource->id,
                'title' => $inputSource->title,
                'filename' => $inputSource->filename,
                'mime_type' => $inputSource->mime_type,
                'file_size' => $inputSource->file_size,
                'analysis_status' => $inputSource->analysis_status,
                'analysis_result' => $inputSource->analysis_result,
                'last_analysis_error' => $inputSource->last_analysis_error,
                'has_file' => $inputSource->hasStoredFile(),
                'created_at' => $inputSource->created_at?->toIso8601String(),
                'updated_at' => $inputSource->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('input-sources/Index', [
            'sources' => [
                'data' => $sources->items(),
                'meta' => [
                    'current_page' => $sources->currentPage(),
                    'from' => $sources->firstItem(),
                    'last_page' => $sources->lastPage(),
                    'per_page' => $sources->perPage(),
                    'to' => $sources->lastItem(),
                    'total' => $sources->total(),
                ],
                'links' => [
                    'first' => $sources->url(1),
                    'last' => $sources->url($sources->lastPage()),
                    'prev' => $sources->previousPageUrl(),
                    'next' => $sources->nextPageUrl(),
                ],
            ],
        ]);
    }

    public function store(StoreInputSourceRequest $request): RedirectResponse
    {
        $title = $request->string('title')->trim()->toString();
        $sourceType = $request->string('source_type')->toString();
        $filename = null;
        $fileDisk = null;
        $filePath = null;
        $mimeType = null;
        $fileSize = null;

        if ($sourceType === 'file') {
            $upload = $request->file('upload');

            if ($upload === null) {
                throw ValidationException::withMessages([
                    'upload' => ['A supported file is required.'],
                ]);
            }

            $filename = $upload->getClientOriginalName();
            $fileDisk = 'local';
            $filePath = $upload->store('input-sources', $fileDisk);

            if ($filePath === false) {
                throw ValidationException::withMessages([
                    'upload' => ['Uploaded file could not be stored.'],
                ]);
            }

            $mimeType = $upload->getClientMimeType();
            $fileSize = $upload->getSize();
        } else {
            $text = $request->string('text')->trim()->toString();

            if ($text === '') {
                throw ValidationException::withMessages([
                    'text' => ['An input source body is required.'],
                ]);
            }

            $fileDisk = 'local';
            $filename = 'input-source-'.now()->format('Ymd-His').'.txt';
            $filePath = "input-sources/{$filename}";

            if (! Storage::disk($fileDisk)->put($filePath, $text)) {
                throw ValidationException::withMessages([
                    'text' => ['Input source text could not be stored.'],
                ]);
            }

            $mimeType = 'text/plain';
            $fileSize = strlen($text);
        }

        if ($title === '') {
            $titleStem = $filename !== null ? pathinfo($filename, PATHINFO_FILENAME) : null;
            $title = $titleStem !== null && $titleStem !== '' ? $titleStem : now()->format('H:i d/m/Y');
        }

        $source = InputSource::create([
            'title' => $title,
            'filename' => $filename,
            'file_disk' => $fileDisk,
            'file_path' => $filePath,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'analysis_status' => 'pending',
        ]);

        AnalyzeInputSourceJob::dispatch($source->id);

        $redirectRoute = $request->string('redirect_to')->toString() === 'input-sources.index'
            ? 'input-sources.index'
            : 'tasks.index';

        return redirect()->route($redirectRoute)->with('status', 'Input queued for analysis.');
    }

    public function preview(InputSource $inputSource): BinaryFileResponse
    {
        if (! $inputSource->hasStoredFile()) {
            abort(404);
        }

        $disk = Storage::disk((string) $inputSource->file_disk);

        if (! $disk->exists((string) $inputSource->file_path)) {
            abort(404);
        }

        $response = response()->file($disk->path((string) $inputSource->file_path), [
            'Content-Type' => $inputSource->mime_type ?: 'application/octet-stream',
        ]);

        $response->setContentDisposition(
            'inline',
            $inputSource->filename ?: basename((string) $inputSource->file_path),
        );

        return $response;
    }
}
