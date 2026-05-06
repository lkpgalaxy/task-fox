<?php

namespace Database\Seeders;

use App\Models\AiModelPricing;
use Illuminate\Database\Seeder;

class AiModelPricingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sourceUrl = 'https://platform.openai.com/docs/pricing';
        $verifiedAt = now();

        collect([
            [
                'model' => 'gpt-5.5',
                'display_name' => 'GPT-5.5',
                'input_per_million_usd' => 5.00,
                'cached_input_per_million_usd' => 0.50,
                'output_per_million_usd' => 30.00,
            ],
            [
                'model' => 'gpt-5.4',
                'display_name' => 'GPT-5.4',
                'input_per_million_usd' => 2.50,
                'cached_input_per_million_usd' => 0.25,
                'output_per_million_usd' => 15.00,
            ],
            [
                'model' => 'gpt-5.4-mini',
                'display_name' => 'GPT-5.4 Mini',
                'input_per_million_usd' => 0.75,
                'cached_input_per_million_usd' => 0.075,
                'output_per_million_usd' => 4.50,
            ],
            [
                'model' => 'gpt-5.4-nano',
                'display_name' => 'GPT-5.4 Nano',
                'input_per_million_usd' => 0.20,
                'cached_input_per_million_usd' => 0.02,
                'output_per_million_usd' => 1.25,
            ],
            [
                'model' => 'gpt-5.3-codex-spark',
                'display_name' => 'GPT-5.3 Codex Spark',
                'input_per_million_usd' => 2.50,
                'cached_input_per_million_usd' => 0.25,
                'output_per_million_usd' => 15.00,
            ],
        ])->each(function (array $pricing) use ($sourceUrl, $verifiedAt): void {
            AiModelPricing::query()->updateOrCreate(
                ['model' => $pricing['model']],
                [
                    ...$pricing,
                    'pricing_mode' => 'standard',
                    'source_url' => $sourceUrl,
                    'verified_at' => $verifiedAt,
                ],
            );
        });
    }
}
