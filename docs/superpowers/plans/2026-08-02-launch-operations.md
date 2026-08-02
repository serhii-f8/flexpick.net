# Phase 9A-2 Launch Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the in-repo enablers that make a first production deploy possible — deploy automation with a smoke gate and rehearsed rollback, CI, release tracking, Postmark wiring — then write the runbook that uses them to stand up staging, production, mail, and alerting.

**Architecture:** Ten tasks in two groups. Tasks 1–9 are code, each independently testable. Task 10 writes the runbook, which is only executable because tasks 1–9 exist. Deployer drives both environments from one `deploy.php` with two hosts; GitHub Actions gates PRs and deploys staging; production stays a deliberate human `dep deploy production`.

**Tech Stack:** PHP 8.4, Laravel 13, Deployer v8.0.0-rc, PHPUnit 11, Larastan 3 (level 3), Pint, GitHub Actions, Ploi, Postmark, self-hosted Bugsink via the Sentry SDK.

**Spec:** `docs/superpowers/specs/2026-08-02-launch-operations-runbook-design.md`

## Global Constraints

- **All PHP/artisan/composer/deployer commands run inside Docker**, from repo root `/var/www/html/flexpick.net`: `docker compose exec -T laravel.test <command>`. Never invoke `php` on the host.
- **Never run two test commands concurrently** — the testing database is shared and concurrent runs corrupt it.
- The full suite exceeds typical agent timeouts. Use `--filter` for per-task verification; run the full suite only at the checkpoints that call for it, with `timeout 900000`.
- Tests are **PHPUnit**, not Pest. Scaffold with `php artisan make:test --phpunit {name}`.
- `vendor/bin/pint --dirty --format agent` must be clean before each commit.
- `vendor/bin/phpstan analyse` must introduce no new error category. Level 3, `paths: app/`.
- Domains, fixed: marketing `flexpick.net`, production app `app.flexpick.net`, staging app `staging.app.flexpick.net`.
- **No secrets in git.** Credentials live in server `.env` files or GitHub Actions secrets. `.env.example` carries blank keys and comments only.
- Redis index allocation, fixed: production `REDIS_DB=0` / `REDIS_CACHE_DB=1`; staging `REDIS_DB=2` / `REDIS_CACHE_DB=3`.
- Commit after every task. Work on branch `growth-retention`.

---

### Task 1: Make factory emails unique by construction

Fixes the suite flake that would otherwise fail CI builds intermittently. Root cause (spec §2.1): Faker's `unique()` pool is rebuilt per application instance, which Laravel refreshes between tests, but `FeatureTest` never rolls back — so a fresh pool re-emits an address an earlier test already committed.

**Files:**
- Modify: `backend/database/factories/UserFactory.php:24`
- Test: `backend/tests/Unit/Factories/UserFactoryEmailTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `UserFactory::definition()['email']` matching `/^[0-9a-hjkmnp-tv-z]{26}@example\.test$/` — a lowercase Crockford-base32 ULID local-part. No later task depends on this.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Factories/UserFactoryEmailTest.php`. It extends `Tests\TestCase` (which boots the application, matching the precedent in `tests/Unit/TokenScrubberTest.php`) and uses `make()` so no database is touched.

```php
<?php

namespace Tests\Unit\Factories;

use App\Models\User;
use Tests\TestCase;

class UserFactoryEmailTest extends TestCase
{
    /**
     * The address must be unique by construction rather than by Faker's
     * unique() pool, which resets between tests while the rows it must
     * avoid are never rolled back. See spec 2026-08-02 §2.1.
     */
    public function test_email_local_part_is_a_ulid(): void
    {
        $email = User::factory()->make()->email;

        $this->assertMatchesRegularExpression(
            '/^[0-9a-hjkmnp-tv-z]{26}@example\.test$/',
            $email,
        );
    }

    public function test_a_thousand_generated_emails_are_distinct(): void
    {
        $emails = [];

        for ($i = 0; $i < 1000; $i++) {
            $emails[] = User::factory()->make()->email;
        }

        $this->assertCount(1000, array_unique($emails));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan test --filter=UserFactoryEmailTest
```

Expected: `test_email_local_part_is_a_ulid` FAILS — the generated address is a Faker `safeEmail()` such as `hansen.maggie@example.org`, which does not match the ULID pattern. `test_a_thousand_generated_emails_are_distinct` passes already; that is expected, because within a single test the Faker pool has not been reset. It is kept as a regression guard.

- [ ] **Step 3: Change the factory**

In `backend/database/factories/UserFactory.php`, replace the email line. `Str` is already imported at the top of the file.

```php
            'email' => Str::lower((string) Str::ulid()).'@example.test',
```

The full `definition()` return becomes:

```php
        return [
            'name' => fake()->name(),
            'email' => Str::lower((string) Str::ulid()).'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_admin' => false,
        ];
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan test --filter=UserFactoryEmailTest
```

Expected: both tests PASS.

- [ ] **Step 5: Run the full suite twice**

This is the actual proof — the flake is intermittent and only a full run exercises it. Run these **sequentially, never concurrently**.

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan test --compact
```

Then, after it completes:

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan test --compact
```

Expected: green both times. If a `users.email` unique-constraint violation still appears, stop — the fix is incomplete and some other code path is creating users with Faker emails; grep for `safeEmail` and `->unique()` across `database/` and `tests/` before proceeding.

