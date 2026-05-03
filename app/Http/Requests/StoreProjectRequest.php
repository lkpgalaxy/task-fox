<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'workspace_path' => ['required', 'string', 'max:1024'],
            'url' => ['nullable', 'string', 'max:2048'],
            'database_name' => ['nullable', 'string', 'max:255'],
            'database_username' => ['nullable', 'string', 'max:255'],
            'database_password' => ['nullable', 'string'],
            'credential_username' => ['nullable', 'string', 'max:255'],
            'credential_password' => ['nullable', 'string'],
            'base_branch' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $credentialUsername = $this->input('credential_username');
            $credentialPassword = $this->input('credential_password');

            if ((($credentialUsername === null || $credentialUsername === '') !== ($credentialPassword === null || $credentialPassword === ''))) {
                $validator->errors()->add('credential_password', 'Repository credentials require both username and password.');
            }
        });
    }
}
