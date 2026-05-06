<?php

use App\Contracts\Agent;
use App\DataTransferObjects\CodingAgentResult;
use App\Jobs\AnalyzeInputSourceJob;
use App\Models\InputSource;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\Task;
use App\Models\TaskRunLog;
use App\Models\User;
use App\Services\CodingAgents\OpenCodeCodingAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it uses the agent analysis to create pending approval tasks', function () {
    $assignee = User::factory()->create(['github_username' => 'linh']);
    $source = InputSource::create([
        'title' => 'Input source notes',
        'analysis_status' => 'pending',
    ]);
    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
        'url' => 'https://github.com/example/task-fox',
        'database_name' => 'task_fox',
        'database_username' => 'task_fox_user',
        'database_password' => 'database-secret',
        'credential_username' => 'repo-user',
        'credential_password' => 'repo-secret',
        'base_branch' => 'develop',
    ]);

    SystemSetting::factory()->create([
        'analyze_source_model' => 'gpt-5.4',
        'analyze_source_reasoning_effort' => 'medium',
    ]);

    $agent = new class($project->id) implements Agent
    {
        /**
         * @var array<int, array<string, mixed>>
         */
        public array $projectSummaries = [];

        public function __construct(private readonly int $projectId) {}

        public function analyzeInputSource(InputSource $inputSource, array $projectSummaries): CodingAgentResult
        {
            $this->projectSummaries = $projectSummaries;

            return new CodingAgentResult(
                successful: true,
                payload: [
                    'tasks' => [
                        [
                            'title' => 'Store uploaded input files',
                            'description' => 'Persist uploaded input files and metadata before task analysis.',
                            'project_id' => $this->projectId,
                            'assignee_github_username' => 'linh',
                            'priority' => 'high',
                            'deadline' => '2026-05-10',
                            'questions' => [],
                        ],
                        [
                            'title' => 'Analyze stored files',
                            'description' => 'Pass stored file metadata to the coding agent for analysis.',
                            'project_id' => 999999,
                            'assignee_github_username' => null,
                            'priority' => 'medium',
                            'deadline' => null,
                            'questions' => ['Should OCR be added later?'],
                        ],
                    ],
                ],
            );
        }
    };

    $this->app->instance(Agent::class, $agent);

    app()->call([new AnalyzeInputSourceJob($source->id), 'handle']);

    $source->refresh();

    expect($source->analysis_status)->toBe('completed')
        ->and($source->analysis_result['task_count'])->toBe(2)
        ->and($source->analysis_result['tasks'][0]['title'])->toBe('Store uploaded input files')
        ->and($source->analysis_result['tasks'][0]['project_id'])->toBe($project->id)
        ->and($source->analysis_result['tasks'][0])->not->toHaveKey('acceptance_criteria')
        ->and($source->analysis_result['tasks'][1]['title'])->toBe('Analyze stored files')
        ->and($source->analysis_result['tasks'][1]['project_id'])->toBe(999999)
        ->and($source->analysis_result['tasks'][1])->not->toHaveKey('questions')
        ->and($source->analysis_result['tasks'][1])->not->toHaveKey('acceptance_criteria')
        ->and($source->analysis_result['analyzed_at'])->toBeString()
        ->and(Task::query()->count())->toBe(2);

    $task = Task::query()->where('title', 'Store uploaded input files')->firstOrFail();

    expect($task)
        ->status->toBe(Task::STATUS_PENDING_APPROVAL)
        ->priority->toBe(Task::PRIORITY_HIGH)
        ->deadline->toDateString()->toBe('2026-05-10')
        ->assignee_user_id->toBe($assignee->id)
        ->project_id->toBe($project->id)
        ->source_input_id->toBe($source->id);

    $questionTask = Task::query()->where('title', 'Analyze stored files')->firstOrFail();

    expect($questionTask->description)->toContain('Questions:')
        ->and($questionTask->description)->toContain('- Should OCR be added later?')
        ->and($questionTask->project_id)->toBeNull();

    expect($agent->projectSummaries)->toHaveCount(1)
        ->and($agent->projectSummaries[0])->toMatchArray([
            'id' => $project->id,
            'name' => 'Task Fox',
            'workspace_path' => '/tmp/task-fox',
            'url' => 'https://github.com/example/task-fox',
            'database_name' => 'task_fox',
            'database_username' => 'task_fox_user',
            'base_branch' => 'develop',
            'has_database_password' => true,
            'has_credential_password' => true,
        ])
        ->and($agent->projectSummaries[0])->not->toHaveKey('database_password')
        ->and($agent->projectSummaries[0])->not->toHaveKey('credential_password');

    expect(TaskRunLog::query()->where('input_source_id', $source->id)->pluck('message')->all())->toBe([
        'Input source analysis started',
        'Input source analysis completed',
    ]);

    $completedLog = TaskRunLog::query()
        ->where('input_source_id', $source->id)
        ->where('message', 'Input source analysis completed')
        ->firstOrFail();

    expect($completedLog)
        ->task_run_id->toBeNull()
        ->level->toBe('info')
        ->and($completedLog->context)->toMatchArray([
            'agent' => 'codex',
            'input_source_id' => $source->id,
            'input_source_title' => 'Input source notes',
            'analysis_status' => 'completed',
            'task_count' => 2,
            'analyze_source_model' => 'gpt-5.4',
            'analyze_source_reasoning_effort' => 'medium',
        ])
        ->and($completedLog->context)->not->toHaveKey('coding_agent');
});

