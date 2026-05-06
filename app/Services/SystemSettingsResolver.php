<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Automation\AgentDriverFactory;
use App\Services\Automation\ExternalTaskProviderFactory;

class SystemSettingsResolver
{
    public const REASONING_EFFORTS = ['low', 'medium', 'high', 'xhigh'];

    public const DISABLED_EXTERNAL_TASK_PROVIDER = '__disabled__';

    public function settings(): SystemSetting
    {
        $settings = SystemSetting::query()->first();

        if ($settings instanceof SystemSetting) {
            return $settings;
        }

        $settings = new SystemSetting;
        $settings->save();

        return $settings;
    }

    public function analyzeSourceModel(): ?string
    {
        return $this->resolveModel('analyze_source_model');
    }

    public function analyzeSourceReasoningEffort(): ?string
    {
        return $this->resolveModel('analyze_source_reasoning_effort');
    }

    public function planModel(): ?string
    {
        return $this->resolveModel('plan_model');
    }

    public function planReasoningEffort(): ?string
    {
        return $this->resolveModel('plan_reasoning_effort');
    }

    public function implementModel(): ?string
    {
        return $this->resolveModel('implement_model');
    }

    public function implementReasoningEffort(): ?string
    {
        return $this->resolveModel('implement_reasoning_effort');
    }

    public function reviewModel(): ?string
    {
        return $this->resolveModel('review_model');
    }

    public function reviewReasoningEffort(): ?string
    {
        return $this->resolveModel('review_reasoning_effort');
    }

    public function commitMessageModel(): ?string
    {
        return $this->resolveModel('commit_message_model');
    }

    public function commitMessageReasoningEffort(): ?string
    {
        return $this->resolveModel('commit_message_reasoning_effort');
    }

    public function retryLimit(): int
    {
        $value = $this->settings()->retry_limit;

        return is_int($value) ? $value : (int) config('automation.agent.retry_limit', 3);
    }

    public function sharedAgentDriver(): ?string
    {
        return $this->resolveModel('agent_driver');
    }

    public function sharedCodingAgentDriver(): ?string
    {
        return $this->resolveModel('coding_agent_driver');
    }

    public function sharedExternalTaskProvider(): ?string
    {
        return $this->resolveModel('external_task_provider');
    }

    public function effectiveAgentDriver(?User $user = null): string
    {
        $override = $this->nullableUserSetting($user?->automation_agent_driver);
        if ($override !== null) {
            return $override;
        }

        $shared = $this->sharedAgentDriver();
        if ($shared !== null) {
            return $shared;
        }

        return app(AgentDriverFactory::class)->defaultAgentDriver();
    }

    public function effectiveCodingAgentDriver(?User $user = null): string
    {
        $override = $this->nullableUserSetting($user?->automation_coding_agent_driver);
        if ($override !== null) {
            return $override;
        }

        $shared = $this->sharedCodingAgentDriver();
        if ($shared !== null) {
            return $shared;
        }

        return app(AgentDriverFactory::class)->defaultCodingAgentDriver();
    }

    public function effectiveExternalTaskProvider(?User $user = null): ?string
    {
        $override = $this->nullableUserSetting($user?->automation_external_task_provider);
        if ($override !== null) {
            return $override === self::DISABLED_EXTERNAL_TASK_PROVIDER ? null : $override;
        }

        $shared = $this->sharedExternalTaskProvider();
        if ($shared !== null) {
            return $shared;
        }

        return app(ExternalTaskProviderFactory::class)->legacyConfiguredProvider();
    }

    /**
     * @return array{
     *     analyze_source_model: string|null,
     *     analyze_source_reasoning_effort: string|null,
     *     plan_model: string|null,
     *     plan_reasoning_effort: string|null,
     *     implement_model: string|null,
     *     implement_reasoning_effort: string|null,
     *     review_model: string|null,
     *     review_reasoning_effort: string|null,
     *     commit_message_model: string|null,
     *     commit_message_reasoning_effort: string|null,
     *     retry_limit: int,
     *     agent_driver: string|null,
     *     coding_agent_driver: string|null,
     *     external_task_provider: string|null
     * }
     */
    public function snapshot(): array
    {
        return [
            'analyze_source_model' => $this->analyzeSourceModel(),
            'analyze_source_reasoning_effort' => $this->analyzeSourceReasoningEffort(),
            'plan_model' => $this->planModel(),
            'plan_reasoning_effort' => $this->planReasoningEffort(),
            'implement_model' => $this->implementModel(),
            'implement_reasoning_effort' => $this->implementReasoningEffort(),
            'review_model' => $this->reviewModel(),
            'review_reasoning_effort' => $this->reviewReasoningEffort(),
            'commit_message_model' => $this->commitMessageModel(),
            'commit_message_reasoning_effort' => $this->commitMessageReasoningEffort(),
            'retry_limit' => $this->retryLimit(),
            'agent_driver' => $this->sharedAgentDriver(),
            'coding_agent_driver' => $this->sharedCodingAgentDriver(),
            'external_task_provider' => $this->sharedExternalTaskProvider(),
        ];
    }

    /**
     * @return array{
     *     automation_agent_driver: string|null,
     *     automation_coding_agent_driver: string|null,
     *     automation_external_task_provider: string|null
     * }
     */
    public function userSnapshot(User $user): array
    {
        return [
            'automation_agent_driver' => $this->nullableUserSetting($user->automation_agent_driver),
            'automation_coding_agent_driver' => $this->nullableUserSetting($user->automation_coding_agent_driver),
            'automation_external_task_provider' => $this->nullableUserSetting($user->automation_external_task_provider),
        ];
    }

    private function nullableUserSetting(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resolveModel(string $column): ?string
    {
        $value = $this->settings()->getAttribute($column);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
