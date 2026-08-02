<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Post-deploy gate (spec §14.4, PR8). Read-only and safe to re-run against
 * production: sends no email, runs no audit, writes nothing.
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
        ];
    }

    private function inProduction(): bool
    {
        return config('app.env') === 'production';
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
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');

        $paths = array_merge([database_path('migrations')], $migrator->paths());
        $files = array_keys($migrator->getMigrationFiles($paths));
        $ran = $migrator->getRepository()->getRan();

        return array_diff($files, $ran) === [];
    }

    private function cachesAreWarm(): bool
    {
        if (! $this->inProduction()) {
            return true;
        }

        return file_exists(app()->getCachedConfigPath())
            && file_exists(app()->getCachedRoutesPath());
    }

    private function horizonIsRunning(): bool
    {
        if (! $this->inProduction()) {
            return true;
        }

        $masters = app(MasterSupervisorRepository::class)->all();

        return $masters !== [];
    }

    private function mailTransportIsUsable(): bool
    {
        if (! $this->inProduction()) {
            return true;
        }

        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * Guards the documented "Vite manifest not found" 500 that takes out
     * /pricing — precisely the class of breakage a smoke gate exists to catch.
     */
    private function publicPageRenders(): bool
    {
        if (! $this->inProduction()) {
            return true;
        }

        return file_exists(public_path('build/manifest.json'))
            && $this->getReturnsOk('/pricing');
    }
}
