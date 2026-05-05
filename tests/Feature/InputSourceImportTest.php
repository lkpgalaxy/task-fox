<?php

use App\Jobs\AnalyzeInputSourceJob;
use App\Models\InputSource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CodingAgents\CodexCodingAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

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
        ->filename->toMatch('/^input-source-\d{8}-\d{6}\.txt$/')
        ->file_disk->toBe('local')
        ->file_path->toBe("input-sources/{$source->filename}")
        ->mime_type->toBe('text/plain')
        ->file_size->toBe(41);

    Storage::disk('local')->assertExists($source->file_path);
    expect(Storage::disk('local')->get($source->file_path))->toBe('Follow up with design on the empty state.');

    Queue::assertPushed(AnalyzeInputSourceJob::class);
});

test('pasted text import without a title uses a generated text filename', function () {
    Queue::fake();
    Storage::fake('local');
    Carbon::setTestNow(Carbon::create(2026, 5, 3, 10, 15, 30));

    try {
        $response = $this->post(route('input-sources.store'), [
            'source_type' => 'text',
            'text' => 'Turn the release notes into tasks.',
        ]);

        $response->assertRedirect(route('tasks.index'));

        $source = InputSource::query()->sole();

        expect($source)
            ->title->toBe('input-source-20260503-101530')
            ->filename->toBe('input-source-20260503-101530.txt')
            ->file_path->toBe('input-sources/input-source-20260503-101530.txt')
            ->mime_type->toBe('text/plain')
            ->file_size->toBe(34);

        Storage::disk('local')->assertExists('input-sources/input-source-20260503-101530.txt');
        expect(Storage::disk('local')->get($source->file_path))->toBe('Turn the release notes into tasks.');
    } finally {
        Carbon::setTestNow();
    }

    Queue::assertPushed(AnalyzeInputSourceJob::class);
});

test('input source management page lists paginated sources', function () {
    for ($index = 1; $index <= 12; $index++) {
        InputSource::create([
            'title' => "Source {$index}",
            'filename' => "source-{$index}.txt",
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
        ->filename->toBe('meeting.txt')
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
        ->filename->toBe('plan.md')
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
        ->filename->toBe('roadmap.pdf')
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
        'filename' => 'example.txt',
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
        'filename' => 'missing.pdf',
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
        'filename' => 'roadmap.pdf',
        'file_disk' => 'local',
        'file_path' => 'input-sources/roadmap.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 8,
    ]);

    $method = (new ReflectionClass(CodexCodingAgent::class))->getMethod('buildInputSourcePayload');
    $payload = $method->invoke(new CodexCodingAgent, $inputSource);

    expect($payload)
        ->toContain('Input source kind: stored uploaded file')
        ->toContain('Filename: roadmap.pdf')
        ->toContain('MIME type: application/pdf')
        ->toContain('File size: 8 bytes')
        ->toContain(Storage::disk('local')->path('input-sources/roadmap.pdf'))
        ->toContain('analyze the PDF directly from disk');
});

test('codex analysis command uses the configured model', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-analysis-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-analysis-codex-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/codex',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '{\"tasks\":[]}'\n"
    );
    chmod($binPath.'/codex', 0755);

    SystemSetting::factory()->create([
        'analyze_source_model' => 'gpt-5.4',
        'analyze_source_reasoning_effort' => 'medium',
    ]);

    $inputSource = new InputSource([
        'title' => 'Roadmap note',
        'analysis_status' => 'pending',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->analyzeInputSource($inputSource, []);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and($args)->toContain('exec')
        ->and($args)->toContain('--model')
        ->and($args[array_search('--model', $args, true) + 1])->toBe('gpt-5.4')
        ->and($args)->toContain('-c')
        ->and($args[array_search('-c', $args, true) + 1])->toBe('model_reasoning_effort="medium"');
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
