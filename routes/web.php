<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InputSourceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskRunLogController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'enabled'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', HomeController::class)->name('home');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/automation', [ProfileController::class, 'updateAutomationSettings'])->name('profile.automation.update');
    Route::patch('/profile/automation/preferences', [ProfileController::class, 'updateAutomationPreferences'])->name('profile.automation.preferences.update');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::post('/tasks/{task}/submit-for-approval', [TaskController::class, 'submitForApproval'])->name('tasks.submit-for-approval');
    Route::post('/tasks/{task}/approve', [TaskController::class, 'approve'])->name('tasks.approve');
    Route::post('/tasks/{task}/stop', [TaskController::class, 'stop'])->name('tasks.stop');
    Route::post('/tasks/{task}/reject', [TaskController::class, 'reject'])->name('tasks.reject');
    Route::post('/tasks/{task}/retry', [TaskController::class, 'retry'])->name('tasks.retry');
    Route::post('/tasks/{task}/rerun-workflow', [TaskController::class, 'rerunWorkflow'])->name('tasks.rerun-workflow');
    Route::post('/tasks/{task}/create-pr', [TaskController::class, 'createPullRequest'])->name('tasks.create-pr');
    Route::post('/tasks/{task}/refresh-pr', [TaskController::class, 'refreshPullRequest'])->name('tasks.refresh-pr');

    Route::get('/input-sources', [InputSourceController::class, 'index'])->name('input-sources.index');
    Route::post('/input/analyze', [InputSourceController::class, 'store'])->name('input-sources.store');
    Route::get('/input-sources/{inputSource}/preview', [InputSourceController::class, 'preview'])->name('input-sources.preview');
    Route::get('/logs', [TaskRunLogController::class, 'index'])->name('logs.index');

    Route::middleware('can:manage-users')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/disable', [UserController::class, 'disable'])->name('users.disable');
        Route::patch('/users/{user}/enable', [UserController::class, 'enable'])->name('users.enable');
    });
});
