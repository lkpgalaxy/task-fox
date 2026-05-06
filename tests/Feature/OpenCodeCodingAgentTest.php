<?php

use App\Models\InputSource;
use App\Models\SystemSetting;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunPhaseSession;
use App\Services\CodingAgents\OpenCodeCodingAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('opencode coding agent uses the run workspace as dir and process cwd', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-opencode-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-opencode-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';
    $cwdPath = $workspacePath.'/cwd.txt';
    $permissionPath = $workspacePath.'/permission.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/opencode',
        "#!/bin/sh\npwd > ".escapeshellarg($cwdPath)."\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '%s' \"\$OPENCODE_PERMISSION\" > ".escapeshellarg($permissionPath)."\nprintf '%s\n' ".escapeshellarg(file_get_contents(base_path('tests/Fixtures/opencode/success-events.jsonl')))."\n"
    );
    chmod($binPath.'/opencode', 0755);

    $task = Task::create([
        'title' => 'Implement in workspace',
        'description' => 'The coding agent must run inside the project workspace.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'implement_model' => 'openai/gpt-5.5',
        'implement_reasoning_effort' => 'medium',
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/workspace',
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);

    $result = (new OpenCodeCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->run($task, $run);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and(trim((string) file_get_contents($cwdPath)))->toBe($workspacePath)
        ->and($args)->toContain('run')
        ->and($args)->toContain('--format')
        ->and($args[array_search('--format', $args, true) + 1])->toBe('json')
        ->and($args)->toContain('--dir')
        ->and($args[array_search('--dir', $args, true) + 1])->toBe($workspacePath)
        ->and($args)->toContain('--model')
        ->and($args[array_search('--model', $args, true) + 1])->toBe('openai/gpt-5.5')
        ->and($args)->toContain('--variant')
        ->and($args[array_search('--variant', $args, true) + 1])->toBe('medium')
        ->and(trim((string) file_get_contents($permissionPath)))->toBe('"allow"')
        ->and($result->context['session_id'])->toBe('session-success-123');
});

test('opencode planning uses read only permission payload and extracts proposed plan', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-opencode-plan-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-opencode-plan-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';
    $permissionPath = $workspacePath.'/permission.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/opencode',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '%s' \"\$OPENCODE_PERMISSION\" > ".escapeshellarg($permissionPath)."\ncase \"$*\" in\n  *'--format'*) printf '%s\n' ".escapeshellarg(file_get_contents(base_path('tests/Fixtures/opencode/success-events.jsonl')))." ;;\n  *) printf '{\"pass\":true}\n' ;;\nesac\n"
    );
    chmod($binPath.'/opencode', 0755);

    $task = Task::create([
        'title' => 'Plan in workspace',
        'description' => 'The coding agent must plan without mutating files.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'plan_model' => 'openai/gpt-5.5',
        'plan_reasoning_effort' => 'high',
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_PLANNING,
        'branch_name' => 'task/planning',
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);

    $result = (new OpenCodeCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->plan($task, $run);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and($result->payload['plan'])->toBe("## Plan\n\n- Inspect files.")
        ->and($args)->toContain('--variant')
        ->and($args[array_search('--variant', $args, true) + 1])->toBe('high')
        ->and(trim((string) file_get_contents($permissionPath)))->toContain('"read":"allow"')
        ->and(trim((string) file_get_contents($permissionPath)))->toContain('"edit":"deny"');
});

test('opencode implementation omits the model flag when the model is blank', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-opencode-no-model-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-opencode-no-model-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/opencode',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '%s\n' ".escapeshellarg(file_get_contents(base_path('tests/Fixtures/opencode/success-events.jsonl')))."\n"
    );
    chmod($binPath.'/opencode', 0755);

    $task = Task::create([
        'title' => 'Implement without model',
        'description' => 'The coding agent should fall back to OpenCode defaults.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'implement_model' => null,
        'implement_reasoning_effort' => null,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/workspace',
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);

    $result = (new OpenCodeCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->run($task, $run);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and($args)->not->toContain('--model')
        ->and($args)->not->toContain('--variant');
});

