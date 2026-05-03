<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'workspace_path',
    'url',
    'database_name',
    'database_username',
    'database_password',
    'credential_username',
    'credential_password',
    'base_branch',
])]
class Project extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'database_password' => 'encrypted',
            'credential_password' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<int, InputSource>
     */
    public function inputSources(): HasMany
    {
        return $this->hasMany(InputSource::class);
    }

    /**
     * @return HasMany<int, Task>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<int, AiRun>
     */
    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /**
     * @return array{id: int|null, name: string, workspace_path: string, url: string|null, database_name: string|null, database_username: string|null, base_branch: string|null, has_database_password: bool, has_credential_password: bool, has_credential_username: bool, has_database_username: bool}
     */
    public function asSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => (string) $this->name,
            'workspace_path' => (string) $this->workspace_path,
            'url' => $this->url,
            'database_name' => $this->database_name,
            'database_username' => $this->database_username,
            'base_branch' => $this->base_branch,
            'has_database_password' => $this->database_password !== null && $this->database_password !== '',
            'has_credential_password' => $this->credential_password !== null && $this->credential_password !== '',
            'has_credential_username' => $this->credential_username !== null && $this->credential_username !== '',
            'has_database_username' => $this->database_username !== null && $this->database_username !== '',
        ];
    }
}
