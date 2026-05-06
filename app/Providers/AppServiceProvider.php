<?php

namespace App\Providers;

use App\Contracts\Agent;
use App\Contracts\CodingAgent;
use App\Contracts\ExternalTaskProvider;
use App\Contracts\PullRequestProvider;
use App\Contracts\TaskExtractor;
use App\Services\Automation\AgentDriverFactory;
use App\Services\Automation\ExternalTaskProviderFactory;
use App\Services\CodingAgents\CodexCodingAgent;
use App\Services\ExternalTaskProviders\NullExternalTaskProvider;
use App\Services\Extraction\AgentTaskExtractor;
use App\Services\PullRequests\GithubPullRequestProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $this->registerAuthorization();
        $this->registerDomainBindings();
    }

    protected function registerAuthorization(): void
    {
        Gate::define('manage-users', fn ($user): bool => $user->isAdmin() && ! $user->isDisabled());
    }

    protected function registerDomainBindings(): void
    {
        $this->app->bind(TaskExtractor::class, AgentTaskExtractor::class);
        $this->app->bind(Agent::class, CodexCodingAgent::class);
        $this->app->bind(CodingAgent::class, CodexCodingAgent::class);
        $this->app->singleton(AgentDriverFactory::class);
        $this->app->singleton(ExternalTaskProviderFactory::class);

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