- [ ] **Step 6: Format and commit**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test vendor/bin/pint --dirty --format agent
git add backend/database/factories/UserFactory.php backend/tests/Unit/Factories/UserFactoryEmailTest.php
git commit -m "test(backend): make factory emails unique by construction

Faker's unique() pool is rebuilt per application instance between tests,
but FeatureTest never rolls back, so the pool re-emits addresses earlier
tests already committed. A ULID local-part removes the dependence on
Faker state entirely."
```

---

### Task 2: Make the staging smoke gate real

`SmokeCommand::inProduction()` gates five of eight assertions on `config('app.env') === 'production'`. On a staging site running `APP_ENV=staging` the gate would pass while asserting almost nothing (spec §5.2.1). Broaden the guard to exclude only `local` and `testing`, preserving 9A-1's stated intent that the command "stays safe to run locally".

**Files:**
- Modify: `backend/app/Console/Commands/SmokeCommand.php:74-77` and its five call sites at lines 107, 117, 128, 141
- Modify: `backend/tests/Feature/Health/SmokeCommandTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SmokeCommand` now performs its full assertion set under any `app.env` other than `local` and `testing`. Task 6 relies on this when wiring `deploy:smoke`, and Task 10's runbook step 3 relies on it for the staging gate to mean anything.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Health/SmokeCommandTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify the staging one fails**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan test --filter=SmokeCommandTest
```

Expected: `test_mail_transport_assertion_is_enforced_on_staging` FAILS — the command exits 0 because `inProduction()` is false under `app.env=staging`, so the mail assertion short-circuits to `true`. `test_mail_transport_assertion_is_skipped_locally` passes already.

- [ ] **Step 3: Broaden the guard**

In `backend/app/Console/Commands/SmokeCommand.php`, replace the `inProduction()` method:

```php
    /**
     * Deployed environments — anything that is not a developer machine or the
     * test runner — get the full assertion set. Staging must be gated exactly
     * as strictly as production, or it certifies releases production rejects.
     */
    private function isDeployedEnvironment(): bool
    {
        return ! in_array(config('app.env'), ['local', 'testing'], true);
    }
```

Then update the four guards that reference it. In `cachesAreWarm()`, `horizonIsRunning()`, `mailTransportIsUsable()`, and `publicPageRenders()`, replace each occurrence of:

```php
        if (! $this->inProduction()) {
            return true;
        }
```

with:

```php
        if (! $this->isDeployedEnvironment()) {
            return true;
        }
```

Also update the class docblock at line 11–14 so it no longer implies the guard is production-only:

```php
/**
 * Post-deploy gate (spec §14.4, PR8). Read-only and safe to re-run against
 * any deployed environment: sends no email, runs no audit, writes nothing.
 * The full assertion set runs everywhere except `local` and `testing`.
 */
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan test --filter=SmokeCommandTest
```

Expected: all tests PASS, including the pre-existing ones which set `app.env` to `testing`.

- [ ] **Step 5: Static analysis and format, then commit**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test vendor/bin/phpstan analyse
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test vendor/bin/pint --dirty --format agent
git add backend/app/Console/Commands/SmokeCommand.php backend/tests/Feature/Health/SmokeCommandTest.php
git commit -m "fix(health): enforce the full smoke assertion set on staging

Five of eight assertions were gated on app.env === 'production', so a
staging gate would pass while asserting nothing. 9A-1's intent was that
app:smoke stay safe to run locally; local and testing remain exempt."
```

---

### Task 3: Root CI workflow

GitHub Actions reads workflows only from the repository root. `frontend/.github/workflows/actions.yaml` is tracked but has never executed, and its bare `npm ci` would fail at root. Replace it with a path-filtered root workflow.

**Files:**
- Create: `.github/workflows/ci.yml`
- Delete: `frontend/.github/workflows/actions.yaml` (and the now-empty `frontend/.github/`)

**Interfaces:**
- Consumes: Task 1's flake fix (without it the backend job fails intermittently).
- Produces: two jobs named `backend` and `frontend`, triggered on `pull_request` and `push` to `main`. Task 7's staging workflow is a separate file and does not depend on these job names.

- [ ] **Step 1: Delete the inert frontend workflow**

```bash
cd /var/www/html/flexpick.net && git rm -r frontend/.github
```

- [ ] **Step 2: Create the root workflow**

Create `.github/workflows/ci.yml`. Note that the MySQL service is configured to match `backend/.env.testing` exactly (database `testing`, user `sail`, password `password`), so only the host variables need overriding. Laravel loads `.env` files without overwriting real environment variables, so the `env:` block wins.

```yaml
name: CI

on:
  pull_request:
    branches: [main]
  push:
    branches: [main]

concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  changes:
    runs-on: ubuntu-latest
    outputs:
      backend: ${{ steps.filter.outputs.backend }}
      frontend: ${{ steps.filter.outputs.frontend }}
    steps:
      - uses: actions/checkout@v4
      - uses: dorny/paths-filter@v3
        id: filter
        with:
          filters: |
            backend:
              - 'backend/**'
              - '.github/workflows/ci.yml'
            frontend:
              - 'frontend/**'
              - '.github/workflows/ci.yml'

  backend:
    needs: changes
    if: needs.changes.outputs.backend == 'true'
    runs-on: ubuntu-latest
    timeout-minutes: 30
    defaults:
      run:
        working-directory: backend
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: password
          MYSQL_DATABASE: testing
          MYSQL_USER: sail
          MYSQL_PASSWORD: password
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -ppassword"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=10
      redis:
        image: redis:7
        ports:
          - 6379:6379
        options: >-
          --health-cmd="redis-cli ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=10
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      REDIS_HOST: 127.0.0.1
      REDIS_PORT: 6379
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: bcmath, curl, intl, mbstring, pdo_mysql, redis, zip
          coverage: none

      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: backend/vendor
          key: composer-${{ hashFiles('backend/composer.lock') }}
          restore-keys: composer-

      # composer install triggers package:discover, which boots the app. Without
      # a .env that boot is fragile. The suite itself still reads .env.testing,
      # because phpunit.xml sets APP_ENV=testing and Laravel then loads only
      # .env.testing -- so this file does not shadow the test configuration.
      - name: Prepare a .env for the composer boot
        run: cp .env.example .env

      - run: composer install --no-interaction --prefer-dist --no-progress

      - run: php artisan key:generate

      - name: Run the test suite
        run: php artisan test --compact

      - name: Check formatting
        run: vendor/bin/pint --test

      - name: Static analysis
        run: vendor/bin/phpstan analyse --no-progress

  frontend:
    needs: changes
    if: needs.changes.outputs.frontend == 'true'
    runs-on: ubuntu-latest
    timeout-minutes: 15
    defaults:
      run:
        working-directory: frontend
    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: '22.12.0'
          cache: npm
          cache-dependency-path: frontend/package-lock.json

      - run: npm ci
      - run: npm run check
      - run: npm run build
```

- [ ] **Step 3: Validate the YAML parses**

There is no local GitHub Actions runner here, so verify syntax rather than behaviour:

```bash
cd /var/www/html/flexpick.net && python3 -c "import yaml,sys; d=yaml.safe_load(open('.github/workflows/ci.yml')); print(sorted(d['jobs'].keys()))"
```

Expected: `['backend', 'changes', 'frontend']`

- [ ] **Step 4: Confirm the frontend commands exist**

The workflow calls `npm run check` and `npm run build`; confirm both are defined rather than assumed.

```bash
cd /var/www/html/flexpick.net/frontend && node -e "const s=require('./package.json').scripts; console.log(s.check, '|', s.build)"
```

Expected: both print a command, neither is `undefined`.

- [ ] **Step 5: Commit**

```bash
cd /var/www/html/flexpick.net
git add .github/workflows/ci.yml
git commit -m "ci: add root workflow gating backend and frontend

GitHub Actions reads workflows only from the repository root, so the
tracked frontend/.github workflow has never run. Path filters keep a
marketing copy change from running the PHP suite."
```

- [ ] **Step 6: Verify CI actually gates a failure**

This cannot be proven locally. Record it as a runbook obligation — Task 10 step "Verify CI" covers pushing a branch with a deliberately broken assertion, confirming the run goes red, fixing it, and confirming green. Until observed, CI is **not verified** under PR18.

---

### Task 4: Rewrite `deploy.php` configuration and hosts

The file is untouched SaaSykit boilerplate: host `1.2.3.4`, domain `yourdomain.com`, repo `username/saasykit`. Critically, `$subDirectory = ''` is wrong for this monorepo and would deploy the repository root, which has no `composer.json`.

**Files:**
- Modify: `backend/deploy.php:8-42`

**Interfaces:**
- Consumes: nothing.
- Produces: two Deployer hosts selectable by alias — `production` and `staging`. Tasks 5, 6 and 7 all reference these aliases. Every subsequent `dep` invocation takes the form `dep <task> production` or `dep <task> staging`.

- [ ] **Step 1: Replace the configuration block**

In `backend/deploy.php`, replace lines 8–42 (from `// Configs` through the `host(...)` chain) with:

```php
// Configs
// Server-specific values are read from the environment so no host detail is
// committed. Ploi provisions the `deployer` user with key-based sudo.

$remoteUser = 'deployer';
$sudoPassword = '';

$host = getenv('DEPLOY_HOST') ?: 'app.flexpick.net';

$repository = 'git@github.com:flexpick/flexpick.net.git';

// This is a monorepo. The Laravel application lives in backend/; deploying the
// repository root would deploy a directory with no composer.json.
$subDirectory = 'backend';

$phpVersion = '8.4';

// End of configs
// ///////////////////////////////////
// ///////////////////////////////////

set('repository', $repository);
set('sub_directory', $subDirectory);

set('node_version', '23.x');

add('shared_files', []);
add('shared_dirs', []);
add('writable_dirs', []);

host('production')
    ->setHostname($host)
    ->set('remote_user', $remoteUser)
    ->set('deploy_path', '~/app')
    ->set('sudo_password', $sudoPassword)
    ->set('domain', 'app.flexpick.net')
    ->set('public_path', 'public')
    ->set('php_version', $phpVersion)
    ->set('branch', 'main');

host('staging')
    ->setHostname($host)
    ->set('remote_user', $remoteUser)
    ->set('deploy_path', '~/staging')
    ->set('sudo_password', $sudoPassword)
    ->set('domain', 'staging.app.flexpick.net')
    ->set('public_path', 'public')
    ->set('php_version', $phpVersion)
    ->set('branch', 'main');
```

