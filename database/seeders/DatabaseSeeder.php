<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate([
            'email' => 'lamkimphu258@gmail.com',
        ], [
            'name' => 'Local Admin',
            'github_username' => 'lamkimphu258',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'disabled_at' => null,
        ]);

        Project::query()->updateOrCreate([
            'name' => 'todo-test',
        ], [
            'workspace_path' => sys_get_temp_dir().'/todo-test-'.Str::random(8),
            'base_branch' => 'staging',
        ]);
    }
}
