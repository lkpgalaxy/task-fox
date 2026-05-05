<?php

namespace App\Http\Requests;

use App\Services\SystemSettingsResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutomationSettingsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'analyze_source_model' => $this->normalizeModelInput('analyze_source_model'),
            'analyze_source_reasoning_effort' => $this->normalizeModelInput('analyze_source_reasoning_effort'),
            'plan_model' => $this->normalizeModelInput('plan_model'),
            'plan_reasoning_effort' => $this->normalizeModelInput('plan_reasoning_effort'),
            'implement_model' => $this->normalizeModelInput('implement_model'),
            'implement_reasoning_effort' => $this->normalizeModelInput('implement_reasoning_effort'),
            'review_model' => $this->normalizeModelInput('review_model'),
            'review_reasoning_effort' => $this->normalizeModelInput('review_reasoning_effort'),
            'commit_message_model' => $this->normalizeModelInput('commit_message_model'),
            'commit_message_reasoning_effort' => $this->normalizeModelInput('commit_message_reasoning_effort'),
            'retry_limit' => $this->normalizeRetryLimitInput(),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'analyze_source_model' => ['nullable', 'string', 'max:255'],
            'analyze_source_reasoning_effort' => ['nullable', 'string', Rule::in(SystemSettingsResolver::REASONING_EFFORTS)],
            'plan_model' => ['nullable', 'string', 'max:255'],
            'plan_reasoning_effort' => ['nullable', 'string', Rule::in(SystemSettingsResolver::REASONING_EFFORTS)],
            'implement_model' => ['nullable', 'string', 'max:255'],
            'implement_reasoning_effort' => ['nullable', 'string', Rule::in(SystemSettingsResolver::REASONING_EFFORTS)],
            'review_model' => ['nullable', 'string', 'max:255'],
            'review_reasoning_effort' => ['nullable', 'string', Rule::in(SystemSettingsResolver::REASONING_EFFORTS)],
            'commit_message_model' => ['nullable', 'string', 'max:255'],
            'commit_message_reasoning_effort' => ['nullable', 'string', Rule::in(SystemSettingsResolver::REASONING_EFFORTS)],
            'retry_limit' => ['nullable', 'integer', 'min:-1', 'not_in:0'],
        ];
    }

    private function normalizeModelInput(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }

    private function normalizeRetryLimitInput(): int|string|null
    {
        $value = trim((string) $this->input('retry_limit'));

        if ($value === '') {
            return null;
        }

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value;
    }
}
