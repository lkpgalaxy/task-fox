<?php

namespace App\Services;

use App\Models\SystemSetting;

class SystemSettingsResolver
{
    public const REASONING_EFFORTS = ['low', 'medium', 'high', 'xhigh'];

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
     *     retry_limit: int
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
        ];
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
