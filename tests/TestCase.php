<?php

declare(strict_types=1);

namespace Flavytech\Etims\Tests;

use Flavytech\Etims\EtimsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base TestCase for all SDK tests.
 *
 * Uses Orchestra Testbench to provide a full Laravel application
 * context without requiring an actual Laravel app installation.
 *
 * Sets up:
 *   - SDK ServiceProvider
 *   - In-memory SQLite database
 *   - SDK migrations
 *   - Minimal eTIMS config for testing
 */
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
            EtimsServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Etims' => \Flavytech\Etims\Facades\Etims::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Use SQLite in-memory for tests — no MySQL required
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Minimal eTIMS config for tests — sandbox mode, fake credentials
        $app['config']->set('etims.mode', 'sandbox');
        $app['config']->set('etims.credentials', [
            'pin'           => 'P000000000X',
            'branch_id'     => '00',
            'device_serial' => 'TEST-DEVICE-001',
            'secret'        => 'test-secret-key',
        ]);
        $app['config']->set('etims.endpoints.sandbox', 'https://etims-sandbox.kra.go.ke');
        $app['config']->set('etims.queue.connection', 'sync'); // run jobs synchronously in tests
        $app['config']->set('etims.idempotency.enabled', true);
        $app['config']->set('etims.logging.log_requests', false);
        $app['config']->set('etims.logging.log_responses', false);
    }

    private function setUpDatabase(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
