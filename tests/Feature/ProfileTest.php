<?php

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ExternalTaskProviders\NullExternalTaskProvider;
use App\Services\SystemSettingsResolver;
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
    configureAutomationDriverOptions();

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
    ]);

    SystemSetting::factory()->create([
        'agent_driver' => 'codex',
        'coding_agent_driver' => 'codex',
        'external_task_provider' => 'linear',
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
    ]);

    $this->actingAs($admin)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('profile/Edit')
            ->where('automationSettings.agent_driver', 'codex')
            ->where('automationSettings.coding_agent_driver', 'codex')
            ->where('automationSettings.external_task_provider', 'linear')
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
            ->where('automationSettings.retry_limit', 3)
        );

    $this->actingAs($admin)
        ->patch(route('profile.automation.update'), [
            'agent_driver' => 'codex',
            'coding_agent_driver' => 'codex',
            'external_task_provider' => '',
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
            'retry_limit' => '5',
        ])
        ->assertRedirect(route('profile.edit'));

    $settings = SystemSetting::query()->sole();

    expect($settings->agent_driver)->toBe('codex')
        ->and($settings->coding_agent_driver)->toBe('codex')
        ->and($settings->external_task_provider)->toBeNull()
        ->and($settings->analyze_source_model)->toBe('gpt-5.5')
        ->and($settings->analyze_source_reasoning_effort)->toBe('low')
        ->and($settings->plan_model)->toBe('gpt-5.5')
        ->and($settings->plan_reasoning_effort)->toBe('xhigh')
        ->and($settings->implement_model)->toBeNull()
        ->and($settings->implement_reasoning_effort)->toBeNull()
        ->and($settings->review_model)->toBeNull()
        ->and($settings->review_reasoning_effort)->toBeNull()
        ->and($settings->commit_message_model)->toBe('gpt-5.4-mini')
        ->and($settings->commit_message_reasoning_effort)->toBe('medium')
        ->and($settings->retry_limit)->toBe(5);

    $this->actingAs($admin)
        ->patch(route('profile.automation.update'), validAutomationSettingsPayload([
            'retry_limit' => '-1',
        ]))
        ->assertRedirect(route('profile.edit'));

    expect(SystemSetting::query()->sole()->retry_limit)->toBe(-1);
});

test('automation model settings reject unsupported reasoning effort values', function () {
    configureAutomationDriverOptions();

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

test('automation settings reject invalid retry limits', function (mixed $retryLimit) {
    configureAutomationDriverOptions();

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
    ]);

    $this->actingAs($admin)
        ->patch(route('profile.automation.update'), validAutomationSettingsPayload([
            'retry_limit' => $retryLimit,
        ]))
        ->assertSessionHasErrors('retry_limit');
})->with([
    'zero' => ['0'],
    'below negative one' => ['-2'],
    'decimal' => ['1.5'],
]);

test('non-admins cannot update automation model settings', function () {
    configureAutomationDriverOptions();

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

test('users can save and clear their personal automation overrides', function () {
    configureAutomationDriverOptions();

    SystemSetting::factory()->create([
        'agent_driver' => 'codex',
        'coding_agent_driver' => 'codex',
        'external_task_provider' => 'linear',
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('profile/Edit')
            ->where('automationPreferences.automation_agent_driver', null)
            ->where('automationPreferences.automation_coding_agent_driver', null)
            ->where('automationPreferences.automation_external_task_provider', null)
        );

    $this->actingAs($user)
        ->patch(route('profile.automation.preferences.update'), [
            'automation_agent_driver' => 'codex',
            'automation_coding_agent_driver' => 'codex',
            'automation_external_task_provider' => SystemSettingsResolver::DISABLED_EXTERNAL_TASK_PROVIDER,
        ])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh())
        ->automation_agent_driver->toBe('codex')
        ->automation_coding_agent_driver->toBe('codex')
        ->automation_external_task_provider->toBe(SystemSettingsResolver::DISABLED_EXTERNAL_TASK_PROVIDER);

    $this->actingAs($user)
        ->patch(route('profile.automation.preferences.update'), [
            'automation_agent_driver' => '',
            'automation_coding_agent_driver' => '',
            'automation_external_task_provider' => '',
        ])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh())
        ->automation_agent_driver->toBeNull()
        ->automation_coding_agent_driver->toBeNull()
        ->automation_external_task_provider->toBeNull();
});

test('automation settings and personal overrides accept opencode', function () {
    configureAutomationDriverOptions();

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
    ]);
    $user = User::factory()->create();

    SystemSetting::factory()->create([
        'agent_driver' => 'codex',
        'coding_agent_driver' => 'codex',
    ]);

    $this->actingAs($admin)
        ->patch(route('profile.automation.update'), validAutomationSettingsPayload([
            'agent_driver' => 'opencode',
            'coding_agent_driver' => 'opencode',
        ]))
        ->assertRedirect(route('profile.edit'));

    expect(SystemSetting::query()->sole())
        ->agent_driver->toBe('opencode')
        ->coding_agent_driver->toBe('opencode');

    $this->actingAs($user)
        ->patch(route('profile.automation.preferences.update'), [
            'automation_agent_driver' => 'opencode',
            'automation_coding_agent_driver' => 'opencode',
            'automation_external_task_provider' => '',
        ])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh())
        ->automation_agent_driver->toBe('opencode')
        ->automation_coding_agent_driver->toBe('opencode');
});

test('automation settings reject invalid driver and provider keys', function () {
    configureAutomationDriverOptions();

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
    ]);
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('profile.automation.update'), validAutomationSettingsPayload([
            'agent_driver' => 'invalid-agent',
            'external_task_provider' => 'invalid-provider',
        ]))
        ->assertSessionHasErrors(['agent_driver', 'external_task_provider']);

    $this->actingAs($user)
        ->patch(route('profile.automation.preferences.update'), [
            'automation_agent_driver' => 'invalid-agent',
            'automation_coding_agent_driver' => 'codex',
            'automation_external_task_provider' => 'invalid-provider',
        ])
        ->assertSessionHasErrors(['automation_agent_driver', 'automation_external_task_provider']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validAutomationSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'agent_driver' => 'codex',
        'coding_agent_driver' => 'codex',
        'external_task_provider' => 'linear',
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
        'retry_limit' => '3',
    ], $overrides);
}

function configureAutomationDriverOptions(): void
{
    config([
        'automation.supported_agent_drivers' => [
            'codex' => 'Codex',
            'opencode' => 'OpenCode',
        ],
        'automation.supported_coding_agent_drivers' => [
            'codex' => 'Codex',
            'opencode' => 'OpenCode',
        ],
        'automation.external_task_providers' => [
            'linear' => [
                'label' => 'Linear',
                'class' => NullExternalTaskProvider::class,
            ],
        ],
    ]);
}

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
