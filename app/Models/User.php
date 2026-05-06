<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'github_username',
    'github_token',
    'role',
    'disabled_at',
    'automation_agent_driver',
    'automation_coding_agent_driver',
    'automation_external_task_provider',
])]
#[Hidden(['password', 'remember_token', 'github_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_user_id');
    }

    public function approvedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'approved_by_user_id');
    }

    public function reviewTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'reviewer_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'github_token' => 'encrypted',
            'disabled_at' => 'datetime',
        ];
    }
}
