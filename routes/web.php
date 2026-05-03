<?php

use App\Http\Controllers\AiRunLogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InputSourceController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
Route::post('/tasks/{task}/approve', [TaskController::class, 'approve'])->name('tasks.approve');
Route::post('/tasks/{task}/reject', [TaskController::class, 'reject'])->name('tasks.reject');
Route::post('/tasks/{task}/refresh-pr', [TaskController::class, 'refreshPullRequest'])->name('tasks.refresh-pr');

Route::get('/input-sources', [InputSourceController::class, 'index'])->name('input-sources.index');
Route::post('/input/analyze', [InputSourceController::class, 'store'])->name('input-sources.store');
Route::get('/input-sources/{inputSource}/preview', [InputSourceController::class, 'preview'])->name('input-sources.preview');
Route::get('/logs', [AiRunLogController::class, 'index'])->name('logs.index');
