<?php

use App\Contracts\CodingAgent;
use App\DataTransferObjects\CodingAgentResult;
use App\Jobs\AnalyzeInputSourceJob;
use App\Models\AiRun;
use App\Models\AiRunLog;
use App\Models\InputSource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it uses the coding agent analysis to create pending approval tasks', function () {
    $assignee = User::factory()->create(['github_username' => 'linh']);
    $source = InputSource::create([
        'title' => 'Input source notes',
        'analysis_status' => 'pending',
    ]);

    $this->app->instance(CodingAgent::class, new class implements CodingAgent
    {
        public function run(Task $task, AiRun $run): CodingAgentResult
        {
            return new CodingAgentResult(successful: true);
        }

        public function analyzeInputSource(InputSource $inputSource): CodingAgentResult
        {
            return new CodingAgentResult(
                successful: true,
                payload: [
                    'tasks' => [
                        [
                            'title' => 'Store uploaded input files',
                            'description' => 'Persist uploaded input files and metadata before task analysis.',
                            'assignee_github_username' => 'linh',
                            'priority' => 'high',
                            'deadline' => '2026-05-10',
                            'acceptance_criteria' => [
                                ['body' => 'Supported uploads are stored on the private disk.', 'checked' => false],
                            ],
                            'questions' => [],
                        ],
                        [
                            'title' => 'Analyze stored files',
                            'description' => 'Pass stored file metadata to the coding agent for analysis.',
                            'assignee_github_username' => null,
                            'priority' => 'medium',
                            'deadline' => null,
                            'acceptance_criteria' => [
                                ['body' => 'Scanned PDFs upload successfully without extracted text.', 'checked' => false],
                            ],
                            'questions' => ['Should OCR be added later?'],
                        ],
                    ],
                ],
            );
        }
    });

    app()->call([new AnalyzeInputSourceJob($source->id), 'handle']);

    $source->refresh();

    expect($source->analysis_status)->toBe('completed')
        ->and($source->analysis_result['task_count'])->toBe(2)
        ->and($source->analysis_result['tasks'][0]['title'])->toBe('Store uploaded input files')
        ->and($source->analysis_result['tasks'][1]['title'])->toBe('Analyze stored files')
        ->and($source->analysis_result['tasks'][1])->not->toHaveKey('questions')
        ->and($source->analysis_result['analyzed_at'])->toBeString()
        ->and(Task::query()->count())->toBe(2);

    $task = Task::query()->where('title', 'Store uploaded input files')->firstOrFail();

    expect($task)
        ->status->toBe(Task::STATUS_PENDING_APPROVAL)
        ->priority->toBe(Task::PRIORITY_HIGH)
        ->deadline->toDateString()->toBe('2026-05-10')
        ->assignee_user_id->toBe($assignee->id)
        ->source_input_id->toBe($source->id)
        ->and($task->acceptance_criteria)->toBe([
            ['body' => 'Supported uploads are stored on the private disk.', 'checked' => false],
        ]);

    $questionTask = Task::query()->where('title', 'Analyze stored files')->firstOrFail();

    expect($questionTask->description)->toContain('Questions:')
        ->and($questionTask->description)->toContain('- Should OCR be added later?');

    expect(AiRunLog::query()->where('input_source_id', $source->id)->pluck('message')->all())->toBe([
        'Input source analysis started',
        'Input source analysis completed',
    ]);

    $completedLog = AiRunLog::query()
        ->where('input_source_id', $source->id)
        ->where('message', 'Input source analysis completed')
        ->firstOrFail();

    expect($completedLog)
        ->ai_run_id->toBeNull()
        ->level->toBe('info')
        ->and($completedLog->context)->toMatchArray([
            'coding_agent' => 'codex',
            'input_source_id' => $source->id,
            'input_source_title' => 'Input source notes',
            'analysis_status' => 'completed',
            'task_count' => 2,
        ]);
});