test('opencode resumes persisted implementation and review sessions with the expected permissions', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-opencode-resume-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-opencode-resume-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';
    $permissionPath = $workspacePath.'/permission.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/opencode',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '%s' \"\$OPENCODE_PERMISSION\" > ".escapeshellarg($permissionPath)."\nprintf '%s\n' ".escapeshellarg(file_get_contents(base_path('tests/Fixtures/opencode/success-events.jsonl')))."\n"
    );
    chmod($binPath.'/opencode', 0755);

    $task = Task::create([
        'title' => 'Resume implementation',
        'description' => 'Resume support should reuse stored sessions.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'implement_model' => 'openai/gpt-5.5',
        'review_model' => 'openai/gpt-5.5',
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/resume',
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);
    TaskRunPhaseSession::create([
        'task_run_id' => $run->id,
        'phase' => TaskRunPhaseSession::PHASE_IMPLEMENT,
        'status' => TaskRunPhaseSession::STATUS_COMPLETED,
        'session_id' => 'session-implement-123',
    ]);
    TaskRunPhaseSession::create([
        'task_run_id' => $run->id,
        'phase' => TaskRunPhaseSession::PHASE_REVIEW,
        'status' => TaskRunPhaseSession::STATUS_COMPLETED,
        'session_id' => 'session-review-123',
    ]);

    $agent = new OpenCodeCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]);

    $implementation = $agent->resumeImplementation($task, $run, 'Fix the failing test.', 2);
    $implementationArgs = file($argsPath, FILE_IGNORE_NEW_LINES);
    $implementationPermission = trim((string) file_get_contents($permissionPath));

    expect($implementation->successful)->toBeTrue()
        ->and($implementationArgs)->toContain('--session')
        ->and($implementationArgs[array_search('--session', $implementationArgs, true) + 1])->toBe('session-implement-123')
        ->and($implementationPermission)->toBe('"allow"');

    $review = $agent->resumeReview($task, $run, 'Re-check the latest diff.', 2);
    $reviewPermission = trim((string) file_get_contents($permissionPath));
    $reviewCommand = $review->context['command'];

    expect($reviewCommand)->toContain('--session')
        ->and($reviewCommand[array_search('--session', $reviewCommand, true) + 1])->toBe('session-review-123')
        ->and($reviewPermission)->toContain('"read":"allow"')
        ->and($reviewPermission)->toContain('"bash":"deny"');
});

test('opencode treats an exit zero error event as a failed run', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-opencode-error-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-opencode-error-bin-'.uniqid();

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/opencode',
        "#!/bin/sh\nprintf '%s\n' ".escapeshellarg(file_get_contents(base_path('tests/Fixtures/opencode/failure-error-event.jsonl')))."\nexit 0\n"
    );
    chmod($binPath.'/opencode', 0755);

    $task = Task::create([
        'title' => 'Implementation failure',
        'description' => 'JSON error events should fail even when the process exits zero.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create();
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/failure',
        'workspace_path' => $workspacePath,
    ]);

    $result = (new OpenCodeCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->run($task, $run);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Token refresh failed: 401')
        ->and($result->messages)->toContain('OpenCode implementation command failed.')
        ->and($result->messages)->not->toContain('OpenCode implementation command completed.')
        ->and($result->context['session_id'])->toBe('session-error-123');
});

test('opencode surfaces non json stdout failures and does not consult session diff storage', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-opencode-stacktrace-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-opencode-stacktrace-bin-'.uniqid();

    mkdir($workspacePath, 0777, true);
    mkdir($binPath, 0777, true);
    mkdir($workspacePath.'/storage/session_diff', 0777, true);
    file_put_contents(
        $workspacePath.'/storage/session_diff/session-success-123.json',
        json_encode(['message' => 'This file must never be read at runtime.'])
    );
    file_put_contents(
        $binPath.'/opencode',
        "#!/bin/sh\nprintf '%s\n' ".escapeshellarg(file_get_contents(base_path('tests/Fixtures/opencode/failure-default-agent.txt')))."\nexit 0\n"
    );
    chmod($binPath.'/opencode', 0755);

    $task = Task::create([
        'title' => 'Stacktrace failure',
        'description' => 'Raw stdout failures should be surfaced directly.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create();
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/stacktrace',
        'workspace_path' => $workspacePath,
    ]);

    $result = (new OpenCodeCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->run($task, $run);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('default agent "Sisyphus - Ultraworker" not found')
        ->and($result->messages)->toContain('OpenCode implementation command failed.')
        ->and($result->messages)->not->toContain('OpenCode implementation command completed.')
        ->and($result->error)->not->toContain('This file must never be read at runtime.');
});