test('queued analysis keeps the uploader agent driver snapshot after settings change', function () {
    config([
        'automation.supported_agent_drivers' => [
            'codex' => 'Codex',
            'opencode' => 'OpenCode',
        ],
    ]);

    $source = InputSource::create([
        'title' => 'Driver snapshot source',
        'agent_driver' => 'codex',
        'analysis_status' => 'pending',
    ]);

    SystemSetting::factory()->create([
        'agent_driver' => null,
        'analyze_source_model' => 'gpt-5.4',
        'analyze_source_reasoning_effort' => 'medium',
    ]);

    $agent = new class implements Agent
    {
        public function analyzeInputSource(InputSource $inputSource, array $projectSummaries): CodingAgentResult
        {
            return new CodingAgentResult(
                successful: true,
                payload: [
                    'tasks' => [
                        [
                            'title' => 'Snapshot driver task',
                            'description' => 'Created with the stored driver snapshot.',
                            'project_id' => null,
                            'assignee_github_username' => null,
                            'priority' => 'medium',
                            'deadline' => null,
                            'questions' => [],
                        ],
                    ],
                ],
            );
        }
    };

    $this->app->instance(Agent::class, $agent);

    SystemSetting::query()->sole()->update(['agent_driver' => 'different-driver']);

    app()->call([new AnalyzeInputSourceJob($source->id), 'handle']);

    expect($source->refresh()->analysis_status)->toBe('completed')
        ->and(TaskRunLog::query()
            ->where('input_source_id', $source->id)
            ->where('message', 'Input source analysis completed')
            ->sole()
            ->context['agent'])
        ->toBe('codex');
});

test('queued analysis logs the selected opencode driver', function () {
    config([
        'automation.supported_agent_drivers' => [
            'codex' => 'Codex',
            'opencode' => 'OpenCode',
        ],
    ]);

    $source = InputSource::create([
        'title' => 'OpenCode source',
        'agent_driver' => 'opencode',
        'analysis_status' => 'pending',
    ]);

    SystemSetting::factory()->create([
        'analyze_source_model' => 'openai/gpt-5.4',
        'analyze_source_reasoning_effort' => 'medium',
    ]);

    $agent = new class implements Agent
    {
        public function analyzeInputSource(InputSource $inputSource, array $projectSummaries): CodingAgentResult
        {
            return new CodingAgentResult(
                successful: true,
                payload: [
                    'tasks' => [
                        [
                            'title' => 'OpenCode task',
                            'description' => 'Created with the selected OpenCode driver.',
                            'project_id' => null,
                            'assignee_github_username' => null,
                            'priority' => 'medium',
                            'deadline' => null,
                            'questions' => [],
                        ],
                    ],
                ],
            );
        }
    };

    $this->app->instance(OpenCodeCodingAgent::class, $agent);

    app()->call([new AnalyzeInputSourceJob($source->id), 'handle']);

    expect(TaskRunLog::query()
        ->where('input_source_id', $source->id)
        ->where('message', 'Input source analysis completed')
        ->sole()
        ->context['agent'])
        ->toBe('opencode');
});