Both hosts share one hostname because staging is a second site on the same server (spec D9A2.2); they are separated by `deploy_path`, and by the environment isolation configured in Task 8.

**Confirm the repository URL before committing.** If `git@github.com:flexpick/flexpick.net.git` is not the real remote, use the actual one:

```bash
cd /var/www/html/flexpick.net && git remote get-url origin
```

- [ ] **Step 2: Verify Deployer parses the file and sees both hosts**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php vendor/bin/dep list 2>&1 | head -5
```

Expected: the task list prints with no PHP parse error or Deployer configuration exception.

- [ ] **Step 3: Verify both aliases resolve and carry the right paths**

`dep config` with no host argument prompts for a selection and will hang under `-T`; always name the host.

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php vendor/bin/dep config production 2>&1 | grep -E "deploy_path|domain|sub_directory|hostname"
```

Expected: `deploy_path` is `~/app`, `domain` is `app.flexpick.net`, `sub_directory` is `backend`.

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php vendor/bin/dep config staging 2>&1 | grep -E "deploy_path|domain"
```

Expected: `deploy_path` is `~/staging`, `domain` is `staging.app.flexpick.net`.

If `dep config` attempts an SSH connection and fails — it resolves some options lazily against the host — that failure is not a defect in this task; the values above are still printed before the connection is attempted. If nothing prints at all, fall back to asserting the source parses as intended:

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php -r '
$src = file_get_contents("deploy.php");
foreach (["host(\x27production\x27)", "host(\x27staging\x27)", "~/staging", "\x27sub_directory\x27, \x27backend\x27"] as $needle) {
    printf("%s %s\n", str_contains($src, $needle) ? "OK  " : "MISS", $needle);
}'
```

Expected: four `OK` lines. Note this fallback checks `set('sub_directory', $subDirectory)` indirectly — if it reports `MISS` on the last needle, confirm by eye that `$subDirectory = 'backend';` is set, since the plan assigns it through a variable.

- [ ] **Step 4: Commit**

```bash
cd /var/www/html/flexpick.net
git add backend/deploy.php
git commit -m "build(deploy): configure real hosts and the monorepo sub-directory

Replaces SaaSykit placeholders. sub_directory was empty, which would have
deployed the repository root -- a directory with no composer.json. Adds
production and staging hosts sharing one server per D9A2.2."
```

---

### Task 5: Inject `SENTRY_RELEASE` on deploy

Closes PR4's outstanding half. `config/sentry.php:21` reads `env('SENTRY_RELEASE')`, `.env.example` ships it blank with a comment claiming the deploy script injects it, and nothing does. Without this, captured Bugsink events carry no release context and a regression cannot be tied to a deploy.

**Files:**
- Modify: `backend/deploy.php` (add task + hook)

**Interfaces:**
- Consumes: Task 4's hosts.
- Produces: Deployer task `deploy:sentry-release`, hooked `before('artisan:optimize', ...)`. No later task calls it directly; Task 10's runbook verifies its effect.

- [ ] **Step 1: Understand the two constraints before writing the task**

Both are load-bearing and neither is obvious:

1. **Ordering.** `artisan:optimize` caches configuration, and `env()` returns `null` at runtime once config is cached. So `SENTRY_RELEASE` must be written **before** `artisan:optimize` runs. In the deploy tree, `artisan:optimize` follows `deploy:vendors`, so hooking `before('artisan:optimize', ...)` is correct.
2. **`.env` is a symlink.** The Laravel recipe sets `shared_files => ['.env']`, so `{{release_path}}/.env` is a symlink into `shared/`. `sed -i` writes a temp file and renames it, which **replaces the symlink with a regular file** and silently detaches the release from shared config. Resolve the symlink with `readlink -f` and edit the real path.

The deployed SHA comes from `{{release_path}}/REVISION`, which Deployer's `deploy:update_code` writes (`vendor/deployer/deployer/recipe/deploy/update_code.php:136`). The release directory has no `.git`, so `git rev-parse` is not an option.

- [ ] **Step 2: Add the task**

Add to `backend/deploy.php`, after the `deploy:export-configs` task definition:

```php
desc('Inject the deployed git SHA as SENTRY_RELEASE (PR4)');
task('deploy:sentry-release', function () {
    $sha = trim(run('cat {{release_path}}/REVISION'));

    if ($sha === '') {
        throw new \RuntimeException(
            'REVISION is empty; refusing to deploy without release context (PR4).'
        );
    }

    // .env is a shared file, so {{release_path}}/.env is a symlink. sed -i
    // renames over its target and would replace the symlink with a plain
    // file, detaching this release from shared config. Edit the real path.
    $envPath = trim(run('readlink -f {{release_path}}/.env'));

    run("if grep -q '^SENTRY_RELEASE=' $envPath; then
            sed -i -E 's|^SENTRY_RELEASE=.*|SENTRY_RELEASE=$sha|' $envPath;
         else
            echo 'SENTRY_RELEASE=$sha' >> $envPath;
         fi");

    info("SENTRY_RELEASE set to $sha");
});
```

- [ ] **Step 3: Hook it before config caching**

Add alongside the other hooks near the bottom of the file:

```php
before('artisan:optimize', 'deploy:sentry-release');
```

