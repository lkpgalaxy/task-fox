<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('users can update their own profile', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
        'github_username' => 'oldhub',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'github_username' => 'newhub',
        ])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh())
        ->name->toBe('New Name')
        ->email->toBe('new@example.com')
        ->github_username->toBe('newhub');
});

test('users can save a github token without exposing the raw token', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'github_username' => 'token-owner',
            'github_token' => 'ghp_profile_token',
        ])
        ->assertRedirect(route('profile.edit'));

    $rawToken = DB::table('users')->whereKey($user->id)->value('github_token');

    expect($user->refresh())
        ->github_token->toBe('ghp_profile_token')
        ->and($rawToken)->not->toBe('ghp_profile_token');

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('profile/Edit')
            ->where('auth.user.has_github_token', true)
            ->missing('auth.user.github_token')
        );
});

test('blank github token updates preserve the existing token', function () {
    $user = User::factory()->create([
        'github_token' => 'ghp_existing_token',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'github_username' => $user->github_username,
            'github_token' => '',
        ])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->github_token)->toBe('ghp_existing_token');
});

test('users can replace an existing github token', function () {
    $user = User::factory()->create([
        'github_token' => 'ghp_existing_token',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'github_username' => $user->github_username,
            'github_token' => 'ghp_replacement_token',
        ])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->github_token)->toBe('ghp_replacement_token');
});

test('profile page receives flashed save status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['status' => 'Profile updated.'])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('profile/Edit')
            ->where('flash.status', 'Profile updated.')
        );
});

test('users can update their own password with current password confirmation', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($user)
        ->patch(route('profile.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasErrors('current_password');

    $this->actingAs($user)
        ->patch(route('profile.password.update'), [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect(route('profile.edit'));

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});
