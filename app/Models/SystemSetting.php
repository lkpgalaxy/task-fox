<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'analyze_source_model',
    'analyze_source_reasoning_effort',
    'plan_model',
    'plan_reasoning_effort',
    'implement_model',
    'implement_reasoning_effort',
    'review_model',
    'review_reasoning_effort',
    'commit_message_model',
    'commit_message_reasoning_effort',
    'retry_limit',
])]
class SystemSetting extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retry_limit' => 'integer',
        ];
    }

    /**
     * @var array<string, string>
     */
    protected $attributes = [
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
        'retry_limit' => 3,
    ];
}
