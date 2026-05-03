<?php

namespace App\Http\Controllers;

use App\Models\AiRunLog;
use Inertia\Inertia;

class AiRunLogController extends Controller
{
    public function index()
    {
        return Inertia::render('tasks/Logs', [
            'logs' => AiRunLog::query()
                ->with([
                    'aiRun.task:id,title,status',
                    'inputSource:id,title,analysis_status',
                ])
                ->latest('created_at')
                ->limit(400)
                ->get()
                ->map(function (AiRunLog $log): array {
                    return [
                        'id' => $log->id,
                        'level' => $log->level,
                        'message' => $log->message,
                        'context' => $log->context,
                        'created_at' => $log->created_at?->toIso8601String(),
                        'task_id' => optional($log->aiRun)->task_id,
                        'task_title' => optional($log->aiRun?->task)->title,
                        'run_id' => $log->ai_run_id,
                        'run_status' => optional($log->aiRun)->status,
                        'input_source_id' => $log->input_source_id,
                        'input_source_title' => $log->inputSource?->title,
                    ];
                }),
        ]);
    }
}
