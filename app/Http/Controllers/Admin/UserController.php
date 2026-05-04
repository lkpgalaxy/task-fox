<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('users/Index', [
            'users' => User::query()
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => $this->serializeUser($user)),
            'roles' => [User::ROLE_ADMIN, User::ROLE_USER],
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $password = (string) ($data['password'] ?? '');

        User::create([
            ...Arr::except($data, ['password', 'password_confirmation']),
            'password' => Hash::make($password !== '' ? $password : 'password'),
        ]);

        return redirect()
            ->route('users.index')
            ->with('status', 'User created.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if ($user->isAdmin() && $data['role'] !== User::ROLE_ADMIN && $this->activeAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'role' => 'The last active admin cannot be demoted.',
            ]);
        }

        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make((string) $data['password']);
        }

        $user->update(Arr::except($data, ['password_confirmation']));

        return redirect()
            ->route('users.index')
            ->with('status', 'User updated.');
    }

    public function disable(User $user): RedirectResponse
    {
        if (request()->user()?->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot disable your own account.',
            ]);
        }

        if ($user->isAdmin() && ! $user->isDisabled() && $this->activeAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'user' => 'The last active admin cannot be disabled.',
            ]);
        }

        $user->update(['disabled_at' => now()]);

        return redirect()
            ->route('users.index')
            ->with('status', 'User disabled.');
    }

    public function enable(User $user): RedirectResponse
    {
        $user->update(['disabled_at' => null]);

        return redirect()
            ->route('users.index')
            ->with('status', 'User enabled.');
    }

    private function activeAdminCount(): int
    {
        return (int) User::query()
            ->where('role', User::ROLE_ADMIN)
            ->whereNull('disabled_at')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'github_username' => $user->github_username,
            'role' => $user->role,
            'disabled_at' => $user->disabled_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
