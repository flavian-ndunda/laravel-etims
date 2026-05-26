<?php

declare(strict_types=1);

namespace Flavytech\Etims;

use Flavytech\Etims\Contracts\EtimsClientContract;
use Flavytech\Etims\Contracts\TenantResolverContract;
use Flavytech\Etims\Http\EtimsHttpClient;
use Flavytech\Etims\Services\EtimsClient;
use Flavytech\Etims\Services\EtimsManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * EtimsServiceProvider
 *
 * Bootstraps the entire SDK into the Laravel application.
 *
 * Responsibilities:
 *   1. Publishes config, migrations, and views
 *   2. Binds SDK contracts to concrete implementations
 *   3. Builds and registers the EtimsManager as a singleton
 *   4. Registers Artisan commands
 *   5. Loads migrations
 *
 * Auto-discovered by Laravel via composer.json extra.laravel configuration.
 * No manual registration needed — just `composer require flavytech/laravel-etims`.
 *
 * Architecture note on singletons: EtimsManager is registered as a singleton
 * because it holds the fake client reference during tests. EtimsHttpClient is
 * NOT a singleton — it's built fresh per-resolution so config changes (e.g.
 * switching tenants) are always picked up.
 */
class EtimsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config with the application's published config.
        // Application config always wins (mergeConfigFrom does NOT override).
        $this->mergeConfigFrom(__DIR__ . '/../config/etims.php', 'etims');

        $this->registerBindings();
        $this->registerManager();
    }

    public function boot(): void
    {
        $this->registerPublishables();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerCommands();
    }

    // =========================================================================
    // Registration
    // =========================================================================

    private function registerBindings(): void
    {
        // Bind the HTTP client — transient (new instance per resolution)
        // so tenant credentials are always fresh
        $this->app->bind(EtimsHttpClient::class, function ($app) {
            $config = $app['config']['etims'];

            // In multi-tenant mode, credentials come from the tenant resolver
            $credentials = $this->resolveCredentials($app, $config);

            return new EtimsHttpClient(
                credentials: $credentials,
                httpConfig:  $config['http'] ?? [],
                cacheConfig: $config['cache'] ?? [],
                loggingConfig: $config['logging'] ?? [],
                mode:        $credentials['mode'] ?? $config['mode'] ?? 'sandbox',
                endpoints:   $config['endpoints'] ?? [],
            );
        });

        // Bind the concrete client to the contract
        $this->app->bind(EtimsClientContract::class, EtimsClient::class);
    }

    private function registerManager(): void
    {
        // Singleton so fake() state is preserved across the request lifecycle
        $this->app->singleton(EtimsManager::class, function ($app) {
            $config = $app['config']['etims'];

            return new EtimsManager(
                client:         $app->make(EtimsClientContract::class),
                events:         $app->make(Dispatcher::class),
                config:         $config,
                tenantResolver: $this->resolveTenantResolver($app, $config),
            );
        });
    }

    // =========================================================================
    // Boot
    // =========================================================================

    private function registerPublishables(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__ . '/../config/etims.php' => config_path('etims.php'),
        ], 'etims-config');

        // Migrations
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'etims-migrations');

        // Publish all at once
        $this->publishes([
            __DIR__ . '/../config/etims.php'    => config_path('etims.php'),
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'etims');
    }

    private function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        // Phase 2: Register Artisan commands here
        // $this->commands([
        //     Commands\RetryFailedInvoicesCommand::class,
        //     Commands\EtimsHealthCheckCommand::class,
        // ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Resolve credentials from config or tenant resolver.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveCredentials($app, array $config): array
    {
        if (($config['multi_tenancy']['enabled'] ?? false)) {
            $resolverClass = $config['multi_tenancy']['tenant_resolver'] ?? null;
            if ($resolverClass && $app->bound(TenantResolverContract::class)) {
                return $app->make(TenantResolverContract::class)->resolve();
            }
        }

        return array_merge($config['credentials'] ?? [], ['mode' => $config['mode'] ?? 'sandbox']);
    }

    private function resolveTenantResolver($app, array $config): ?TenantResolverContract
    {
        if (!($config['multi_tenancy']['enabled'] ?? false)) {
            return null;
        }

        if ($app->bound(TenantResolverContract::class)) {
            return $app->make(TenantResolverContract::class);
        }

        return null;
    }
}
