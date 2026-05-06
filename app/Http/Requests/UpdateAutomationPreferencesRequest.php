<?php

namespace App\Http\Requests;

use App\Services\Automation\AgentDriverFactory;
use App\Services\Automation\ExternalTaskProviderFactory;
use App\Services\SystemSettingsResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutomationPreferencesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'automation_agent_driver' => $this->normalize('automation_agent_driver'),
            'automation_coding_agent_driver' => $this->normalize('automation_coding_agent_driver'),
            'automation_external_task_provider' => $this->normalize('automation_external_task_provider'),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'automation_agent_driver' => ['nullable', 'string', Rule::in(array_keys(app(AgentDriverFactory::class)->agentDrivers()))],
            'automation_coding_agent_driver' => ['nullable', 'string', Rule::in(array_keys(app(AgentDriverFactory::class)->codingAgentDrivers()))],
            'automation_external_task_provider' => ['nullable', 'string', Rule::in([
                ...array_keys(app(ExternalTaskProviderFactory::class)->options()),
                SystemSettingsResolver::DISABLED_EXTERNAL_TASK_PROVIDER,
            ])],
        ];
    }

    private function normalize(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }
}
