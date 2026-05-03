<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInputSourceRequest;
use App\Jobs\AnalyzeInputSourceJob;
use App\Models\InputSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InputSourceController extends Controller
{
    public function store(StoreInputSourceRequest $request): RedirectResponse
    {
        $title = $request->string('title')->trim()->toString();
        $sourceType = $request->string('source_type')->toString();
        $originalFilename = null;
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

            $originalFilename = $upload->getClientOriginalName();
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
            $filePath = 'input-sources/'.Str::uuid().'.txt';

            if (! Storage::disk($fileDisk)->put($filePath, $text)) {
                throw ValidationException::withMessages([
                    'text' => ['Input source text could not be stored.'],
                ]);
            }

            $mimeType = 'text/plain';
            $fileSize = strlen($text);
        }

        if ($title === '') {
            $filename = $originalFilename !== null ? pathinfo($originalFilename, PATHINFO_FILENAME) : null;
            $title = $filename !== null && $filename !== '' ? $filename : now()->format('H:i d/m/Y');
        }

        $source = InputSource::create([
            'title' => $title,
            'original_filename' => $originalFilename,
            'file_disk' => $fileDisk,
            'file_path' => $filePath,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'analysis_status' => 'pending',
        ]);

        AnalyzeInputSourceJob::dispatch($source->id);

        return redirect()->route('tasks.index')->with('status', 'Input queued for analysis.');
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
            $inputSource->original_filename ?: basename((string) $inputSource->file_path),
        );

        return $response;
    }
}
