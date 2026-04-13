<?php

namespace Ahmed3bead\TenantAudit\Tests;

use Ahmed3bead\TenantAudit\TenantAuditServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            TenantAuditServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Sensible test defaults — override per-test via config()->set()
        $app['config']->set('tenant-audit.capture_ip', false);
        $app['config']->set('tenant-audit.capture_user_agent', false);
        $app['config']->set('tenant-audit.queue', false);
    }

    protected function setUpDatabase(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
