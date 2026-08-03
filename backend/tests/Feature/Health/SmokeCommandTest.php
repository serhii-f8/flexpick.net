<?php

namespace Tests\Feature\Health;

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
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
            ->expectsOutputToContain('audit scanners provisioned')
            ->assertSuccessful();
    }

    public function test_fails_when_a_scanner_binary_is_missing(): void
    {
        config()->set('audit.scanners.semgrep.bin', '/nonexistent/semgrep');

        $this->artisan('app:smoke')->assertFailed();
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

    public function test_mail_transport_assertion_is_enforced_on_staging(): void
    {
        config()->set('app.env', 'staging');
        config()->set('mail.default', 'log');

        $this->artisan('app:smoke')
            ->expectsOutputToContain('mail transport')
            ->assertFailed();
    }

    public function test_mail_transport_assertion_is_skipped_locally(): void
    {
        config()->set('app.env', 'local');
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

    public function test_horizon_assertion_reflects_supervisor_state_in_production(): void
    {
        config()->set('app.env', 'production');

        $noSupervisors = Mockery::mock(MasterSupervisorRepository::class);
        $noSupervisors->shouldReceive('all')->andReturn([]);
        $this->app->instance(MasterSupervisorRepository::class, $noSupervisors);

        // Other production-gated assertions (config/routes caching, mail
        // transport) will also fail here since the testing environment never
        // has those warmed - that's expected and doesn't weaken this check,
        // since we pin the exact PASS/FAIL line for the horizon assertion.
        $this->artisan('app:smoke')
            ->expectsOutputToContain('FAIL  horizon worker on the audit queue')
            ->assertFailed();

        $runningSupervisors = Mockery::mock(MasterSupervisorRepository::class);
        $runningSupervisors->shouldReceive('all')->andReturn(['flexpick-supervisor']);
        $this->app->instance(MasterSupervisorRepository::class, $runningSupervisors);

        $this->artisan('app:smoke')
            ->expectsOutputToContain('PASS  horizon worker on the audit queue')
            ->doesntExpectOutputToContain('FAIL  horizon worker on the audit queue')
            ->run();
    }

    public function test_vite_manifest_assertion_passes_in_production_when_manifest_exists(): void
    {
        config()->set('app.env', 'production');

        // backend/public/build/manifest.json exists in this working tree, so
        // this exercises the genuine file_exists() + /pricing check rather
        // than the `! inProduction()` shortcut the other tests rely on.
        // FeatureTest::setUp() calls withoutVite(), which only fakes Blade's
        // @vite directive rendering - it does not touch this file_exists()
        // check or short-circuit the underlying assertion.
        $this->artisan('app:smoke')
            ->expectsOutputToContain('PASS  vite manifest present and a public page renders')
            ->doesntExpectOutputToContain('FAIL  vite manifest present and a public page renders')
            ->run();
    }
}
