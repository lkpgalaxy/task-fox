<?php

use App\Jobs\AnalyzeInputSourceJob;
use App\Models\InputSource;
use App\Services\CodingAgents\CodexCodingAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('pasted text import creates an input source and queues analysis', function () {
    Queue::fake();
    Storage::fake('local');

    $response = $this->post(route('input-sources.store'), [
        'title' => 'Sprint notes',
        'source_type' => 'text',
        'text' => 'Follow up with design on the empty state.',
    ]);

    $response->assertRedirect(route('tasks.index'));

    $source = InputSource::query()->sole();

    expect($source)
        ->title->toBe('Sprint notes')
        ->original_filename->toBeNull()
        ->file_disk->toBe('local')
        ->file_path->toStartWith('input-sources/')
        ->mime_type->toBe('text/plain')
        ->file_size->toBe(41);

    Storage::disk('local')->assertExists($source->file_path);
    expect(Storage::disk('local')->get($source->file_path))->toBe('Follow up with design on the empty state.');

    Queue::assertPushed(AnalyzeInputSourceJob::class);
});

test('input source management page lists paginated sources', function () {
    for ($index = 1; $index <= 12; $index++) {
        InputSource::create([
            'title' => "Source {$index}",
            'original_filename' => "source-{$index}.txt",
            'file_disk' => 'local',
            'file_path' => "input-sources/source-{$index}.txt",
            'mime_type' => 'text/plain',
            'file_size' => 100 + $index,
            'analysis_status' => $index === 1 ? 'completed' : 'pending',
        ]);
    }

    $this->get(route('input-sources.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('input-sources/Index')
            ->has('sources.data', 10)
            ->where('sources.meta.current_page', 1)
            ->where('sources.meta.last_page', 2)
            ->where('sources.meta.total', 12)
            ->has('sources.links.prev')
            ->has('sources.links.next')
        );
});

test('input source imports can redirect back to source management', function () {
    Queue::fake();
    Storage::fake('local');

    $response = $this->post(route('input-sources.store'), [
        'title' => 'Source page upload',
        'source_type' => 'text',
        'text' => 'Create tasks from this source.',
        'redirect_to' => 'input-sources.index',
    ]);

    $response->assertRedirect(route('input-sources.index'));

    expect(InputSource::query()->sole())->title->toBe('Source page upload');
    Queue::assertPushed(AnalyzeInputSourceJob::class);
});

test('txt uploads create an input source and queue analysis', function () {
    Queue::fake();
    Storage::fake('local');

    $upload = UploadedFile::fake()->createWithContent('meeting.txt', 'Ship the task list export.');

    $response = $this->post(route('input-sources.store'), [
        'source_type' => 'file',
        'upload' => $upload,
    ]);

    $response->assertRedirect(route('tasks.index'));

    $source = InputSource::query()->sole();

    expect($source)
        ->title->toBe('meeting')
        ->original_filename->toBe('meeting.txt')
        ->file_disk->toBe('local')
        ->file_path->toStartWith('input-sources/')
        ->mime_type->not->toBeNull()
        ->file_size->toBe(26);

    Storage::disk('local')->assertExists($source->file_path);

    Queue::assertPushed(AnalyzeInputSourceJob::class);
});

test('md uploads create an input source and queue analysis', function () {
    Queue::fake();
    Storage::fake('local');

    $upload = UploadedFile::fake()->createWithContent('plan.md', "## Plan\n\n- Draft QA checklist.");

    $response = $this->post(route('input-sources.store'), [
        'title' => 'Imported plan',
        'source_type' => 'file',
        'upload' => $upload,
    ]);

    $response->assertRedirect(route('tasks.index'));

    $source = InputSource::query()->sole();

    expect($source)
        ->title->toBe('Imported plan')
        ->original_filename->toBe('plan.md')
        ->file_disk->toBe('local')
        ->file_path->toStartWith('input-sources/')
        ->mime_type->not->toBeNull()
        ->file_size->toBe(30);

    Storage::disk('local')->assertExists($source->file_path);

    Queue::assertPushed(AnalyzeInputSourceJob::class);
});

test('pdf uploads store the file and queue analysis without extracted text', function () {
    Queue::fake();
    Storage::fake('local');

    $upload = UploadedFile::fake()->createWithContent('roadmap.pdf', emptyPdf());

    $response = $this->post(route('input-sources.store'), [
        'source_type' => 'file',
        'upload' => $upload,
    ]);

    $response->assertRedirect(route('tasks.index'));

    $source = InputSource::query()->sole();

    expect($source)
        ->title->toBe('roadmap')
        ->original_filename->toBe('roadmap.pdf')
        ->file_disk->toBe('local')
        ->file_path->toStartWith('input-sources/')
        ->mime_type->not->toBeNull()
        ->file_size->toBe(strlen(emptyPdf()));

    Storage::disk('local')->assertExists($source->file_path);

    Queue::assertPushed(AnalyzeInputSourceJob::class);
});

test('markdown uploads are rejected', function () {
    Queue::fake();

    $upload = UploadedFile::fake()->createWithContent('notes.markdown', 'Review the imported tasks.');

    $response = $this->from(route('tasks.index'))->post(route('input-sources.store'), [
        'source_type' => 'file',
        'upload' => $upload,
    ]);

    $response
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHasErrors('upload');

    expect(InputSource::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('preview route streams stored files inline', function () {
    Queue::fake();
    Storage::fake('local');

    $path = 'input-sources/example.txt';
    Storage::disk('local')->put($path, 'Preview body.');

    $source = InputSource::create([
        'title' => 'Example',
        'original_filename' => 'example.txt',
        'file_disk' => 'local',
        'file_path' => $path,
        'mime_type' => 'text/plain',
        'file_size' => 13,
        'analysis_status' => 'completed',
    ]);

    $response = $this->get(route('input-sources.preview', $source));

    $response
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8');

    expect($response->headers->get('content-disposition'))->toContain('inline');
});

test('preview route fails safely when stored file is missing', function () {
    Storage::fake('local');

    $source = InputSource::create([
        'title' => 'Missing',
        'original_filename' => 'missing.pdf',
        'file_disk' => 'local',
        'file_path' => 'input-sources/missing.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'analysis_status' => 'failed',
    ]);

    $this->get(route('input-sources.preview', $source))->assertNotFound();
});

test('codex analysis prompt includes stored file path metadata for file backed sources', function () {
    Storage::fake('local');
    Storage::disk('local')->put('input-sources/roadmap.pdf', '%PDF-1.4');

    $inputSource = new InputSource([
        'title' => 'Roadmap PDF',
        'original_filename' => 'roadmap.pdf',
        'file_disk' => 'local',
        'file_path' => 'input-sources/roadmap.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 8,
    ]);

    $method = (new ReflectionClass(CodexCodingAgent::class))->getMethod('buildInputSourcePayload');
    $payload = $method->invoke(new CodexCodingAgent, $inputSource);

    expect($payload)
        ->toContain('Input source kind: stored uploaded file')
        ->toContain('Original filename: roadmap.pdf')
        ->toContain('MIME type: application/pdf')
        ->toContain('File size: 8 bytes')
        ->toContain(Storage::disk('local')->path('input-sources/roadmap.pdf'))
        ->toContain('analyze the PDF directly from disk');
});

function emptyPdf(): string
{
    return assemblePdf([
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /Resources << >> /MediaBox [0 0 612 792] >>',
    ]);
}

/**
 * @param  list<string>  $objects
 */
function assemblePdf(array $objects): string
{
    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $number = $index + 1;
        $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
    $pdf .= "0000000000 65535 f \n";

    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer\n<< /Root 1 0 R /Size ".(count($objects) + 1)." >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}