- [ ] **Step 4: Verify the task is registered and correctly ordered**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php vendor/bin/dep tree deploy 2>&1 | grep -B2 -A1 "sentry-release"
```

Expected: `deploy:sentry-release // before artisan:optimize` appears immediately **before** `artisan:optimize` in the tree. If it appears after, the hook is wrong and the cached config will carry a null release.

- [ ] **Step 5: Commit**

```bash
cd /var/www/html/flexpick.net
git add backend/deploy.php
git commit -m "build(deploy): inject the deployed SHA as SENTRY_RELEASE

Closes PR4's outstanding half. Runs before artisan:optimize because
env() returns null once config is cached, and resolves the .env symlink
before editing because sed -i would otherwise replace it with a file."
```

---

### Task 6: Smoke gate and rollback wiring

`deploy.php` has no smoke step and nothing that invokes rollback. Deployer ships the `rollback` primitive; what is missing is that nothing runs it.

**Files:**
- Modify: `backend/deploy.php` (add task + hooks)

**Interfaces:**
- Consumes: Task 2's broadened smoke guard, Task 4's hosts.
- Produces: Deployer task `deploy:smoke`, hooked `after('deploy:symlink', ...)`, and `after('deploy:failed', 'rollback')`. Task 7's staging workflow relies on a failing deploy exiting non-zero.

- [ ] **Step 1: Note the placement trade-off before wiring**

`app:smoke` asserts against the running application in-process (`app()->handle(Request::create(...))`) and checks the served Vite manifest and `/pricing`. It therefore must run **after** `deploy:symlink`, when the new release is live. That means a failing smoke leaves the bad release briefly live until rollback completes. Smoking before the swap cannot see the real URL, TLS, or manifest, which is most of what the command checks. The exposure window is accepted and bounded by the rehearsed rollback (spec §5.2).

Hooking on `deploy:symlink` rather than `deploy:success` matters: a failure at `deploy:symlink` still routes through Deployer's `deploy:failed`, which is what triggers rollback.

- [ ] **Step 2: Add the smoke task**

Add to `backend/deploy.php`, after `deploy:sentry-release`:

```php
desc('Post-release smoke gate (PR8) -- fails the deploy on a non-zero exit');
task('deploy:smoke', function () {
    // Runs against the freshly symlinked release, which is now live. Read-only:
    // sends no email, runs no audit, writes nothing. Exit code is the contract.
    run('cd {{current_path}} && {{bin/php}} artisan app:smoke', timeout: 120);
});
```

- [ ] **Step 3: Wire the hooks**

Add alongside the other hooks. The rollback hook must come after the existing `deploy:unlock` hook so the lock is released before the rollback runs.

```php
after('deploy:symlink', 'deploy:smoke');

// deploy:failed already unlocks (see above); rolling back afterwards returns
// the site to the previous release. Rehearsed in the runbook, step 8.
after('deploy:failed', 'rollback');
```

- [ ] **Step 4: Verify the wiring**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php vendor/bin/dep tree deploy 2>&1 | grep -A3 "deploy:symlink"
```

Expected: `deploy:smoke // after deploy:symlink` appears directly after `deploy:symlink`, inside `deploy:publish` and before `deploy:cleanup`.

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php vendor/bin/dep list 2>&1 | grep -E "deploy:smoke|deploy:sentry-release|^  rollback"
```

Expected: all three lines present.

- [ ] **Step 5: Commit**

```bash
cd /var/www/html/flexpick.net
git add backend/deploy.php
git commit -m "build(deploy): add the post-release smoke gate and rollback wiring

app:smoke runs after deploy:symlink because it asserts against the live
application; a failure routes through deploy:failed, which now rolls
back. The brief exposure window is documented in the spec."
```

---

### Task 7: Staging deploy workflow

Per D9A2.4, merges to `main` deploy staging automatically; production stays a deliberate human `dep deploy production`.

**Files:**
- Create: `.github/workflows/deploy-staging.yml`

**Interfaces:**
- Consumes: Tasks 4–6 (`staging` host alias, `deploy:smoke`).
- Produces: nothing later depends on this.

- [ ] **Step 1: Create the workflow**

The `DEPLOY_SSH_KEY` and `DEPLOY_HOST` secrets are created by the runbook (Task 10, step 3), not here.

```yaml
name: Deploy staging

on:
  push:
    branches: [main]

concurrency:
  group: deploy-staging
  cancel-in-progress: false

jobs:
  deploy:
    runs-on: ubuntu-latest
    timeout-minutes: 20
    environment: staging
    defaults:
      run:
        working-directory: backend
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none

      - run: composer install --no-interaction --prefer-dist --no-progress --no-dev

      - name: Load the deploy key
        uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.DEPLOY_SSH_KEY }}

      - name: Deploy
        env:
          DEPLOY_HOST: ${{ secrets.DEPLOY_HOST }}
        run: php vendor/bin/dep deploy staging --no-interaction
```

`deploy:smoke` runs inside `dep deploy` (Task 6), so a failing gate fails this job and rolls staging back. No separate smoke step is needed, and adding one would double-run the gate.

- [ ] **Step 2: Validate the YAML parses**

```bash
cd /var/www/html/flexpick.net && python3 -c "import yaml; d=yaml.safe_load(open('.github/workflows/deploy-staging.yml')); print(d['jobs']['deploy']['steps'][-1]['run'])"
```

Expected: `php vendor/bin/dep deploy staging --no-interaction`

- [ ] **Step 3: Commit**

```bash
cd /var/www/html/flexpick.net
git add .github/workflows/deploy-staging.yml
git commit -m "ci: deploy staging on merge to main

