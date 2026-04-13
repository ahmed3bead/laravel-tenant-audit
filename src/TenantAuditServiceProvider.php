<?php

namespace Ahmed3bead\TenantAudit;

use Ahmed3bead\TenantAudit\Contracts\TenantResolverContract;
use Ahmed3bead\TenantAudit\Facades\TenantAudit;
use Illuminate\Support\ServiceProvider;

class TenantAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/tenant-audit.php',
            'tenant-audit'
        );

        $this->app->singleton(TenantAuditManager::class, function ($app) {
            return new TenantAuditManager($app);
        });

        $this->app->alias(TenantAuditManager::class, 'tenant-audit');

        $this->bindTenantResolver();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishConfig();
            $this->publishMigrations();
            $this->publishStubs();
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Bind the configured tenant resolver to the contract so user code can
     * type-hint TenantResolverContract and receive the correct implementation.
     */
    protected function bindTenantResolver(): void
    {
        $resolverClass = config('tenant-audit.tenant_resolver');

        if (! $resolverClass) {
            return;
        }

        $this->app->bind(TenantResolverContract::class, $resolverClass);
    }

    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/tenant-audit.php' => config_path('tenant-audit.php'),
        ], 'tenant-audit-config');
    }

    protected function publishMigrations(): void
    {
        // Migrations are published rather than auto-loaded so the host app
        // controls when and how they run (important for multi-tenancy setups
        // where migrations may target a different connection or schema).
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'tenant-audit-migrations');
    }

    protected function publishStubs(): void
    {
        $this->publishes([
            __DIR__.'/../stubs/TenantResolver.stub' => app_path('Services/TenantResolver.php'),
        ], 'tenant-audit-stubs');
    }
}
