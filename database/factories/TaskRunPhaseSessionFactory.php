<?php

namespace Database\Factories;

use App\Models\TaskRun;
use App\Models\TaskRunPhaseSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskRunPhaseSession>
 */
class TaskRunPhaseSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_run_id' => TaskRun::factory(),
            'phase' => $this->faker->randomElement(TaskRunPhaseSession::PHASES),
            'status' => TaskRunPhaseSession::STATUS_PENDING,
            'session_id' => null,
            'model' => null,
            'reasoning_effort' => null,
            'attempt_count' => 0,
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'total_cost_usd' => null,
            'command' => null,
            'last_error' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