test('opencode planning succeeds from json assistant output with recoverable tool errors even on non zero exit', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-opencode-plan-tool-error-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-opencode-plan-tool-error-bin-'.uniqid();

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/opencode',
        <<<'SH'
#!/bin/sh
cat <<'JSON'
{"type":"message.completed","sessionID":"session-plan-tool-error-123","model":"openai/gpt-5.5","message":{"content":[{"type":"tool_use","name":"grep","state":{"status":"error","error":{"message":"ripgrep exited with status 2"}}},{"type":"text","text":"<proposed_plan>\n## Plan\n\n- Inspect the affected files.\n</proposed_plan>"}]}}
JSON
exit 9
SH
    );
    chmod($binPath.'/opencode', 0755);

    $task = Task::create([
        'title' => 'Plan after tool recovery',
        'description' => 'Recoverable tool errors should not fail planning.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'plan_model' => 'openai/gpt-5.5',
        'plan_reasoning_effort' => 'high',
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_PLANNING,
        'branch_name' => 'task/recoverable-plan',
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);

    $result = (new OpenCodeCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->plan($task, $run);

    expect($result->successful)->toBeTrue()
        ->and($result->payload['plan'])->toBe("## Plan\n\n- Inspect the affected files.")
        ->and($result->context['tool_errors'])->toHaveCount(1)
        ->and($result->context['tool_errors'][0]['message'])->toBe('ripgrep exited with status 2');
});

test('opencode analysis accepts plain json output and stored file metadata', function () {
    Storage::fake('local');
    Storage::disk('local')->put('input-sources/roadmap.pdf', '%PDF-1.4');

    $workspacePath = sys_get_temp_dir().'/task-fox-opencode-analysis-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-opencode-analysis-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';
    $permissionPath = $workspacePath.'/permission.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/opencode',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '%s' \"\$OPENCODE_PERMISSION\" > ".escapeshellarg($permissionPath)."\nprintf '{\"tasks\":[]}'\n"
    );
    chmod($binPath.'/opencode', 0755);

    SystemSetting::factory()->create([
        'analyze_source_model' => 'openai/gpt-5.4',
        'analyze_source_reasoning_effort' => 'medium',
    ]);

    $inputSource = new InputSource([
        'title' => 'Roadmap PDF',
        'filename' => 'roadmap.pdf',
        'file_disk' => 'local',
        'file_path' => 'input-sources/roadmap.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 8,
    ]);

    $result = (new OpenCodeCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->analyzeInputSource($inputSource, []);

    $capturedPrompt = (string) file_get_contents($argsPath);

    expect($result->successful)->toBeTrue()
        ->and(trim((string) file_get_contents($permissionPath)))->toBe('"allow"')
        ->and($capturedPrompt)->toContain('Input source kind: stored uploaded file')
        ->and($capturedPrompt)->toContain(Storage::disk('local')->path('input-sources/roadmap.pdf'))
        ->and($capturedPrompt)->toContain('analyze the PDF directly from disk');
});

test('opencode review classifier uses a fresh read only run with no session', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-opencode-review-classifier-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-opencode-review-classifier-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';
    $permissionPath = $workspacePath.'/permission.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/opencode',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '%s' \"\$OPENCODE_PERMISSION\" > ".escapeshellarg($permissionPath)."\nprintf '{\"pass\":false}'\n"
    );
    chmod($binPath.'/opencode', 0755);

    $task = Task::create([
        'title' => 'Review classifier override',
        'description' => 'Review classification should come from OpenCode.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create();
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_REVIEWING_CHANGES,
        'branch_name' => 'task/review-classifier',
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);

    $method = (new ReflectionClass(OpenCodeCodingAgent::class))->getMethod('reviewTextHasFindings');
    $result = $method->invoke(
        new OpenCodeCodingAgent([
            'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
        ]),
        $run,
        'There are no blocking issues in scope of this patch.',
    );

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result)->toBeTrue()
        ->and($args)->toContain('run')
        ->and($args)->not->toContain('--session')
        ->and(trim((string) file_get_contents($permissionPath)))->toContain('"read":"allow"');
});
