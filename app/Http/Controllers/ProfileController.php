<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAutomationPreferencesRequest;
use App\Http\Requests\UpdateAutomationSettingsRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Services\Automation\AgentDriverFactory;
use App\Services\Automation\ExternalTaskProviderFactory;
use App\Services\SystemSettingsResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(
        Request $request,
        SystemSettingsResolver $settingsResolver,
        AgentDriverFactory $agentDriverFactory,
        ExternalTaskProviderFactory $externalTaskProviderFactory,
    ): Response {
        $user = $request->user();

        return Inertia::render('profile/Edit', [
            'automationSettings' => $user?->isAdmin()
                ? $settingsResolver->snapshot()
                : null,
            'automationPreferences' => $user ? $settingsResolver->userSnapshot($user) : null,
            'automationOptions' => [
                'agent_drivers' => $agentDriverFactory->agentDrivers(),
                'coding_agent_drivers' => $agentDriverFactory->codingAgentDrivers(),
                'external_task_providers' => $externalTaskProviderFactory->options(),
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (trim((string) ($data['github_token'] ?? '')) === '') {
            unset($data['github_token']);
        }

        $request->user()?->update($data);

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Profile updated.');
    }

    public function updateAutomationSettings(UpdateAutomationSettingsRequest $request, SystemSettingsResolver $settingsResolver): RedirectResponse
    {
        $settings = $settingsResolver->settings();
        $settings->fill($request->validated());
        $settings->save();

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Automation models updated.');
    }

    public function updateAutomationPreferences(UpdateAutomationPreferencesRequest $request): RedirectResponse
    {
        $request->user()?->update($request->validated());

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Automation preferences updated.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()?->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Password updated.');
    }
}
