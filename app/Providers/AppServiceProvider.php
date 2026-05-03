<?php

namespace App\Providers;

use App\Contracts\CodingAgent;
use App\Contracts\ExternalTaskProvider;
use App\Contracts\PullRequestProvider;
use App\Contracts\TaskExtractor;
use App\Services\CodingAgents\CodexCodingAgent;
use App\Services\ExternalTaskProviders\NullExternalTaskProvider;
use App\Services\Extraction\AgentTaskExtractor;
use App\Services\PullRequests\GithubPullRequestProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerDomainBindings();
    }

    protected function registerDomainBindings(): void
    {
        $this->app->bind(TaskExtractor::class, AgentTaskExtractor::class);
        $this->app->bind(CodingAgent::class, function (): CodingAgent {
            return match ((string) config('automation.coding_agent.driver', 'codex')) {
                'codex' => new CodexCodingAgent([
                    'REPOSITORY_PATH' => (string) config('automation.repository.path'),
                ]),
                default => new CodexCodingAgent([
                    'REPOSITORY_PATH' => (string) config('automation.repository.path'),
                ]),
            };
        });

        $pullRequestProvider = config('automation.pull_request_provider');
        if (
            is_string($pullRequestProvider) &&
            class_exists($pullRequestProvider) &&
            is_subclass_of($pullRequestProvider, PullRequestProvider::class)
        ) {
            $this->app->bind(PullRequestProvider::class, $pullRequestProvider);
        } else {
            $this->app->bind(PullRequestProvider::class, GithubPullRequestProvider::class);
        }

        $provider = Arr::get(config('automation'), 'external_task_provider');

        if (
            is_string($provider) &&
            $provider !== '' &&
            class_exists($provider) &&
            is_subclass_of($provider, ExternalTaskProvider::class)
        ) {
            $this->app->bind(ExternalTaskProvider::class, $provider);

            return;
        }

        $this->app->bind(ExternalTaskProvider::class, NullExternalTaskProvider::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
