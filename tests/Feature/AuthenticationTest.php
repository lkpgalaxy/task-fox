<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('guests are redirected to login for protected pages', function () {
    $this->get(route('tasks.index'))->assertRedirect(route('login'));
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});

test('valid login authenticates and invalid login fails', function () {
    $user = User::factory()->create([
        'email' => 'valid@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('tasks.index', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('disabled users cannot log in', function () {
    $user = User::factory()->disabled()->create([
        'email' => 'disabled@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('active sessions for disabled users are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    $user->update(['disabled_at' => now()]);

    $this->get(route('tasks.index'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
