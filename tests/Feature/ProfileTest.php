<?php

use App\Models\SystemSetting;
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

test('admins can update automation model settings from the profile page', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
    ]);

    SystemSetting::factory()->create([
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
    ]);

    $this->actingAs($admin)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('profile/Edit')
            ->where('automationSettings.analyze_source_model', 'gpt-5.4')
            ->where('automationSettings.analyze_source_reasoning_effort', 'medium')
            ->where('automationSettings.plan_model', 'gpt-5.5')
            ->where('automationSettings.plan_reasoning_effort', 'high')
            ->where('automationSettings.implement_model', 'gpt-5.5')
            ->where('automationSettings.implement_reasoning_effort', 'medium')
            ->where('automationSettings.review_model', 'gpt-5.5')
            ->where('automationSettings.review_reasoning_effort', 'high')
            ->where('automationSettings.commit_message_model', 'gpt-5.4-mini')
            ->where('automationSettings.commit_message_reasoning_effort', 'medium')
        );

    $this->actingAs($admin)
        ->patch(route('profile.automation.update'), [
            'analyze_source_model' => 'gpt-5.5',
            'analyze_source_reasoning_effort' => 'low',
            'plan_model' => 'gpt-5.5',
            'plan_reasoning_effort' => 'xhigh',
            'implement_model' => '',
            'implement_reasoning_effort' => '',
            'review_model' => '  ',
            'review_reasoning_effort' => '  ',
            'commit_message_model' => 'gpt-5.4-mini',
            'commit_message_reasoning_effort' => 'medium',
        ])
        ->assertRedirect(route('profile.edit'));

    expect(SystemSetting::query()->sole())
        ->analyze_source_model->toBe('gpt-5.5')
        ->and(SystemSetting::query()->sole()->analyze_source_reasoning_effort)->toBe('low')
        ->and(SystemSetting::query()->sole()->plan_model)->toBe('gpt-5.5')
        ->and(SystemSetting::query()->sole()->plan_reasoning_effort)->toBe('xhigh')
        ->and(SystemSetting::query()->sole()->implement_model)->toBeNull()
        ->and(SystemSetting::query()->sole()->implement_reasoning_effort)->toBeNull()
        ->and(SystemSetting::query()->sole()->review_model)->toBeNull()
        ->and(SystemSetting::query()->sole()->review_reasoning_effort)->toBeNull()
        ->and(SystemSetting::query()->sole()->commit_message_model)->toBe('gpt-5.4-mini')
        ->and(SystemSetting::query()->sole()->commit_message_reasoning_effort)->toBe('medium');
});

test('automation model settings reject unsupported reasoning effort values', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
    ]);

    $this->actingAs($admin)
        ->patch(route('profile.automation.update'), [
            'analyze_source_model' => 'gpt-5.4',
            'analyze_source_reasoning_effort' => 'minimal',
            'plan_model' => 'gpt-5.5',
            'plan_reasoning_effort' => 'high',
            'implement_model' => 'gpt-5.5',
            'implement_reasoning_effort' => 'medium',
            'review_model' => 'gpt-5.5',
            'review_reasoning_effort' => 'high',
            'commit_message_model' => 'gpt-5.4-mini',
            'commit_message_reasoning_effort' => 'medium',
        ])
        ->assertSessionHasErrors('analyze_source_reasoning_effort');
});

test('non-admins cannot update automation model settings', function () {
    $user = User::factory()->create();

    SystemSetting::factory()->create([
        'analyze_source_model' => 'gpt-5.5',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.automation.update'), [
            'analyze_source_model' => 'gpt-4.1-mini',
            'analyze_source_reasoning_effort' => 'low',
            'plan_model' => 'gpt-4.1-mini',
            'plan_reasoning_effort' => 'low',
            'implement_model' => 'gpt-4.1-mini',
            'implement_reasoning_effort' => 'low',
            'review_model' => 'gpt-4.1-mini',
            'review_reasoning_effort' => 'low',
            'commit_message_model' => 'gpt-4.1-mini',
            'commit_message_reasoning_effort' => 'low',
        ])
        ->assertForbidden();

    expect(SystemSetting::query()->sole()->analyze_source_model)->toBe('gpt-5.5');
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
