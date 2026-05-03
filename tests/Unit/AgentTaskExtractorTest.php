<?php

use App\Contracts\CodingAgent;
use App\DataTransferObjects\CodingAgentResult;
use App\Models\AiRun;
use App\Models\InputSource;
use App\Models\Task;
use App\Services\Extraction\AgentTaskExtractor;

test('it normalizes coding agent task analysis into extractor format', function () {
    $inputSource = new InputSource([
        'title' => 'Meeting notes',
    ]);

    $extractor = new AgentTaskExtractor(new class implements CodingAgent
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
                            'title' => 'Add stored file import',
                            'description' => 'Store uploaded files and analyze them.',
                            'assignee_github_username' => '@linh',
                            'priority' => 'HIGH',
                            'deadline' => '2026-05-10',
                            'acceptance_criteria' => [
                                ['body' => 'Uploaded file metadata is stored on the input source.', 'checked' => true],
                            ],
                            'questions' => ['Should scanned PDFs use OCR?', 'Should scanned PDFs use OCR?'],
                        ],
                        [
                            'title' => ['Create', 'fallback validation'],
                            'description' => ['for' => 'empty analysis'],
                            'priority' => 'invalid',
                            'deadline' => 'next week',
                            'acceptance_criteria' => [
                                ['body' => ['No', 'array conversion warning'], 'checked' => false],
                            ],
                            'questions' => [['Nested', 'question']],
                        ],
                    ],
                ],
            );
        }
    });

    $tasks = $extractor->extract($inputSource);

    expect($tasks)->toHaveCount(2)
        ->and($tasks[0])
        ->toMatchArray([
            'title' => 'Add stored file import',
            'description' => 'Store uploaded files and analyze them.',
            'assignee_github_username' => 'linh',
            'priority' => 'high',
            'deadline' => '2026-05-10',
            'acceptance_criteria' => [
                ['body' => 'Uploaded file metadata is stored on the input source.', 'checked' => true],
            ],
            'questions' => ['Should scanned PDFs use OCR?'],
        ])
        ->and($tasks[1]['title'])->toBe('Create fallback validation')
        ->and($tasks[1]['description'])->toBe('empty analysis')
        ->and($tasks[1]['priority'])->toBeNull()
        ->and($tasks[1]['deadline'])->toBeNull()
        ->and($tasks[1]['acceptance_criteria'])->toBe([
            ['body' => 'No array conversion warning', 'checked' => false],
        ])
        ->and($tasks[1]['questions'])->toBe(['Nested question']);
});