Production stays a deliberate human dep deploy per D9A2.4. The smoke
gate runs inside dep deploy, so a failing gate fails this job."
```

---

### Task 8: Postmark and environment-isolation keys

Postmark is already wired in code — `symfony/postmark-mailer` in `composer.json`, the `postmark` mailer in `config/mail.php:64`, `services.postmark.token` in `config/services.php:24`. Only environment documentation is missing, along with the Redis and Horizon keys that keep staging from dequeuing production audit jobs.

**Files:**
- Modify: `backend/.env.example`
- Modify: `backend/config/mail.php:64-69`

**Interfaces:**
- Consumes: nothing.
- Produces: documented keys `POSTMARK_TOKEN`, `POSTMARK_MESSAGE_STREAM_ID`, `REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_PREFIX`, `HORIZON_PREFIX`. Task 10's runbook steps 3 and 4 set these on the server.

- [ ] **Step 1: Add the message stream to the mailer config**

Postmark Message Streams are how staging sends real mail without touching production's sending reputation (spec §4.1). Laravel's Postmark transport reads `message_stream_id` from the mailer config. In `backend/config/mail.php`, replace the `postmark` block:

```php
        'postmark' => [
            'transport' => 'postmark',
            // Separate streams per environment so staging's live-delivery
            // proof (PR9) cannot affect production's sending reputation.
            'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],
```

- [ ] **Step 2: Document the mail keys in `.env.example`**

Replace the mail block in `backend/.env.example` (lines 31–41, ending at `MAIL_TIMEOUT=15`) with:

```
# Local development uses Mailpit. Deployed environments use Postmark
# (D9A2.3): set MAIL_MAILER=postmark and supply the two keys below.
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"
# SMTP socket timeout in seconds. Bounded so a hanging mail host cannot stall a
# scheduled command for PHP's 60s default; shared with the audit report mailer.
MAIL_TIMEOUT=15

# Postmark server API token. Blank locally; set on staging and production.
POSTMARK_TOKEN=
# Message Stream ID. Production and staging MUST use different streams so a
# staging send cannot affect production's sending reputation.
POSTMARK_MESSAGE_STREAM_ID=outbound
```

- [ ] **Step 3: Document the isolation keys in `.env.example`**

Staging runs on the same server as production (D9A2.2). Sharing a Redis index or Horizon prefix means staging workers dequeue real users' audit jobs. Append after the existing `REDIS_PORT` line:

```
# Redis index allocation. Production takes 0 (queue) and 1 (cache); staging
# takes 2 and 3. Sharing an index means staging workers dequeue production
# audit jobs -- running real users' analyses against staging credentials.
REDIS_DB=0
REDIS_CACHE_DB=1
# Namespaces stay disjoint even if an index is ever misconfigured.
REDIS_PREFIX=flexpick_database_
HORIZON_PREFIX=flexpick_horizon:
```

- [ ] **Step 4: Verify config resolves and nothing regressed**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan config:clear && docker compose exec -T laravel.test php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
var_dump(array_key_exists("message_stream_id", config("mail.mailers.postmark")));
var_dump(config("database.redis.default.database"));
'
```

Expected: `bool(true)` then the configured queue index.

Then confirm the mail-related suite still passes:

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan test --filter=Mail
```

Expected: PASS.

- [ ] **Step 5: Confirm no secrets were committed**

```bash
cd /var/www/html/flexpick.net && grep -nE "POSTMARK_TOKEN=.+|SENTRY_LARAVEL_DSN=.+|HEALTH_ENDPOINT_TOKEN=.+" backend/.env.example
```

Expected: no output. Any match is a committed secret — stop and remove it.

- [ ] **Step 6: Format and commit**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test vendor/bin/pint --dirty --format agent
git add backend/.env.example backend/config/mail.php
git commit -m "feat(mail): document Postmark and staging isolation keys

Postmark's package and config were already present; only env wiring was
missing. Adds per-environment message streams, and the Redis/Horizon
keys that stop staging workers dequeuing production audit jobs."
```

---

### Task 9: Prune superseded frontend deploy configs

Per D9A2.5 the marketing site is served from the same Ploi server. Three unused deploy configurations for a site with one deployment target is a trap: the next person to touch it has no way to know which is real.

**Files:**
- Delete: `frontend/netlify.toml`, `frontend/vercel.json`, `frontend/Dockerfile`, `frontend/nginx/`
- Modify: `frontend/CLAUDE.md` (the "Deployment" section)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

- [ ] **Step 1: Confirm nothing references them**

```bash
cd /var/www/html/flexpick.net && grep -rn "netlify\|vercel\|Dockerfile" --include="*.json" --include="*.yml" --include="*.yaml" --include="*.ts" --include="*.js" --include="*.md" frontend/ .github/ compose.yml 2>/dev/null | grep -v node_modules | grep -v "^frontend/CLAUDE.md"
```

Expected: no output. If `compose.yml` builds the frontend from `frontend/Dockerfile`, **stop** — the Docker dev environment depends on it and only `netlify.toml` and `vercel.json` may be deleted. Verify explicitly:

```bash
cd /var/www/html/flexpick.net && grep -n -A5 "frontend:" compose.yml
```

- [ ] **Step 2: Delete what step 1 cleared**

```bash
cd /var/www/html/flexpick.net && git rm frontend/netlify.toml frontend/vercel.json
```

And, only if step 1 showed `compose.yml` does not build from it:

```bash
cd /var/www/html/flexpick.net && git rm -r frontend/Dockerfile frontend/nginx
```

- [ ] **Step 3: Correct the documentation**

In `frontend/CLAUDE.md`, replace the `## Deployment` section body with:

```markdown
Static output to `dist/`. Deployed to Ploi as a site on the same server as the
Laravel app (`app.flexpick.net`), built by Ploi's static-site deploy script:
pull, `npm ci`, `npm run build`, publish `dist/`. Astro assets under `/_astro/`
get 1-year immutable cache headers, configured in the site's nginx block.
Rollback is a redeploy of the previous commit. CI runs build and lint checks on
Node 22 via the root `.github/workflows/ci.yml`.
```

- [ ] **Step 4: Verify the frontend still builds**

```bash
cd /var/www/html/flexpick.net/frontend && npm run build
```

Expected: build succeeds, `dist/` is produced.

- [ ] **Step 5: Commit**

```bash
cd /var/www/html/flexpick.net
git add -A frontend/
git commit -m "chore(frontend): remove superseded deploy configs

D9A2.5 puts the marketing site on Ploi. Three unused deploy
configurations for one target leave no way to tell which is real."
```

---

### Task 10: Write the runbook

The document that stands up the infrastructure. It is written last because every command it cites now exists.

**Files:**
- Create: `docs/superpowers/runbooks/2026-08-02-launch-operations-runbook.md`

**Interfaces:**
- Consumes: Tasks 1–9.
- Produces: the executable document. Nothing depends on it.

- [ ] **Step 1: Write the runbook with the ten steps from spec §6.1**

Every step uses the shape mandated by spec §6.2: **preconditions → exact commands → expected output → verification → what to do if it fails.** A step that cannot be verified gets no checkbox.

The ten steps, in the order fixed by spec §6.1 (DNS first because propagation is the long pole; staging fully exercised before production; alert delivery proven before production serves traffic):

0. **Secrets inventory** — every credential named, with storage location and rotation owner: `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `POSTMARK_TOKEN` ×2 streams, `SENTRY_LARAVEL_DSN`, `HEALTH_ENDPOINT_TOKEN`, `HEALTH_TELEGRAM_BOT_TOKEN`, `HEALTH_TELEGRAM_CHAT_ID`, `HEALTH_SLACK_WEBHOOK_URL`, database passwords ×2.
1. **All DNS at once** — A records for `flexpick.net`, `app.flexpick.net`, `staging.app.flexpick.net`; Postmark DKIM and Return-Path CNAMEs; SPF; DMARC at `p=none`.
2. **Server prep and Bugsink** — Docker container bound to loopback, Ploi-proxied with TLS. Gate: a hand-fired test event appears in Bugsink.
3. **Staging site and first deploy** — Ploi site, database `flexpick_staging`, `.env` with `APP_ENV=staging`, `REDIS_DB=2`, `REDIS_CACHE_DB=3`, distinct `REDIS_PREFIX`/`HORIZON_PREFIX`, `horizon-staging` supervisor. Create the `DEPLOY_SSH_KEY` and `DEPLOY_HOST` GitHub secrets here. Gate: `dep deploy staging` green and `app:smoke` exits 0 — and per Task 2 that gate is now meaningful on staging.
4. **Postmark** — verify DKIM/SPF/DMARC propagated, then a live send from staging. Gate: real inbox receipt with DKIM and SPF passing in the received headers, a matching `AuditEmailLog` row, and a successful resend.
5. **Alert channels** — Telegram bot, Slack webhook, mail. Deliberately destructive per spec §6.3: set `HEALTH_OLDEST_QUEUED_MINUTES=0` on staging, run `php artisan app:health-alerts`, confirm all three channels deliver, restore the value, confirm the recovery notification arrives.
6. **Production site and first deploy** — same shape as step 3 with `REDIS_DB=0`/`REDIS_CACHE_DB=1` and `horizon-prod`. Gate: `app:smoke` exits 0 against `app.flexpick.net`.
7. **Ploi uptime monitor** — pointed at `/health` with a real `HEALTH_ENDPOINT_TOKEN` (the endpoint 404s on an empty token), **plus** an explicit non-2xx rule. State plainly that a MySQL outage is detected off-box or not at all, because both the result store and `app:health-alerts` read MySQL (spec §6.4). Gate: monitor green, and a forced 503 pages you.
8. **Rollback rehearsal** — staging first, then production. Per spec §6.3, record wall-clock from "decision to roll back" to "smoke green on the previous release". Gate: two timed rollbacks, both recovering to a working app.
9. **Marketing site** — Ploi static site for `flexpick.net`. Gate: site serves and its call-to-action reaches `app.flexpick.net`.
10. **Support ownership** — record the owner (spec D9A2.6: solo) and both windows: next-business-day for `needs_followup`, same-day for `failed`, plus the daily signal that surfaces them.

Include one step not in the spec's table, appended to step 3, because Task 3 step 6 deferred it: **verify CI gates a real failure** — push a branch with a deliberately broken assertion, confirm the run goes red, fix it, confirm green.

Write every step in this shape. Step 5 is given here in full as the worked example, because it is the most intricate and the one most likely to be written vaguely:

```markdown
## Step 5 — Prove the alert channels deliver

**Preconditions:** Step 3 complete (staging deployed, `app:smoke` exits 0).
A Telegram bot created via @BotFather and its chat ID resolved; an incoming
Slack webhook URL; a deliverable address for `HEALTH_ALERT_MAIL_TO`.

**Why this step is destructive on purpose:** the 9A-1 suite proved alert
fan-out under `Notification::fake()`. It never proved delivery. The only way
to prove delivery is to make a check genuinely fail.

**Commands** (on the server, in the staging site directory):

    # 1. Configure all three channels.
    #    HEALTH_ALERT_CHANNELS defaults to `mail` alone in .env.example.
    HEALTH_ALERT_CHANNELS=mail,telegram,slack
    HEALTH_ALERT_MAIL_TO=<you@example.com>
    HEALTH_TELEGRAM_BOT_TOKEN=<token>
    HEALTH_TELEGRAM_CHAT_ID=<chat id>
    HEALTH_SLACK_WEBHOOK_URL=<webhook url>

    php artisan config:clear

    # 2. Force a failure: zero minutes means any pending audit is overdue.
    #    Note the current value first so step 4 restores it exactly.
    HEALTH_OLDEST_QUEUED_MINUTES=0
    php artisan config:clear
    php artisan health:check
    php artisan app:health-alerts

**Expected output:** `app:health-alerts` reports a dispatched alert for
`OldestPendingAuditCheck`.

**Verification:** the alert arrives in **all three** of Telegram, Slack and
the mailbox. Paste each into the evidence log. If one channel is silent but
the others deliver, that is the channel's credentials — `MailAlertChannel` is
self-guarding and will not let one failure kill the others, which is the
design being confirmed here.

**Restore and confirm recovery:**

    HEALTH_OLDEST_QUEUED_MINUTES=30
    php artisan config:clear
    php artisan health:check
    php artisan app:health-alerts

**Verification:** a recovery notification arrives on all three channels. A
missing recovery notification is a real defect, not a configuration slip —
recovery delivery is one of the three requirements that justified building
custom alert dispatch instead of using Spatie's built-in notifications.

**If it fails:** do not proceed to step 6. Production must not serve traffic
with unproven alerting; a day-one outage with silent alerts is
indistinguishable from having no monitoring at all.
```

Confirm the two config key names above against `backend/config/health.php` and `backend/.env.example` while writing, rather than copying them on trust.

- [ ] **Step 2: Add the evidence log**

The runbook ends with a table: one row per claim, with the actual pasted output. Under PR18, anything not observed is recorded as **"not verified"**, never checked off. Pre-populate the rows spec §7 predicts will land there:

- DMARC stays at `p=none` until a week of aggregate reports has been reviewed — moving to quarantine or reject sooner risks silently dropping the product's own audit-report emails, which are the deliverable.
- The database-outage alerting path remains off-box-only.

Rows the log must carry, from spec §7: CI gates real failures; `deploy:smoke` blocks a bad release; `SENTRY_RELEASE` is injected; alerts deliver on all three channels plus recovery; mail is deliverable with DKIM and SPF passing; rollback works, with the measured time; the flake is gone.

- [ ] **Step 3: Verify every command the runbook cites exists**

The runbook's whole value is that its steps are executable. Check the artisan commands it invokes:

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan list 2>&1 | grep -E "app:smoke|app:health-alerts"
```

Expected: both present.

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php vendor/bin/dep list 2>&1 | grep -E "deploy:smoke|deploy:sentry-release|^  rollback|^  releases"
```

Expected: all four present.

- [ ] **Step 4: Commit**

```bash
cd /var/www/html/flexpick.net
git add docs/superpowers/runbooks/2026-08-02-launch-operations-runbook.md
git commit -m "docs: add the Phase 9A-2 launch operations runbook

Ten ordered steps with an evidence log. DNS first because propagation is
the long pole; staging fully exercised before production; alert delivery
proven before production serves traffic."
```

---

### Final checkpoint

- [ ] **Run the full suite twice, sequentially**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan test --compact
```

Then, only after it finishes:

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test php artisan test --compact
```

Expected: green both times.

- [ ] **Run the remaining gates**

```bash
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test vendor/bin/phpstan analyse
cd /var/www/html/flexpick.net && docker compose exec -T laravel.test vendor/bin/pint --test
```

- [ ] **Update the phase checklist**

In `docs/2026-08-01-remaining-phases.md`, under "Carried out of 9A-1", tick the Faker-flake item (Task 1) and the `SENTRY_RELEASE` item (Task 5). Leave the Ploi-monitor and database-outage items unticked — they are runbook-execution obligations, not code.

Leave every Phase 9A bullet at line 89–92 unticked. PR8, PR9, PR13 and PR17 are satisfied by **executing** the runbook, not by writing it. Under PR18 the correct report until then is "not verified".

- [ ] **Commit the checklist update**

```bash
cd /var/www/html/flexpick.net
git add docs/2026-08-01-remaining-phases.md
git commit -m "docs: record 9A-2 in-repo enablers complete

The four launch-blocking bullets stay unticked -- they are satisfied by
executing the runbook against real infrastructure, not by writing it."
```
