<?php

namespace Database\Factories;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemSetting>
 */
class SystemSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analyze_source_model' => 'gpt-5.4',
            'analyze_source_reasoning_effort' => 'medium',
            'plan_model' => 'gpt-5.5',
            'plan_reasoning_effort' => 'high',
            'implement_model' => 'gpt-5.5',
            'implement_reasoning_effort' => 'medium',
            'review_model' => 'gpt-5.5',
            'review_reasoning_effort' => 'high',
            'commit_message_model' => 'gpt-5.4-mini',
            'commit_message_reasoning_effort' => 'medium',
        ];
    }
}
