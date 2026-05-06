<?php

namespace Database\Factories;

use App\Models\AiModelPricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModelPricing>
 */
class AiModelPricingFactory extends Factory
{
    protected $model = AiModelPricing::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model' => 'gpt-5.4',
            'display_name' => 'GPT-5.4',
            'input_per_million_usd' => 2.50,
            'cached_input_per_million_usd' => 0.25,
            'output_per_million_usd' => 15.00,
            'pricing_mode' => 'standard',
            'source_url' => 'https://platform.openai.com/docs/pricing',
            'verified_at' => now(),
        ];
    }
}
