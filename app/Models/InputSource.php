<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'agent_driver',
    'filename',
    'file_disk',
    'file_path',
    'mime_type',
    'file_size',
    'analysis_result',
    'analysis_status',
    'last_analysis_error',
])]
class InputSource extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'analysis_result' => 'array',
        ];
    }

    public function hasStoredFile(): bool
    {
        return $this->file_disk !== null && $this->file_path !== null;
    }

    /**
     * @return HasMany<int, Task>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
