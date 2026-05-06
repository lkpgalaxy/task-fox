<?php

namespace App\Services\Automation;

use App\Contracts\ExternalTaskProvider;
use App\Services\ExternalTaskProviders\NullExternalTaskProvider;

class ExternalTaskProviderFactory
{
    /**
     * @return array<string, array{label: string, class: string}>
     */
    public function providers(): array
    {
        $providers = config('automation.external_task_providers', []);

        if (! is_array($providers)) {
            return [];
        }

        return collect($providers)
            ->filter(fn (mixed $config): bool => is_array($config))
            ->map(fn (array $config): array => [
                'label' => is_string($config['label'] ?? null) ? $config['label'] : '',
                'class' => is_string($config['class'] ?? null) ? $config['class'] : '',
            ])
            ->filter(fn (array $config): bool => $config['label'] !== '' && $config['class'] !== '')
            ->all();
    }

    public function legacyConfiguredProvider(): ?string
    {
        $provider = config('automation.external_task_provider');

        return is_string($provider) && trim($provider) !== ''
            ? trim($provider)
            : null;
    }

    public function supportsProvider(?string $provider): bool
    {
        return $provider !== null && array_key_exists($provider, $this->providers());
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect($this->providers())
            ->mapWithKeys(fn (array $provider, string $key): array => [$key => $provider['label']])
            ->all();
    }

    public function make(?string $provider): ExternalTaskProvider
    {
        $provider = is_string($provider) ? trim($provider) : null;

        if ($provider === null || $provider === '') {
            return app(NullExternalTaskProvider::class);
        }

        $configuredProviders = $this->providers();

        if (array_key_exists($provider, $configuredProviders)) {
            return app($configuredProviders[$provider]['class']);
        }

        if (class_exists($provider) && is_subclass_of($provider, ExternalTaskProvider::class)) {
            return app($provider);
        }

        return app(ExternalTaskProvider::class);
    }
}
