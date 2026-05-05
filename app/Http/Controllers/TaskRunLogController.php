<?php

namespace App\Http\Controllers;

use App\Models\TaskRunLog;
use Inertia\Inertia;
use Inertia\Response;

class TaskRunLogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('tasks/Logs', [
            'logs' => fn () => TaskRunLog::query()
                ->with([
                    'taskRun.task:id,title,status',
                    'inputSource:id,title,analysis_status',
                ])
                ->latest('created_at')
                ->limit(400)
                ->get()
                ->map(function (TaskRunLog $log): array {
                    return [
                        'id' => $log->id,
                        'level' => $log->level,
                        'message' => $log->message,
                        'context' => $log->context,
                        'created_at' => $log->created_at?->toIso8601String(),
                        'task_id' => optional($log->taskRun)->task_id,
                        'task_title' => optional($log->taskRun?->task)->title,
                        'run_id' => $log->task_run_id,
                        'run_status' => optional($log->taskRun)->status,
                        'input_source_id' => $log->input_source_id,
                        'input_source_title' => $log->inputSource?->title,
                    ];
                }),
        ]);
    }
}
