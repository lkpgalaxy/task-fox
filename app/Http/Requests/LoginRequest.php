<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function authenticate(): void
    {
        $email = $this->string('email')->lower()->toString();
        $user = User::query()->where('email', $email)->first();

        if ($user?->isDisabled()) {
            throw ValidationException::withMessages([
                'email' => 'This account has been disabled.',
            ]);
        }

        if (! Auth::attempt([
            'email' => $email,
            'password' => $this->string('password')->toString(),
        ], $this->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }
    }
}
