<?php

use App\Contracts\Agent;
use App\DataTransferObjects\CodingAgentResult;
use App\Models\InputSource;
use App\Services\Extraction\AgentTaskExtractor;

test('it normalizes agent task analysis into extractor format', function () {
    $inputSource = new InputSource([
        'title' => 'Meeting notes',
    ]);

    $agent = new class implements Agent
    {
        /**
         * @var array<int, array<string, mixed>>
         */
        public array $projectSummaries = [];

        public function analyzeInputSource(InputSource $inputSource, array $projectSummaries): CodingAgentResult
        {
            $this->projectSummaries = $projectSummaries;

            return new CodingAgentResult(
                successful: true,
                payload: [
                    'tasks' => [
                        [
                            'title' => 'Add stored file import',
                            'description' => 'Store uploaded files and analyze them.',
                            'project_id' => '12',
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
                            'project_id' => 'unknown',
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
    };

    $extractor = new AgentTaskExtractor($agent);
    $projectSummaries = [
        ['id' => 12, 'name' => 'Task Fox'],
    ];

    $tasks = $extractor->extract($inputSource, $projectSummaries);

    expect($tasks)->toHaveCount(2)
        ->and($agent->projectSummaries)->toBe($projectSummaries)
        ->and($tasks[0])
        ->toMatchArray([
            'title' => 'Add stored file import',
            'description' => 'Store uploaded files and analyze them.',
            'project_id' => 12,
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
        ->and($tasks[1]['project_id'])->toBeNull()
        ->and($tasks[1]['priority'])->toBeNull()
        ->and($tasks[1]['deadline'])->toBeNull()
        ->and($tasks[1]['acceptance_criteria'])->toBe([
            ['body' => 'No array conversion warning', 'checked' => false],
        ])
        ->and($tasks[1]['questions'])->toBe(['Nested question']);
});
