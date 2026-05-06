<?php

namespace App\Models;

use Database\Factories\AiModelPricingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'model',
    'display_name',
    'input_per_million_usd',
    'cached_input_per_million_usd',
    'output_per_million_usd',
    'pricing_mode',
    'source_url',
    'verified_at',
])]
class AiModelPricing extends Model
{
    /** @use HasFactory<AiModelPricingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_per_million_usd' => 'decimal:6',
            'cached_input_per_million_usd' => 'decimal:6',
            'output_per_million_usd' => 'decimal:6',
            'verified_at' => 'datetime',
        ];
    }
}
