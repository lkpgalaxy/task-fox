<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreInputSourceRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:255'],
            'source_type' => ['required', 'string', 'in:text,file'],
            'text' => ['required_if:source_type,text', 'nullable', 'string', 'max:120000'],
            'upload' => [
                'required_if:source_type,file',
                'nullable',
                File::types(['txt', 'md', 'pdf'])->max(10 * 1024),
                'extensions:txt,md,pdf',
            ],
        ];
    }
}
