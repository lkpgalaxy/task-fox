<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('non-admin users cannot access user management', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('users.index'))
        ->assertForbidden();
});

test('admins can create update disable and enable users', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Managed User',
            'email' => 'managed@example.com',
            'github_username' => 'managed',
            'role' => User::ROLE_USER,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
        ->assertRedirect(route('users.index'));

    $managedUser = User::query()->where('email', 'managed@example.com')->firstOrFail();

    expect(Hash::check('secret-password', $managedUser->password))->toBeTrue();

    $this->actingAs($admin)
        ->patch(route('users.update', $managedUser), [
            'name' => 'Managed Admin',
            'email' => 'managed-admin@example.com',
            'github_username' => 'managed-admin',
            'role' => User::ROLE_ADMIN,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertRedirect(route('users.index'));

    expect($managedUser->refresh())
        ->name->toBe('Managed Admin')
        ->email->toBe('managed-admin@example.com')
        ->github_username->toBe('managed-admin')
        ->role->toBe(User::ROLE_ADMIN);

    $this->actingAs($admin)
        ->patch(route('users.disable', $managedUser))
        ->assertRedirect(route('users.index'));

    expect($managedUser->refresh()->disabled_at)->not->toBeNull();

    $this->actingAs($admin)
        ->patch(route('users.enable', $managedUser))
        ->assertRedirect(route('users.index'));

    expect($managedUser->refresh()->disabled_at)->toBeNull();
});

test('last active admin cannot be disabled or demoted', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('users.disable', $admin))
        ->assertSessionHasErrors('user');

    $this->actingAs($admin)
        ->patch(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'github_username' => $admin->github_username,
            'role' => User::ROLE_USER,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertSessionHasErrors('role');

    expect($admin->refresh())
        ->role->toBe(User::ROLE_ADMIN)
        ->disabled_at->toBeNull();
});

test('admins cannot disable their own account', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('users.disable', $admin))
        ->assertSessionHasErrors('user');

    expect($admin->refresh()->disabled_at)->toBeNull();
});
