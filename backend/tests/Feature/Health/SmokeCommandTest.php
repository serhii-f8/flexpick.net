<?php

namespace Tests\Feature\Health;

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Mockery;
use Tests\Feature\FeatureTest;

class SmokeCommandTest extends FeatureTest
{
    public function test_succeeds_when_the_application_is_healthy(): void
    {
        config()->set('app.env', 'testing');

        $this->artisan('app:smoke')->assertSuccessful();
    }

    public function test_reports_every_specified_assertion(): void
    {
        config()->set('app.env', 'testing');

        $this->artisan('app:smoke')
            ->expectsOutputToContain('liveness endpoint')
            ->expectsOutputToContain('readiness endpoint')
            ->expectsOutputToContain('database reachable')
            ->expectsOutputToContain('no pending migrations')
            ->expectsOutputToContain('configuration and routes cached')
            ->expectsOutputToContain('horizon worker on the audit queue')
            ->expectsOutputToContain('mail transport')
            ->expectsOutputToContain('vite manifest')
            ->assertSuccessful();
    }

    public function test_fails_when_migrations_are_pending(): void
    {
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('getRan')->andReturn([]);

        $migrator = Mockery::mock(Migrator::class);
        $migrator->shouldReceive('paths')->andReturn([]);
        $migrator->shouldReceive('getMigrationFiles')
            ->andReturn(['2026_01_01_000000_never_ran' => '/tmp/never_ran.php']);
        $migrator->shouldReceive('getRepository')->andReturn($repository);

        $this->app->instance('migrator', $migrator);

        $this->artisan('app:smoke')
            ->expectsOutputToContain('no pending migrations')
            ->assertFailed();
    }

    public function test_fails_when_mail_transport_is_log_in_production(): void
    {
        config()->set('app.env', 'production');
        config()->set('mail.default', 'log');

        $this->artisan('app:smoke')
            ->expectsOutputToContain('mail transport')
            ->assertFailed();
    }

    public function test_allows_log_mail_transport_outside_production(): void
    {
        config()->set('app.env', 'testing');
        config()->set('mail.default', 'log');

        $this->artisan('app:smoke')->assertSuccessful();
    }

    public function test_fails_in_production_when_configuration_is_not_cached(): void
    {
        config()->set('app.env', 'production');
        config()->set('mail.default', 'smtp');

        // The testing environment never has a cached config file, so asserting
        // production here proves the gate binds where it matters.
        $this->artisan('app:smoke')
            ->expectsOutputToContain('configuration and routes cached')
            ->assertFailed();
    }
}
