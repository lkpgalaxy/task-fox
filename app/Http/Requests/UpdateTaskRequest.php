<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'priority' => ['nullable', Rule::in([Task::PRIORITY_LOW, Task::PRIORITY_MEDIUM, Task::PRIORITY_HIGH, Task::PRIORITY_URGENT])],
            'deadline' => ['nullable', 'date'],
            'assignee_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'source_input_id' => ['nullable', 'integer', 'exists:input_sources,id'],
            'acceptance_criteria' => ['required', 'array'],
            'acceptance_criteria.*.body' => ['required', 'string', 'max:1500'],
            'acceptance_criteria.*.checked' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, array{body: string, checked: bool}>
     */
    public function acceptanceCriteria(): array
    {
        return $this->validated('acceptance_criteria', []);
    }
}
