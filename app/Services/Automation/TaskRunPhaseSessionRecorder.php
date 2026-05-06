<?php

namespace App\Services\Automation;

use App\DataTransferObjects\CodingAgentInvocation;
use App\Models\AiModelPricing;
use App\Models\TaskRun;
use App\Models\TaskRunPhaseSession;
use Illuminate\Support\Arr;

class TaskRunPhaseSessionRecorder
{
    public function start(TaskRun $run, string $phase, array $attributes = []): TaskRunPhaseSession
    {
        $session = $this->phaseSession($run, $phase);

        $session->fill(array_merge([
            'status' => TaskRunPhaseSession::STATUS_RUNNING,
            'attempt_count' => ((int) $session->attempt_count) + 1,
            'started_at' => $session->started_at ?? now(),
            'finished_at' => null,
        ], $attributes));
        $session->save();

        return $session->refresh();
    }

    public function recordAgentResult(TaskRun $run, string $phase, CodingAgentInvocation $invocation, bool $successful, ?string $lastError = null): TaskRunPhaseSession
    {
        $session = $this->phaseSession($run, $phase);
        $usage = $invocation->usage;
        $pricing = $this->lookupPricing($invocation->model);
        $costDelta = $pricing !== null
            ? $this->calculateCost($usage, $pricing)
            : null;

        $session->fill([
            'status' => $successful ? TaskRunPhaseSession::STATUS_COMPLETED : TaskRunPhaseSession::STATUS_FAILED,
            'session_id' => $invocation->sessionId ?? $session->session_id,
            'model' => $invocation->model ?? $session->model,
            'reasoning_effort' => $invocation->reasoningEffort ?? $session->reasoning_effort,
            'input_tokens' => ((int) $session->input_tokens) + (int) Arr::get($usage, 'input_tokens', 0),
            'cached_input_tokens' => ((int) $session->cached_input_tokens) + (int) Arr::get($usage, 'cached_input_tokens', 0),
            'output_tokens' => ((int) $session->output_tokens) + (int) Arr::get($usage, 'output_tokens', 0),
            'total_tokens' => ((int) $session->total_tokens) + (int) Arr::get($usage, 'total_tokens', 0),
            'total_cost_usd' => $costDelta !== null
                ? round(((float) $session->total_cost_usd) + $costDelta, 8)
                : $session->total_cost_usd,
            'command' => $invocation->command !== [] ? $invocation->command : $session->command,
            'last_error' => $successful ? null : $lastError,
            'finished_at' => now(),
        ]);
        $session->save();

        return $session->refresh();
    }

    /**
     * @param  array<int, string>|string  $command
     */
    public function recordTestResult(TaskRun $run, array|string $command, bool $successful, ?string $lastError = null): TaskRunPhaseSession
    {
        $commandPayload = is_array($command) ? $command : ['shell' => $command];
        $session = $this->phaseSession($run, TaskRunPhaseSession::PHASE_TEST);

        $session->fill([
            'status' => $successful ? TaskRunPhaseSession::STATUS_COMPLETED : TaskRunPhaseSession::STATUS_FAILED,
            'attempt_count' => ((int) $session->attempt_count) + 1,
            'command' => $commandPayload,
            'started_at' => $session->started_at ?? now(),
            'finished_at' => now(),
            'last_error' => $successful ? null : $lastError,
        ]);
        $session->save();

        return $session->refresh();
    }

    public function markFailed(TaskRun $run, string $phase, string $error): TaskRunPhaseSession
    {
        $session = $this->phaseSession($run, $phase);
        $session->fill([
            'status' => TaskRunPhaseSession::STATUS_FAILED,
            'last_error' => $error,
            'finished_at' => now(),
        ]);
        $session->save();

        return $session->refresh();
    }

    private function phaseSession(TaskRun $run, string $phase): TaskRunPhaseSession
    {
        return TaskRunPhaseSession::query()->firstOrCreate(
            [
                'task_run_id' => $run->id,
                'phase' => $phase,
            ],
            [
                'status' => TaskRunPhaseSession::STATUS_PENDING,
            ],
        );
    }

    private function lookupPricing(?string $model): ?AiModelPricing
    {
        if ($model === null || trim($model) === '') {
            return null;
        }

        return AiModelPricing::query()
            ->where('model', trim($model))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function calculateCost(array $usage, AiModelPricing $pricing): float
    {
        return (
            ((int) Arr::get($usage, 'input_tokens', 0) / 1_000_000) * (float) $pricing->input_per_million_usd
        ) + (
            ((int) Arr::get($usage, 'cached_input_tokens', 0) / 1_000_000) * (float) $pricing->cached_input_per_million_usd
        ) + (
            ((int) Arr::get($usage, 'output_tokens', 0) / 1_000_000) * (float) $pricing->output_per_million_usd
        );
    }
}
