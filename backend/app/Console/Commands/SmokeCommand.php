<?php

namespace App\Console\Commands;

use App\Constants\AuditTier;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Post-deploy gate (spec §14.4, PR8). Read-only and safe to re-run against
 * any deployed environment: sends no email, runs no audit, writes nothing.
 * The full assertion set runs everywhere except `local` and `testing`.
 */
class SmokeCommand extends Command
{
    protected $signature = 'app:smoke';

    protected $description = 'Verify a freshly deployed release is serviceable';

    public function handle(): int
    {
        $failures = [];

        foreach ($this->assertions() as $label => $assertion) {
            try {
                $ok = $assertion();
            } catch (Throwable $e) {
                $ok = false;
                $label .= ' ('.$e->getMessage().')';
            }

            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').'  '.$label);

            if (! $ok) {
                $failures[] = $label;
            }
        }

        if ($failures !== []) {
            $this->newLine();
            $this->error(count($failures).' smoke assertion(s) failed. Roll back this release.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All smoke assertions passed.');

        return self::SUCCESS;
    }

    /**
     * The seven assertions of spec §7, plus a direct database probe so a
     * connection failure is named as such rather than surfacing as a
     * confusing readiness 503.
     *
     * @return array<string, callable(): bool>
     */
    private function assertions(): array
    {
        return [
            'liveness endpoint returns 200' => fn (): bool => $this->getReturnsOk('/up'),
            'readiness endpoint returns 200' => fn (): bool => $this->getReturnsOk('/health/ready'),
            'database reachable' => fn (): bool => $this->databaseIsReachable(),
            'no pending migrations' => fn (): bool => $this->migrationsAreCurrent(),
            'configuration and routes cached' => fn (): bool => $this->cachesAreWarm(),
            'horizon worker on the audit queue' => fn (): bool => $this->horizonIsRunning(),
            'mail transport is a real transport' => fn (): bool => $this->mailTransportIsUsable(),
            'vite manifest present and a public page renders' => fn (): bool => $this->publicPageRenders(),
            'audit scanners provisioned' => fn (): bool => $this->auditScannersProvisioned(),
        ];
    }

    /**
     * Deployed environments — anything that is not a developer machine or the
     * test runner — get the full assertion set. Staging must be gated exactly
     * as strictly as production, or it certifies releases production rejects.
     */
    private function isDeployedEnvironment(): bool
    {
        return ! in_array(config('app.env'), ['local', 'testing'], true);
    }

    private function getReturnsOk(string $uri): bool
    {
        $response = app()->handle(Request::create($uri, 'GET'));

        return $response->getStatusCode() === 200;
    }

    private function databaseIsReachable(): bool
    {
        DB::select('select 1');

        return true;
    }

    private function migrationsAreCurrent(): bool
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $paths = array_merge([database_path('migrations')], $migrator->paths());
        $files = array_keys($migrator->getMigrationFiles($paths));
        $ran = $migrator->getRepository()->getRan();

        return array_diff($files, $ran) === [];
    }

    private function cachesAreWarm(): bool
    {
        if (! $this->isDeployedEnvironment()) {
            return true;
        }

        return file_exists(app()->getCachedConfigPath())
            && file_exists(app()->getCachedRoutesPath());
    }

    private function horizonIsRunning(): bool
    {
        if (! $this->isDeployedEnvironment()) {
            return true;
        }

        $masters = app(MasterSupervisorRepository::class)->all();

        return $masters !== [];
    }

    private function mailTransportIsUsable(): bool
    {
        if (! $this->isDeployedEnvironment()) {
            return true;
        }

        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * Guards the documented "Vite manifest not found" 500 that takes out any
     * page rendered through the shared app layout. Probes /terms-of-service
     * rather than /pricing because /pricing now requires auth — this is the
     * class of breakage a smoke gate exists to catch, on a page that stays
     * genuinely public.
     */
    private function publicPageRenders(): bool
    {
        if (! $this->isDeployedEnvironment()) {
            return true;
        }

        return file_exists(public_path('build/manifest.json'))
            && $this->getReturnsOk('/terms-of-service');
    }

    /**
     * Provisioning drift is otherwise invisible: every run degrades, reports
     * quietly thin out, and the exit code stays 0 (spec §9.1). Checked
     * everywhere, not just in production — the dev container provisions
     * these binaries too (Task 14).
     */
    private function auditScannersProvisioned(): bool
    {
        $profile = app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC);

        foreach ($profile->scanners as $name) {
            $scanner = app('audit.scanner.'.$name);

            if (! $scanner->isAvailable()) {
                $this->line("  scanner [{$name}] is not available at its configured path");

                return false;
            }
        }

        return true;
    }
}
