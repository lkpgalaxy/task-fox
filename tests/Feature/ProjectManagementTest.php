<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('projects can be created with required fields', function () {
    $response = $this->post(route('projects.store'), [
        'name' => 'Core Platform',
        'workspace_path' => '/tmp/core-platform',
    ]);

    $response->assertRedirect(route('projects.index'));

    $project = Project::query()->sole();
    expect($project->name)->toBe('Core Platform')
        ->and($project->workspace_path)->toBe('/tmp/core-platform')
        ->and($project->url)->toBeNull()
        ->and($project->database_name)->toBeNull()
        ->and($project->database_username)->toBeNull()
        ->and($project->database_password)->toBeNull()
        ->and($project->credential_username)->toBeNull()
        ->and($project->credential_password)->toBeNull()
        ->and($project->base_branch)->toBeNull();
});

test('projects can be created with a default reviewer', function () {
    $reviewer = User::factory()->create(['github_username' => 'reviewer-login']);

    $response = $this->post(route('projects.store'), [
        'name' => 'Core Platform',
        'workspace_path' => '/tmp/core-platform',
        'default_reviewer_user_id' => $reviewer->id,
    ]);

    $response->assertRedirect(route('projects.index'));

    $project = Project::query()->sole();
    expect($project->default_reviewer_user_id)->toBe($reviewer->id)
        ->and($project->defaultReviewer->is($reviewer))->toBeTrue();
});

test('projects can be updated with optional credentials and base branch', function () {
    $project = Project::create([
        'name' => 'Legacy Project',
        'workspace_path' => '/tmp/legacy',
        'url' => 'https://github.com/example/legacy',
    ]);

    $response = $this->patch(route('projects.update', $project), [
        'name' => 'Modern Platform',
        'workspace_path' => '/tmp/modern',
        'url' => 'https://github.com/example/modern',
        'database_name' => 'modern_db',
        'database_username' => 'db_user',
        'database_password' => 'db_secret',
        'credential_username' => 'repo_user',
        'credential_password' => 'repo_secret',
        'base_branch' => 'develop',
    ]);

    $response->assertRedirect(route('projects.index'));

    $project->refresh();
    expect($project)
        ->name->toBe('Modern Platform')
        ->workspace_path->toBe('/tmp/modern')
        ->url->toBe('https://github.com/example/modern')
        ->database_name->toBe('modern_db')
        ->database_username->toBe('db_user')
        ->database_password->toBe('db_secret')
        ->credential_username->toBe('repo_user')
        ->credential_password->toBe('repo_secret')
        ->base_branch->toBe('develop');
});

test('projects can be updated with a default reviewer', function () {
    $reviewer = User::factory()->create(['github_username' => 'reviewer-login']);
    $project = Project::create([
        'name' => 'Legacy Project',
        'workspace_path' => '/tmp/legacy',
    ]);

    $response = $this->patch(route('projects.update', $project), [
        'name' => 'Modern Platform',
        'workspace_path' => '/tmp/modern',
        'url' => null,
        'database_name' => null,
        'database_username' => null,
        'database_password' => null,
        'credential_username' => null,
        'credential_password' => null,
        'base_branch' => null,
        'default_reviewer_user_id' => $reviewer->id,
    ]);

    $response->assertRedirect(route('projects.index'));

    expect($project->refresh()->default_reviewer_user_id)->toBe($reviewer->id);
});

test('project index lists users without github usernames as reviewer options', function () {
    $this->withoutVite();

    $reviewer = User::factory()->create([
        'name' => 'No GitHub Reviewer',
        'github_username' => null,
    ]);

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Index')
            ->where('reviewerOptions', fn ($options): bool => $options->contains(
                fn (array $option): bool => $option['id'] === $reviewer->id
                    && $option['name'] === 'No GitHub Reviewer'
                    && $option['github_username'] === null
            )));
});

test('projects can use a default reviewer without a github username', function () {
    $reviewer = User::factory()->create(['github_username' => null]);

    $this->from(route('projects.index'))->post(route('projects.store'), [
        'name' => 'Core Platform',
        'workspace_path' => '/tmp/core-platform',
        'default_reviewer_user_id' => $reviewer->id,
    ])->assertRedirect(route('projects.index'))
        ->assertSessionHasNoErrors();

    expect(Project::query()->sole()->default_reviewer_user_id)->toBe($reviewer->id);
});

test('blank password fields are preserved on project update', function () {
    $project = Project::create([
        'name' => 'Secure Project',
        'workspace_path' => '/tmp/secure',
        'url' => 'https://github.com/example/secure',
        'database_name' => 'secure_db',
        'database_username' => 'db_user',
        'database_password' => 'existing_db_secret',
        'credential_username' => 'repo_user',
        'credential_password' => 'existing_repo_secret',
    ]);

    $response = $this->patch(route('projects.update', $project), [
        'name' => 'Secure Project Updated',
        'workspace_path' => '/tmp/secure-updated',
        'url' => null,
        'database_name' => 'secure_db',
        'database_username' => 'db_user',
        'database_password' => '',
        'credential_username' => 'repo_user',
        'credential_password' => '',
        'base_branch' => null,
    ]);

    $response->assertRedirect(route('projects.index'));

    $project->refresh();
    expect($project->name)->toBe('Secure Project Updated')
        ->and($project->url)->toBeNull()
        ->and($project->database_password)->toBe('existing_db_secret')
        ->and($project->credential_username)->toBe('repo_user')
        ->and($project->credential_password)->toBe('existing_repo_secret');
});

test('project can be deleted', function () {
    $project = Project::create([
        'name' => 'Archive Project',
        'workspace_path' => '/tmp/archive',
        'url' => 'https://github.com/example/archive',
    ]);

    $response = $this->delete(route('projects.destroy', $project));

    $response->assertRedirect(route('projects.index'));

    expect(Project::query()->exists())->toBeFalse();
});
