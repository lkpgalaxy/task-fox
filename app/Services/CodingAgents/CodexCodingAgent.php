<?php

namespace App\Services\CodingAgents;

use App\Contracts\CodingAgent;
use App\DataTransferObjects\CodingAgentResult;
use App\Models\AiRun;
use App\Models\InputSource;
use App\Models\Task;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Symfony\Component\Process\Process;

class CodexCodingAgent implements CodingAgent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private readonly array $context = [],
    ) {}

    public function run(Task $task, AiRun $run): CodingAgentResult
    {
        $prompt = "Implement task {$task->id}: {$task->title}\n".
            "Description:\n{$task->description}\n\n".
            "Acceptance criteria:\n".
            collect($task->acceptance_criteria ?? [])
                ->map(static fn (array $criterion): string => "- {$criterion['body']}")
                ->join("\n");

        $command = $this->buildCommand($task, $run, $prompt);
        $repositoryPath = (string) ($run->repository_path ?: config('automation.repository.path'));
        $workingDirectory = $repositoryPath !== '' ? $repositoryPath : null;

        $process = new Process($command, $workingDirectory);
        $process->setTimeout(null);
        $process->setEnv(array_merge(
            $this->context,
            [
                'TASK_ID' => (string) $task->id,
                'TASK_RUN_ID' => (string) $run->id,
                'TASK_REPOSITORY_PATH' => $repositoryPath,
            ],
        ));
        $process->run();

        $output = trim((string) $process->getOutput());
        $errorOutput = trim((string) $process->getErrorOutput());

        if (! $process->isSuccessful()) {
            $message = $errorOutput !== '' ? $errorOutput : 'Coding agent command failed.';

            return new CodingAgentResult(successful: false, messages: [], error: $message);
        }

        $messages = array_values(
            array_filter([
                'Coding agent command completed.',
                $output !== '' ? "Output: {$output}" : null,
                $errorOutput !== '' ? "STDERR: {$errorOutput}" : null,
            ], static fn (?string $message) => $message !== null),
        );

        return new CodingAgentResult(
            successful: true,
            messages: $messages,
        );
    }

    public function analyzeInputSource(InputSource $inputSource): CodingAgentResult
    {
        $inputPayload = $this->buildInputSourcePayload($inputSource);

        $prompt = <<<PROMPT
Analyze this input source and extract implementation tasks.

Return ONLY a valid JSON object with this exact shape:
{
  "tasks": [
    {
      "title": "Short actionable task title",
      "description": "Detailed task description preserving relevant context",
      "assignee_github_username": null,
      "priority": "medium",
      "deadline": null,
      "acceptance_criteria": [
        {"body": "Specific verifiable criterion", "checked": false}
      ],
      "questions": []
    }
  ]
}

Rules:
- Break the source into useful implementation subtasks, not one task per line.
- priority must be one of urgent, high, medium, low, or null.
- deadline must be YYYY-MM-DD or null.
- assignee_github_username must omit @, or be null.
- acceptance_criteria must contain concrete verification steps.
- questions must contain unresolved product or technical questions.
- Do not include markdown fences, commentary, or any text outside the JSON object.

{$inputPayload}
PROMPT;

        $repositoryPath = (string) config('automation.repository.path');
        $process = new Process($this->buildAnalyzeCommand($repositoryPath, $prompt), $repositoryPath !== '' ? $repositoryPath : null);
        $process->setTimeout(null);
        $process->setEnv($this->context);
        $process->run();

        $output = trim((string) $process->getOutput());
        $errorOutput = trim((string) $process->getErrorOutput());

        if (! $process->isSuccessful()) {
            $message = $errorOutput !== '' ? $errorOutput : 'Coding agent analysis command failed.';

            return new CodingAgentResult(successful: false, messages: [], error: $message);
        }

        try {
            $payload = $this->decodeJsonPayload($output);
        } catch (JsonException $exception) {
            return new CodingAgentResult(
                successful: false,
                messages: $output !== '' ? ["Output: {$output}"] : [],
                error: 'Coding agent returned invalid task JSON: '.$exception->getMessage(),
            );
        }

        return new CodingAgentResult(
            successful: true,
            messages: array_values(
                array_filter([
                    'Coding agent input analysis completed.',
                    $errorOutput !== '' ? "STDERR: {$errorOutput}" : null,
                ], static fn (?string $message) => $message !== null),
            ),
            payload: $payload,
        );
    }

    private function buildInputSourcePayload(InputSource $inputSource): string
    {
        if ($inputSource->hasStoredFile()) {
            $absolutePath = Storage::disk((string) $inputSource->file_disk)->path((string) $inputSource->file_path);
            $filename = $inputSource->original_filename ?: basename((string) $inputSource->file_path);
            $mimeType = $inputSource->mime_type ?: 'unknown';
            $fileSize = $inputSource->file_size !== null ? "{$inputSource->file_size} bytes" : 'unknown';

            return <<<PAYLOAD
Input source title: {$inputSource->title}
Input source kind: stored uploaded file
Original filename: {$filename}
MIME type: {$mimeType}
File size: {$fileSize}
Stored absolute file path: {$absolutePath}

Read and analyze the stored file at the path above. If the file is a PDF, analyze the PDF directly from disk; do not assume extracted text is available in the input source body.
PAYLOAD;
        }

        return <<<PAYLOAD
Input source title: {$inputSource->title}
Input source kind: unavailable

No stored file metadata is available for this input source.
PAYLOAD;
    }

    private function buildCommand(Task $task, AiRun $run, string $prompt): array
    {
        $repositoryPath = (string) ($run->repository_path ?: config('automation.repository.path'));
        $command = implode(' ', [
            'codex',
            'exec',
            '--cd',
            escapeshellarg($repositoryPath),
            escapeshellarg($prompt),
        ]);

        return ['sh', '-lc', $command];
    }

    private function buildAnalyzeCommand(string $repositoryPath, string $prompt): array
    {
        $command = implode(' ', [
            'codex',
            'exec',
            '--cd',
            escapeshellarg($repositoryPath),
            escapeshellarg($prompt),
        ]);

        return ['sh', '-lc', $command];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeJsonPayload(string $output): array
    {
        $json = trim($output);

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches) === 1) {
            $json = trim($matches[1]);
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
