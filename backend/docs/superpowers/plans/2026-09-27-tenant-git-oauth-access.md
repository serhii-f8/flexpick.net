# Tenant Git OAuth & Multi-Provider Access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single shared GitHub bot-account token with per-tenant OAuth connections (GitHub, GitLab, Bitbucket Cloud), closing the cross-workspace repo-access security hole and adding GitLab/Bitbucket support in the same change.

**Architecture:** A `GitProvider` interface (one implementation per provider) resolves a repo URL's host to provider-specific clone/branch-listing logic. A new `tenant_git_connections` table stores each tenant's encrypted OAuth token per provider. `RepositoryCloner` resolves the calling tenant's connection through a `GitRepoAccessResolver` instead of embedding one shared token; a missing connection fails the same way an unreachable repo already does (`AuditNotAnalyzableException` → `not_analyzable` → refund).

**Tech Stack:** Laravel 13 / PHP 8.4, Laravel Socialite (already configured for github/gitlab/bitbucket), Filament 5 (Dashboard panel), PHPUnit.

**Spec:** `backend/docs/superpowers/specs/2026-09-27-multi-provider-git-access-and-sizing-design.md` (this plan implements its Architecture, Connection flow, and Data flow & error handling sections only — Multi-run sizing is a separate plan).

## Global Constraints

- Cloud-hosted providers only: `github.com`, `gitlab.com`, `bitbucket.org`. No self-hosted/on-prem.
- The shared `config('audit.github_token')` model is retired outright — no fallback to it once this plan lands.
- One `TenantGitConnection` per `(tenant_id, provider)` — connecting again replaces the existing row (`updateOrCreate`).
- `access_token`/`refresh_token` must use Laravel's `encrypted` cast — this is the app's first per-tenant secret-storage table; no existing convention to copy for the storage shape, but the cast itself is built into Laravel 13.
- `RepositoryCloner::preflight()`/`clone()`/`remoteHeadSha()` take `?Tenant $tenant = null`, not a `bool $useToken` flag — `null` means anonymous/public-repo-only (landing-page requests, which have no tenant yet), matching `AuditRequest::tenant`/`AuditSchedule::tenant`'s natural nullability.
- Run `vendor/bin/pint --format agent` before finalizing each task's commit; the CI gate is `vendor/bin/pint --test`.
- Run `php artisan test --compact` (or `--filter=` for a single test) — this is a PHPUnit suite (`^11`), not Pest.

## Review Focus

- **Cross-tenant connection isolation.** Tenant A connects GitHub; Tenant B (no connection) submits Tenant A's private repo URL from their own dashboard. A reasonable person expects this to fail with "connect your account," never to succeed using Tenant A's token. This is the security fix itself — Task 7's tests must include this exact adversarial case, not just the happy path.
- **`remoteHeadSha()` must never throw.** Its docblock promises `null` on any failure so `ScheduledAuditChangeChecker` can treat it as "unknown, not unchanged." Wrapping it around `GitRepoAccessResolver::resolveCloneUrl()` (which throws for a missing connection) without a catch would silently break that contract for every scheduled tenant who hasn't connected an account yet.
- **Landing-page (tenantless) requests stay public-repo-only.** Today's `useToken: false` boolean explicitly forced anonymous access; replacing it with `tenant: $auditRequest->tenant` must produce the identical anonymous behavior when `tenant_id` is null, not accidentally start requiring a connection that can't exist yet.
- **Tokens are never stored in plaintext.** A future edit could drop the `encrypted` cast from `TenantGitConnection` without any test noticing. A test reading the raw DB column (bypassing the model's accessor) is the only thing that catches this.
- **OAuth connect-intent can't be used to attach a connection to a tenant the user doesn't belong to.** `redirect()`/`callback()`'s `tenant_id` comes from a query string / session value a client could tamper with; both ends must verify `auth()->user()->tenants()->where('tenants.id', $tenantId)->exists()`, not just "is someone logged in."

---

## File Structure

**New:**
- `database/migrations/2026_09_27_000001_create_tenant_git_connections_table.php`
- `app/Models/TenantGitConnection.php`
- `database/factories/TenantGitConnectionFactory.php`
- `app/Services/GitProviders/GitProvider.php` (interface)
- `app/Services/GitProviders/GitHubProvider.php` (replaces `App\Services\GitHub\GitHubApiClient`)
- `app/Services/GitProviders/GitLabProvider.php`
- `app/Services/GitProviders/BitbucketProvider.php`
- `app/Services/GitProviders/GitProviderResolver.php`
- `app/Services/GitProviders/GitRepoAccessResolver.php`
- `app/Services/GitProviders/GitConnectionService.php`
- `app/Filament/Dashboard/Pages/GitConnections.php`
- `resources/views/filament/dashboard/pages/git-connections.blade.php`
- Tests: one Feature test file per new class above, under the mirrored `tests/Feature/...` path.

**Modify:**
- `app/Services/AuditReport/RepositoryCloner.php` — inject `GitRepoAccessResolver`; `preflight()`/`clone()`/`remoteHeadSha()` take `?Tenant $tenant` instead of `bool $useToken`.
- `app/Services/AuditReport/AuditPipeline.php:67-68` — pass `tenant: $auditRequest->tenant`.
- `app/Services/AuditRequestService.php:120` — pass `tenant: $auditRequest->tenant` instead of `useToken: false`.
- `app/Services/AuditReport/ScheduledAuditChangeChecker.php:13` — pass `tenant: $schedule->tenant`.
- `app/Filament/Dashboard/Pages/AuditReports.php` — `launchAudit()` passes `tenant: $tenant` to `preflight()`; `loadBranches()` resolves the provider + tenant connection instead of `GitHubApiClient`.
- `app/Http/Controllers/Auth/OAuthController.php` — branch `redirect()`/`callback()` on a `git_connection` intent.
- `app/Mail/Audit/AuditRepoAccessNeeded.php` + `resources/views/emails/audit/access-needed.blade.php` — "connect your account" copy, provider-aware.
- `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php:279-280` — same copy change in `statusDescription()`.
- `resources/views/filament/dashboard/pages/audit-reports.blade.php:19` — same copy change in the launch form's private-repo disclosure.
- `config/audit.php` — remove `github_account`/`github_token`.
- `config/services.php` — no structural change; `.env.example` gains `GITLAB_CLIENT_ID`/`SECRET` and `BITBUCKET_CLIENT_ID`/`SECRET` (already read by `services.php`, never documented).
- `app/Support/Sentry/TokenScrubber.php` — remove the dead `config('audit.github_token')`-specific scrub line (the generic embedded-credential regex already covers every provider's clone URL shape).

**Delete:**
- `app/Services/GitHub/GitHubApiClient.php`
- `tests/Feature/Services/GitHub/GitHubApiClientTest.php` (superseded by `GitHubProviderTest`)

---

### Task 1: `TenantGitConnection` — migration, model, factory

**Files:**
- Create: `database/migrations/2026_09_27_000001_create_tenant_git_connections_table.php`
- Create: `app/Models/TenantGitConnection.php`
- Create: `database/factories/TenantGitConnectionFactory.php`
- Test: `tests/Feature/Models/TenantGitConnectionTest.php`

**Interfaces:**
- Produces: `TenantGitConnection` with `tenant_id: int`, `provider: string`, `account_login: ?string`, `access_token: string` (encrypted cast), `refresh_token: ?string` (encrypted cast), `expires_at: ?Carbon`, `connected_by_user_id: ?int`, `connected_at: ?Carbon`. Unique on `(tenant_id, provider)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

class TenantGitConnectionTest extends FeatureTest
{
    public function test_access_token_is_encrypted_at_rest(): void
    {
        $tenant = Tenant::factory()->create();

        $connection = TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github',
            'access_token' => 'ghp_super_secret_token',
        ]);

        $this->assertSame('ghp_super_secret_token', $connection->access_token);

        $rawValue = DB::table('tenant_git_connections')->where('id', $connection->id)->value('access_token');
        $this->assertNotSame('ghp_super_secret_token', $rawValue);
        $this->assertStringNotContainsString('ghp_super_secret_token', $rawValue);
    }

    public function test_one_connection_per_tenant_per_provider(): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=TenantGitConnectionTest`
Expected: FAIL — class `App\Models\TenantGitConnection` not found.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_git_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('account_login')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_git_connections');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantGitConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'provider',
        'account_login',
        'access_token',
        'refresh_token',
        'expires_at',
        'connected_by_user_id',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantGitConnection> */
class TenantGitConnectionFactory extends Factory
{
    protected $model = TenantGitConnection::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => 'github',
            'account_login' => $this->faker->userName(),
            'access_token' => 'test-token-'.$this->faker->uuid(),
            'refresh_token' => null,
            'expires_at' => null,
            'connected_by_user_id' => User::factory(),
            'connected_at' => now(),
        ];
    }
}
```

- [ ] **Step 6: Run migration and test to verify it passes**

Run: `docker compose exec laravel.test php artisan migrate && docker compose exec laravel.test php artisan test --filter=TenantGitConnectionTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_27_000001_create_tenant_git_connections_table.php \
        app/Models/TenantGitConnection.php \
        database/factories/TenantGitConnectionFactory.php \
        tests/Feature/Models/TenantGitConnectionTest.php
git commit -m "feat(audit): add tenant_git_connections table for per-tenant repo-access OAuth tokens"
```

---

### Task 2: `GitProvider` interface + `GitHubProvider`

Replaces `App\Services\GitHub\GitHubApiClient`. This task only migrates GitHub; deletion of the old class happens in Task 11, once nothing references it.

**Files:**
- Create: `app/Services/GitProviders/GitProvider.php`
- Create: `app/Services/GitProviders/GitHubProvider.php`
- Test: `tests/Feature/Services/GitProviders/GitHubProviderTest.php`

**Interfaces:**
- Consumes: `App\Models\TenantGitConnection` (Task 1).
- Produces: `interface GitProvider { name(): string; label(): string; authorizationScopes(): array; listBranches(TenantGitConnection $connection, string $repoUrl): array; cloneUrl(TenantGitConnection $connection, string $repoUrl): string; }` — used by Tasks 3-9.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitHubProvider;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class GitHubProviderTest extends FeatureTest
{
    public function test_identity(): void
    {
        $provider = new GitHubProvider;

        $this->assertSame('github', $provider->name());
        $this->assertSame('GitHub', $provider->label());
        $this->assertSame(['repo'], $provider->authorizationScopes());
    }

    public function test_clone_url_embeds_the_connections_own_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'ghp_tenant_token']);

        $url = (new GitHubProvider)->cloneUrl($connection, 'https://github.com/acme/app');

        $this->assertSame('https://x-access-token:ghp_tenant_token@github.com/acme/app', $url);
    }

    public function test_returns_branch_names_for_a_valid_repo(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'ghp_tenant_token']);
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([
            ['name' => 'main'], ['name' => 'develop'],
        ])]);

        $branches = (new GitHubProvider)->listBranches($connection, 'https://github.com/acme/app');

        $this->assertSame(['main', 'develop'], $branches);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_tenant_token'));
    }

    public function test_returns_empty_array_for_an_inaccessible_repo(): void
    {
        $connection = TenantGitConnection::factory()->make();
        Http::fake(['api.github.com/*' => Http::response(null, 404)]);

        $this->assertSame([], (new GitHubProvider)->listBranches($connection, 'https://github.com/acme/private'));
    }

    public function test_caches_branches_per_connection(): void
    {
        $connectionA = TenantGitConnection::factory()->make(['id' => 1, 'access_token' => 'token-a']);
        $connectionB = TenantGitConnection::factory()->make(['id' => 2, 'access_token' => 'token-b']);
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([['name' => 'main']])]);

        $provider = new GitHubProvider;
        $provider->listBranches($connectionA, 'https://github.com/acme/app');
        $provider->listBranches($connectionA, 'https://github.com/acme/app');
        $provider->listBranches($connectionB, 'https://github.com/acme/app');

        Http::assertSentCount(2);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=GitHubProviderTest`
Expected: FAIL — class `App\Services\GitProviders\GitHubProvider` not found.

- [ ] **Step 3: Write the interface**

```php
<?php

namespace App\Services\GitProviders;

use App\Models\TenantGitConnection;

interface GitProvider
{
    public function name(): string;

    public function label(): string;

    /** @return list<string> */
    public function authorizationScopes(): array;

    /** @return list<string> */
    public function listBranches(TenantGitConnection $connection, string $repoUrl): array;

    public function cloneUrl(TenantGitConnection $connection, string $repoUrl): string;
}
```

- [ ] **Step 4: Write `GitHubProvider`**

```php
<?php

namespace App\Services\GitProviders;

use App\Models\TenantGitConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubProvider implements GitProvider
{
    public function name(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function authorizationScopes(): array
    {
        return ['repo'];
    }

    public function listBranches(TenantGitConnection $connection, string $repoUrl): array
    {
        $repo = $this->parseRepo($repoUrl);

        if ($repo === null) {
            return [];
        }

        /** @var list<string> */
        return Cache::remember(
            "github_branches:{$connection->id}:{$repo['owner']}/{$repo['name']}",
            now()->addMinutes(15),
            function () use ($connection, $repo): array {
                try {
                    $response = Http::timeout(10)->connectTimeout(5)
                        ->withToken($connection->access_token)
                        ->get("https://api.github.com/repos/{$repo['owner']}/{$repo['name']}/branches", ['per_page' => 100])
                        ->throw();

                    return collect($response->json())->pluck('name')->filter()->values()->all();
                } catch (Throwable) {
                    return [];
                }
            },
        );
    }

    public function cloneUrl(TenantGitConnection $connection, string $repoUrl): string
    {
        return 'https://x-access-token:'.$connection->access_token.'@'.substr($repoUrl, strlen('https://'));
    }

    /** @return array{owner:string,name:string}|null */
    private function parseRepo(string $repoUrl): ?array
    {
        if (! preg_match('#github\.com[:/]([^/]+)/([^/.]+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            return null;
        }

        return ['owner' => $matches[1], 'name' => $matches[2]];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=GitHubProviderTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/GitProviders/GitProvider.php \
        app/Services/GitProviders/GitHubProvider.php \
        tests/Feature/Services/GitProviders/GitHubProviderTest.php
git commit -m "feat(audit): add GitProvider interface and GitHubProvider"
```

---

### Task 3: `GitLabProvider`

**Files:**
- Create: `app/Services/GitProviders/GitLabProvider.php`
- Test: `tests/Feature/Services/GitProviders/GitLabProviderTest.php`

**Interfaces:**
- Consumes: `GitProvider` interface (Task 2).
- Produces: `GitLabProvider implements GitProvider`, used by Task 5.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitLabProvider;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class GitLabProviderTest extends FeatureTest
{
    public function test_identity(): void
    {
        $provider = new GitLabProvider;

        $this->assertSame('gitlab', $provider->name());
        $this->assertSame('GitLab', $provider->label());
        $this->assertSame(['read_repository'], $provider->authorizationScopes());
    }

    public function test_clone_url_embeds_the_connections_own_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'glpat_tenant_token']);

        $url = (new GitLabProvider)->cloneUrl($connection, 'https://gitlab.com/acme/app');

        $this->assertSame('https://oauth2:glpat_tenant_token@gitlab.com/acme/app', $url);
    }

    public function test_returns_branch_names_for_a_nested_group_path(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'glpat_tenant_token']);
        Http::fake(['gitlab.com/api/v4/projects/acme%2Fteam%2Fapp/repository/branches*' => Http::response([
            ['name' => 'main'], ['name' => 'develop'],
        ])]);

        $branches = (new GitLabProvider)->listBranches($connection, 'https://gitlab.com/acme/team/app');

        $this->assertSame(['main', 'develop'], $branches);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer glpat_tenant_token'));
    }

    public function test_returns_empty_array_for_an_inaccessible_repo(): void
    {
        $connection = TenantGitConnection::factory()->make();
        Http::fake(['gitlab.com/*' => Http::response(null, 404)]);

        $this->assertSame([], (new GitLabProvider)->listBranches($connection, 'https://gitlab.com/acme/private'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=GitLabProviderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `GitLabProvider`**

```php
<?php

namespace App\Services\GitProviders;

use App\Models\TenantGitConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitLabProvider implements GitProvider
{
    public function name(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab';
    }

    public function authorizationScopes(): array
    {
        return ['read_repository'];
    }

    public function listBranches(TenantGitConnection $connection, string $repoUrl): array
    {
        $path = $this->parseRepo($repoUrl);

        if ($path === null) {
            return [];
        }

        /** @var list<string> */
        return Cache::remember(
            "gitlab_branches:{$connection->id}:{$path}",
            now()->addMinutes(15),
            function () use ($connection, $path): array {
                try {
                    $response = Http::timeout(10)->connectTimeout(5)
                        ->withToken($connection->access_token)
                        ->get('https://gitlab.com/api/v4/projects/'.rawurlencode($path).'/repository/branches', ['per_page' => 100])
                        ->throw();

                    return collect($response->json())->pluck('name')->filter()->values()->all();
                } catch (Throwable) {
                    return [];
                }
            },
        );
    }

    public function cloneUrl(TenantGitConnection $connection, string $repoUrl): string
    {
        return 'https://oauth2:'.$connection->access_token.'@'.substr($repoUrl, strlen('https://'));
    }

    private function parseRepo(string $repoUrl): ?string
    {
        if (! preg_match('#gitlab\.com[:/](.+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=GitLabProviderTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/GitProviders/GitLabProvider.php tests/Feature/Services/GitProviders/GitLabProviderTest.php
git commit -m "feat(audit): add GitLabProvider"
```

---

### Task 4: `BitbucketProvider`

**Files:**
- Create: `app/Services/GitProviders/BitbucketProvider.php`
- Test: `tests/Feature/Services/GitProviders/BitbucketProviderTest.php`

**Interfaces:**
- Consumes: `GitProvider` interface (Task 2).
- Produces: `BitbucketProvider implements GitProvider`, used by Task 5.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\TenantGitConnection;
use App\Services\GitProviders\BitbucketProvider;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class BitbucketProviderTest extends FeatureTest
{
    public function test_identity(): void
    {
        $provider = new BitbucketProvider;

        $this->assertSame('bitbucket', $provider->name());
        $this->assertSame('Bitbucket', $provider->label());
        $this->assertSame(['repository'], $provider->authorizationScopes());
    }

    public function test_clone_url_embeds_the_connections_own_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'bb_tenant_token']);

        $url = (new BitbucketProvider)->cloneUrl($connection, 'https://bitbucket.org/acme/app');

        $this->assertSame('https://x-token-auth:bb_tenant_token@bitbucket.org/acme/app', $url);
    }

    public function test_returns_branch_names_for_a_valid_repo(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'bb_tenant_token']);
        Http::fake(['api.bitbucket.org/2.0/repositories/acme/app/refs/branches*' => Http::response([
            'values' => [['name' => 'main'], ['name' => 'develop']],
        ])]);

        $branches = (new BitbucketProvider)->listBranches($connection, 'https://bitbucket.org/acme/app');

        $this->assertSame(['main', 'develop'], $branches);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer bb_tenant_token'));
    }

    public function test_returns_empty_array_for_an_inaccessible_repo(): void
    {
        $connection = TenantGitConnection::factory()->make();
        Http::fake(['api.bitbucket.org/*' => Http::response(null, 404)]);

        $this->assertSame([], (new BitbucketProvider)->listBranches($connection, 'https://bitbucket.org/acme/private'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=BitbucketProviderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `BitbucketProvider`**

```php
<?php

namespace App\Services\GitProviders;

use App\Models\TenantGitConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class BitbucketProvider implements GitProvider
{
    public function name(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket';
    }

    public function authorizationScopes(): array
    {
        return ['repository'];
    }

    public function listBranches(TenantGitConnection $connection, string $repoUrl): array
    {
        $repo = $this->parseRepo($repoUrl);

        if ($repo === null) {
            return [];
        }

        /** @var list<string> */
        return Cache::remember(
            "bitbucket_branches:{$connection->id}:{$repo['workspace']}/{$repo['slug']}",
            now()->addMinutes(15),
            function () use ($connection, $repo): array {
                try {
                    $response = Http::timeout(10)->connectTimeout(5)
                        ->withToken($connection->access_token)
                        ->get("https://api.bitbucket.org/2.0/repositories/{$repo['workspace']}/{$repo['slug']}/refs/branches")
                        ->throw();

                    return collect($response->json('values'))->pluck('name')->filter()->values()->all();
                } catch (Throwable) {
                    return [];
                }
            },
        );
    }

    public function cloneUrl(TenantGitConnection $connection, string $repoUrl): string
    {
        return 'https://x-token-auth:'.$connection->access_token.'@'.substr($repoUrl, strlen('https://'));
    }

    /** @return array{workspace:string,slug:string}|null */
    private function parseRepo(string $repoUrl): ?array
    {
        if (! preg_match('#bitbucket\.org[:/]([^/]+)/([^/.]+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            return null;
        }

        return ['workspace' => $matches[1], 'slug' => $matches[2]];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=BitbucketProviderTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/GitProviders/BitbucketProvider.php tests/Feature/Services/GitProviders/BitbucketProviderTest.php
git commit -m "feat(audit): add BitbucketProvider"
```

---

### Task 5: `GitProviderResolver`

**Files:**
- Create: `app/Services/GitProviders/GitProviderResolver.php`
- Test: `tests/Feature/Services/GitProviders/GitProviderResolverTest.php`

**Interfaces:**
- Consumes: `GitHubProvider`, `GitLabProvider`, `BitbucketProvider` (Tasks 2-4).
- Produces: `GitProviderResolver::forUrl(string $url): ?GitProvider` and `::forProviderName(string $name): GitProvider`, used by Task 6 and Task 9.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\GitProviders;

use App\Services\GitProviders\BitbucketProvider;
use App\Services\GitProviders\GitHubProvider;
use App\Services\GitProviders\GitLabProvider;
use App\Services\GitProviders\GitProviderResolver;
use Tests\Feature\FeatureTest;

class GitProviderResolverTest extends FeatureTest
{
    public function test_resolves_by_host(): void
    {
        $resolver = app(GitProviderResolver::class);

        $this->assertInstanceOf(GitHubProvider::class, $resolver->forUrl('https://github.com/acme/app'));
        $this->assertInstanceOf(GitLabProvider::class, $resolver->forUrl('https://gitlab.com/acme/app'));
        $this->assertInstanceOf(BitbucketProvider::class, $resolver->forUrl('https://bitbucket.org/acme/app'));
    }

    public function test_returns_null_for_an_unrecognized_host(): void
    {
        $this->assertNull(app(GitProviderResolver::class)->forUrl('https://example.com/acme/app'));
        $this->assertNull(app(GitProviderResolver::class)->forUrl('file:///tmp/fixture-repo'));
    }

    public function test_resolves_by_provider_name(): void
    {
        $resolver = app(GitProviderResolver::class);

        $this->assertInstanceOf(GitHubProvider::class, $resolver->forProviderName('github'));
        $this->assertInstanceOf(GitLabProvider::class, $resolver->forProviderName('gitlab'));
        $this->assertInstanceOf(BitbucketProvider::class, $resolver->forProviderName('bitbucket'));
    }

    public function test_throws_for_an_unknown_provider_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(GitProviderResolver::class)->forProviderName('sourceforge');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=GitProviderResolverTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `GitProviderResolver`**

```php
<?php

namespace App\Services\GitProviders;

use InvalidArgumentException;

class GitProviderResolver
{
    public function __construct(
        private GitHubProvider $github,
        private GitLabProvider $gitlab,
        private BitbucketProvider $bitbucket,
    ) {}

    public function forUrl(string $url): ?GitProvider
    {
        return match (parse_url($url, PHP_URL_HOST)) {
            'github.com' => $this->github,
            'gitlab.com' => $this->gitlab,
            'bitbucket.org' => $this->bitbucket,
            default => null,
        };
    }

    public function forProviderName(string $name): GitProvider
    {
        return match ($name) {
            'github' => $this->github,
            'gitlab' => $this->gitlab,
            'bitbucket' => $this->bitbucket,
            default => throw new InvalidArgumentException("Unknown git provider: {$name}"),
        };
    }
}
```

Note: no `AppServiceProvider` binding is needed — `GitHubProvider`/`GitLabProvider`/`BitbucketProvider` are concrete classes with no constructor arguments, so Laravel's container resolves them automatically.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=GitProviderResolverTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/GitProviders/GitProviderResolver.php tests/Feature/Services/GitProviders/GitProviderResolverTest.php
git commit -m "feat(audit): add GitProviderResolver"
```

---

### Task 6: `GitRepoAccessResolver`

The core security-fix logic: given a repo URL and an optional tenant, produce either the tenant's own authenticated clone URL, an unauthenticated passthrough (anonymous/unrecognized host), or a thrown `AuditNotAnalyzableException` when a tenant has no connection for that provider.

**Files:**
- Create: `app/Services/GitProviders/GitRepoAccessResolver.php`
- Test: `tests/Feature/Services/GitProviders/GitRepoAccessResolverTest.php`

**Interfaces:**
- Consumes: `GitProviderResolver` (Task 5), `TenantGitConnection` (Task 1), `App\Exceptions\AuditNotAnalyzableException` (existing).
- Produces: `GitRepoAccessResolver::resolveCloneUrl(string $repoUrl, ?Tenant $tenant): string` and `::connectionFor(string $repoUrl, Tenant $tenant): ?TenantGitConnection`, used by Task 7 (`RepositoryCloner`) and Task 8 (`AuditReports::loadBranches()`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\GitProviders;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitRepoAccessResolver;
use Tests\Feature\FeatureTest;

class GitRepoAccessResolverTest extends FeatureTest
{
    public function test_returns_the_authenticated_clone_url_for_a_connected_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github',
            'access_token' => 'ghp_tenant_token',
        ]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/app', $tenant);

        $this->assertSame('https://x-access-token:ghp_tenant_token@github.com/acme/app', $url);
    }

    public function test_throws_when_the_tenant_has_no_connection_for_that_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(AuditNotAnalyzableException::class);
        $this->expectExceptionMessage('Connect your GitHub account to audit this repository.');

        app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/app', $tenant);
    }

    /**
     * The core security fix: Tenant A's GitHub connection must never be used
     * to clone a repo on Tenant B's behalf, even though both reference the
     * same provider.
     */
    public function test_tenant_a_connection_is_never_used_for_tenant_b(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenantA)->create([
            'provider' => 'github',
            'access_token' => 'tenant-a-token',
        ]);

        $this->expectException(AuditNotAnalyzableException::class);

        app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/private', $tenantB);
    }

    public function test_returns_the_url_unchanged_when_no_tenant_is_given(): void
    {
        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/public', null);

        $this->assertSame('https://github.com/acme/public', $url);
    }

    public function test_returns_the_url_unchanged_for_an_unrecognized_host(): void
    {
        $tenant = Tenant::factory()->create();

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://example.com/acme/app', $tenant);

        $this->assertSame('https://example.com/acme/app', $url);
    }

    public function test_connection_for_returns_the_matching_tenant_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $connection = TenantGitConnection::factory()->for($tenant)->create(['provider' => 'gitlab']);

        $found = app(GitRepoAccessResolver::class)->connectionFor('https://gitlab.com/acme/app', $tenant);

        $this->assertSame($connection->id, $found?->id);
    }

    public function test_connection_for_returns_null_when_none_exists(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNull(app(GitRepoAccessResolver::class)->connectionFor('https://gitlab.com/acme/app', $tenant));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=GitRepoAccessResolverTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `GitRepoAccessResolver`**

```php
<?php

namespace App\Services\GitProviders;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Models\TenantGitConnection;

class GitRepoAccessResolver
{
    public function __construct(private GitProviderResolver $providers) {}

    public function resolveCloneUrl(string $repoUrl, ?Tenant $tenant): string
    {
        $provider = $this->providers->forUrl($repoUrl);

        if ($provider === null || $tenant === null) {
            return $repoUrl;
        }

        $connection = $this->connectionFor($repoUrl, $tenant);

        if ($connection === null) {
            throw AuditNotAnalyzableException::accessDenied(
                "Connect your {$provider->label()} account to audit this repository."
            );
        }

        return $provider->cloneUrl($connection, $repoUrl);
    }

    public function connectionFor(string $repoUrl, Tenant $tenant): ?TenantGitConnection
    {
        $provider = $this->providers->forUrl($repoUrl);

        if ($provider === null) {
            return null;
        }

        return TenantGitConnection::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $provider->name())
            ->first();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=GitRepoAccessResolverTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/GitProviders/GitRepoAccessResolver.php tests/Feature/Services/GitProviders/GitRepoAccessResolverTest.php
git commit -m "feat(audit): add GitRepoAccessResolver, the tenant-scoped repo-access gate"
```

---

### Task 7: Wire `GitRepoAccessResolver` into `RepositoryCloner`

This is the task that actually closes the security hole in production code paths — Tasks 1-6 only introduced new, unused classes.

**Files:**
- Modify: `app/Services/AuditReport/RepositoryCloner.php` (full contents below)
- Modify: `app/Services/AuditReport/AuditPipeline.php:67-68`
- Modify: `app/Services/AuditRequestService.php:120`
- Modify: `app/Services/AuditReport/ScheduledAuditChangeChecker.php:13`
- Modify: `app/Filament/Dashboard/Pages/AuditReports.php:225`
- Test: `tests/Feature/Services/AuditReport/RepositoryClonerTest.php` (new — none existed before; the class was only exercised indirectly through pipeline tests)
- Modify: `tests/Support/RunsAuditPipelineWithFakes.php` if it constructs `AuditRequest` without a tenant for tests that now need one (see Step 6)

**Interfaces:**
- Consumes: `GitRepoAccessResolver` (Task 6).
- Produces: `RepositoryCloner::preflight(string $url, ?Tenant $tenant = null): void`, `::clone(string $url, string $uuid, ?Tenant $tenant = null, ?string $branch = null): string`, `::remoteHeadSha(string $url, ?string $branch = null, ?Tenant $tenant = null): ?string` — the `bool $useToken` parameter is gone.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Services\AuditReport;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Services\AuditReport\RepositoryCloner;
use Tests\Feature\FeatureTest;

class RepositoryClonerTest extends FeatureTest
{
    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRepo = base_path('tests/fixtures/sample-repo');
    }

    public function test_preflight_succeeds_against_a_public_local_repo_with_no_tenant(): void
    {
        app(RepositoryCloner::class)->preflight('file://'.$this->fixtureRepo, tenant: null);

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_preflight_throws_when_a_connected_provider_host_has_no_tenant_connection(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(AuditNotAnalyzableException::class);
        $this->expectExceptionMessage('Connect your GitHub account to audit this repository.');

        app(RepositoryCloner::class)->preflight('https://github.com/acme/private', tenant: $tenant);
    }

    /**
     * The security fix, exercised through the real preflight path: Tenant B
     * cannot reach a repo using Tenant A's connection.
     */
    public function test_preflight_does_not_leak_another_tenants_connection(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenantA)->create(['provider' => 'github']);

        $this->expectException(AuditNotAnalyzableException::class);

        app(RepositoryCloner::class)->preflight('https://github.com/acme/app', tenant: $tenantB);
    }

    public function test_remote_head_sha_returns_null_instead_of_throwing_when_no_connection_exists(): void
    {
        $tenant = Tenant::factory()->create();

        $sha = app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/private', tenant: $tenant);

        $this->assertNull($sha);
    }

    public function test_remote_head_sha_still_resolves_a_public_local_repo_with_no_tenant(): void
    {
        $sha = app(RepositoryCloner::class)->remoteHeadSha('file://'.$this->fixtureRepo);

        $this->assertNotNull($sha);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=RepositoryClonerTest`
Expected: FAIL — `preflight()`/`remoteHeadSha()` don't accept a `tenant` named argument yet (current signatures use `bool $useToken`).

- [ ] **Step 3: Rewrite `RepositoryCloner`**

```php
<?php

namespace App\Services\AuditReport;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Services\GitProviders\GitRepoAccessResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class RepositoryCloner
{
    public function __construct(private GitRepoAccessResolver $accessResolver) {}

    public function preflight(string $url, ?Tenant $tenant = null): void
    {
        $resolvedUrl = $this->accessResolver->resolveCloneUrl($url, $tenant);

        $result = Process::timeout(config('audit.preflight_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run(['git', 'ls-remote', '--exit-code', $resolvedUrl, 'HEAD']);

        if (! $result->successful()) {
            throw AuditNotAnalyzableException::accessDenied(
                'Repository is not publicly accessible: '.$this->redactUrl($url)
            );
        }
    }

    public function clone(string $url, string $uuid, ?Tenant $tenant = null, ?string $branch = null): string
    {
        $resolvedUrl = $this->accessResolver->resolveCloneUrl($url, $tenant);
        $path = $this->workdirPath($uuid);
        File::ensureDirectoryExists(dirname($path));

        $command = ['git', 'clone', '--depth', (string) config('audit.clone_depth'), '--no-tags', '--single-branch'];
        if ($branch !== null) {
            $command[] = '--branch';
            $command[] = $branch;
        }
        $command[] = $resolvedUrl;
        $command[] = $path;

        $result = Process::timeout(config('audit.clone_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run($command);

        if (! $result->successful()) {
            $this->cleanup($uuid);

            throw new AuditNotAnalyzableException('Repository could not be cloned: '.$this->redactUrl($url));
        }

        $sizeMb = $this->directorySizeMb($path);
        if ($sizeMb > config('audit.max_repo_size_mb')) {
            $this->cleanup($uuid);

            throw new AuditNotAnalyzableException(
                sprintf('Repository too large for automated analysis (%d MB)', $sizeMb)
            );
        }

        return $path;
    }

    /**
     * The current SHA of a remote ref, without cloning. `null` on any
     * failure (unreachable host, private repo, no git connection, network
     * error) -- callers (ScheduledAuditChangeChecker) must treat that as
     * "unknown," never as "unchanged."
     */
    public function remoteHeadSha(string $url, ?string $branch = null, ?Tenant $tenant = null): ?string
    {
        try {
            $resolvedUrl = $this->accessResolver->resolveCloneUrl($url, $tenant);
        } catch (AuditNotAnalyzableException) {
            return null;
        }

        $ref = $branch !== null ? 'refs/heads/'.$branch : 'HEAD';

        $result = Process::timeout(config('audit.preflight_timeout'))
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run(['git', 'ls-remote', $resolvedUrl, $ref]);

        if (! $result->successful()) {
            return null;
        }

        $firstLine = trim(explode("\n", trim($result->output()))[0] ?? '');
        $sha = strtok($firstLine, "\t ");

        return $sha !== false && $sha !== '' ? $sha : null;
    }

    public function sizeKb(string $path): int
    {
        $result = Process::run(['du', '-sk', $path]);

        return (int) strtok(trim($result->output()), "\t ");
    }

    public function cleanup(string $uuid): void
    {
        File::deleteDirectory($this->workdirPath($uuid));
    }

    private function workdirPath(string $uuid): string
    {
        return rtrim(config('audit.workdir'), '/').'/'.$uuid;
    }

    private function directorySizeMb(string $path): int
    {
        $result = Process::run(['du', '-sm', $path]);

        return (int) strtok(trim($result->output()), "\t ");
    }

    private function redactUrl(string $url): string
    {
        return preg_replace('#//[^/@]+@#', '//', $url) ?? $url;
    }
}
```

- [ ] **Step 4: Update the four call sites**

In `app/Services/AuditReport/AuditPipeline.php`, replace lines 67-68:

```php
            $this->cloner->preflight($auditRequest->repo_url, tenant: $auditRequest->tenant);
            $path = $this->cloner->clone($auditRequest->repo_url, $auditRequest->uuid, tenant: $auditRequest->tenant, branch: $auditRequest->branch);
```

In `app/Services/AuditRequestService.php`, replace line 120:

```php
            $this->cloner->preflight($auditRequest->repo_url, tenant: $auditRequest->tenant);
```

(`$auditRequest->tenant` is `null` for a not-yet-claimed landing-page request, which is exactly the anonymous/public-only behavior `useToken: false` used to force — see Review Focus.)

In `app/Services/AuditReport/ScheduledAuditChangeChecker.php`, replace line 13:

```php
        $sha = $this->cloner->remoteHeadSha($schedule->repo_url, $schedule->branch, tenant: $schedule->tenant);
```

In `app/Filament/Dashboard/Pages/AuditReports.php`, replace line 225:

```php
            app(RepositoryCloner::class)->preflight($repoUrl, tenant: $tenant);
```

- [ ] **Step 5: Run the new tests**

Run: `docker compose exec laravel.test php artisan test --filter=RepositoryClonerTest`
Expected: PASS

- [ ] **Step 6: Run the full existing suite to catch signature-change fallout**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: Any failure here is a caller that still expects `bool $useToken` or an `AuditRequest`/`AuditSchedule` factory state that leaves `tenant_id` null where a connected tenant is now required (e.g. an `AuditPipelineTest` case that clones a `github.com` URL through `RunsAuditPipelineWithFakes` will now need `TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github'])` alongside the existing fixture setup, or must switch its fixture URL to `file://` like the sample-repo fixture already in use for local clones). Fix each one at the call site, not by reintroducing a shared-token fallback.

- [ ] **Step 7: Commit**

```bash
git add app/Services/AuditReport/RepositoryCloner.php \
        app/Services/AuditReport/AuditPipeline.php \
        app/Services/AuditRequestService.php \
        app/Services/AuditReport/ScheduledAuditChangeChecker.php \
        app/Filament/Dashboard/Pages/AuditReports.php \
        tests/Feature/Services/AuditReport/RepositoryClonerTest.php \
        tests/Support/RunsAuditPipelineWithFakes.php
git commit -m "fix(audit): clone with the requesting tenant's own connection, not a shared token"
```

---

### Task 8: Filament Dashboard "Git Connections" page + migrate branch lookup

**Files:**
- Create: `app/Filament/Dashboard/Pages/GitConnections.php`
- Create: `resources/views/filament/dashboard/pages/git-connections.blade.php`
- Modify: `app/Filament/Dashboard/Pages/AuditReports.php` (`loadBranches()`, imports)
- Test: `tests/Feature/Filament/Dashboard/GitConnectionsPageTest.php`
- Test: modify `tests/Feature/Filament/Dashboard/AuditReportsTest.php` (or wherever `loadBranches()` is currently covered) to fake the new provider path instead of `GitHubApiClient`

**Interfaces:**
- Consumes: `TenantGitConnection` (Task 1), `GitRepoAccessResolver` (Task 6), `GitProviderResolver` (Task 5).
- Produces: the `filament.dashboard.pages.git-connections` route (Filament's auto-registered page route, from this page's default slug), which Task 9's `OAuthController::callbackForGitConnection()` redirects to.

- [ ] **Step 1: Write the failing page test**

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Filament\Dashboard\Pages\GitConnections;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class GitConnectionsPageTest extends FeatureTest
{
    public function test_shows_connected_and_unconnected_providers(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github', 'account_login' => 'octocat']);

        $this->actingAs($user);

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->assertSee('octocat')
            ->assertSee('Not connected');
    }

    public function test_disconnect_removes_the_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github']);

        $this->actingAs($user);

        Livewire::test(GitConnections::class, ['tenant' => $tenant])
            ->call('disconnect', 'github');

        $this->assertDatabaseMissing('tenant_git_connections', ['tenant_id' => $tenant->id, 'provider' => 'github']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=GitConnectionsPageTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the `GitConnections` page**

```php
<?php

namespace App\Filament\Dashboard\Pages;

use App\Models\TenantGitConnection;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class GitConnections extends Page
{
    protected string $view = 'filament.dashboard.pages.git-connections';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Audits';

    protected static ?int $navigationSort = 2;

    /** @return array<string, array{label: string, connected: bool, account: ?string}> */
    public function connections(): array
    {
        $tenant = Filament::getTenant();
        $existing = TenantGitConnection::query()->where('tenant_id', $tenant->id)->get()->keyBy('provider');

        $providers = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket'];

        $result = [];
        foreach ($providers as $key => $label) {
            $connection = $existing->get($key);
            $result[$key] = [
                'label' => $label,
                'connected' => $connection !== null,
                'account' => $connection?->account_login,
            ];
        }

        return $result;
    }

    public function connectUrl(string $provider): string
    {
        return route('auth.oauth.redirect', [
            'provider' => $provider,
            'intent' => 'git_connection',
            'tenant_id' => Filament::getTenant()->id,
        ]);
    }

    public function disconnect(string $provider): void
    {
        TenantGitConnection::query()
            ->where('tenant_id', Filament::getTenant()->id)
            ->where('provider', $provider)
            ->delete();
    }
}
```

- [ ] **Step 4: Write the view**

```blade
<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($this->connections() as $provider => $connection)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $connection['label'] }}</h3>

                @if ($connection['connected'])
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Connected as :account', ['account' => $connection['account']]) }}</p>
                    <div class="mt-3 flex gap-2">
                        <a href="{{ $this->connectUrl($provider) }}" class="inline-flex items-center rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200">{{ __('Reconnect') }}</a>
                        <button type="button" wire:click="disconnect('{{ $provider }}')" wire:confirm="{{ __('Disconnect this account?') }}" class="inline-flex items-center rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-500">{{ __('Disconnect') }}</button>
                    </div>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Not connected') }}</p>
                    <a href="{{ $this->connectUrl($provider) }}" class="mt-3 inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-500">{{ __('Connect') }}</a>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=GitConnectionsPageTest`
Expected: PASS

- [ ] **Step 6: Migrate `AuditReports::loadBranches()` off `GitHubApiClient`**

In `app/Filament/Dashboard/Pages/AuditReports.php`, replace the `use App\Services\GitHub\GitHubApiClient;` import with:

```php
use App\Services\GitProviders\GitProviderResolver;
use App\Services\GitProviders\GitRepoAccessResolver;
```

Replace `loadBranches()` (lines 71-84):

```php
    public function loadBranches(string $repoUrl): void
    {
        $key = rtrim($repoUrl, '/');

        if (array_key_exists($key, $this->branchesByRepo)) {
            return;
        }

        if (! $this->userMayLookUpBranchesFor($key)) {
            return;
        }

        /** @var Tenant|null $tenant */
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return;
        }

        $provider = app(GitProviderResolver::class)->forUrl($repoUrl);
        $connection = $provider !== null ? app(GitRepoAccessResolver::class)->connectionFor($repoUrl, $tenant) : null;

        $this->branchesByRepo[$key] = $connection !== null ? $provider->listBranches($connection, $repoUrl) : [];
    }
```

Update the docblock above `userMayLookUpBranchesFor()` (lines 86-100): replace "runs on the shared AUDIT_GITHUB_TOKEN PAT, which is a read-only collaborator on every customer's private repos" with "runs on the calling tenant's own connected account, so it can only ever see repos that account can see" — the guard method body itself (lines 101-119) is unchanged, since restricting lookups to repos the tenant already has a claim to is still good defense in depth even though the underlying oracle problem is now structurally closed.

- [ ] **Step 7: Update the existing branch-lookup test**

Find the existing test(s) asserting `loadBranches()`/`GitHubApiClient` behavior (likely in `tests/Feature/Filament/Dashboard/AuditReportsTest.php`) and replace any `GitHubApiClient`/`config(['audit.github_token' => ...])` fake setup with a `TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github'])` plus `Http::fake([...])`, matching the pattern in `GitHubProviderTest`.

- [ ] **Step 8: Run the full Filament dashboard test file**

Run: `docker compose exec laravel.test php artisan test --filter=AuditReportsTest`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Filament/Dashboard/Pages/GitConnections.php \
        resources/views/filament/dashboard/pages/git-connections.blade.php \
        app/Filament/Dashboard/Pages/AuditReports.php \
        tests/Feature/Filament/Dashboard/GitConnectionsPageTest.php \
        tests/Feature/Filament/Dashboard/AuditReportsTest.php
git commit -m "feat(audit): add the workspace Git Connections page; branch lookup uses the tenant's own connection"
```

---

### Task 9: OAuth connect flow — `GitConnectionService` + `OAuthController`

**Files:**
- Create: `app/Services/GitProviders/GitConnectionService.php`
- Modify: `app/Http/Controllers/Auth/OAuthController.php`
- Modify: `.env.example` (add `GITLAB_CLIENT_ID`/`SECRET`, `BITBUCKET_CLIENT_ID`/`SECRET`)
- Test: `tests/Feature/Services/GitProviders/GitConnectionServiceTest.php`
- Test: `tests/Feature/Http/Controllers/Auth/GitConnectionOAuthTest.php`

**Interfaces:**
- Consumes: `TenantGitConnection` (Task 1), `GitProviderResolver` (Task 5), the `filament.dashboard.pages.git-connections` route (Task 8).
- Produces: `GitConnectionService::store(Tenant $tenant, User $user, string $provider, \Laravel\Socialite\Contracts\User $socialiteUser): TenantGitConnection`, used by `OAuthController` here.

- [ ] **Step 1: Write the failing `GitConnectionService` test**

```php
<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use App\Services\GitProviders\GitConnectionService;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Feature\FeatureTest;

class GitConnectionServiceTest extends FeatureTest
{
    public function test_stores_a_new_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $socialiteUser = (new SocialiteUser)->setRaw([])->map([
            'nickname' => 'octocat',
            'token' => 'ghp_new_token',
            'refreshToken' => 'refresh_token_value',
            'expiresIn' => 28800,
        ]);

        $connection = app(GitConnectionService::class)->store($tenant, $user, 'github', $socialiteUser);

        $this->assertSame($tenant->id, $connection->tenant_id);
        $this->assertSame('github', $connection->provider);
        $this->assertSame('octocat', $connection->account_login);
        $this->assertSame('ghp_new_token', $connection->access_token);
        $this->assertSame('refresh_token_value', $connection->refresh_token);
        $this->assertSame($user->id, $connection->connected_by_user_id);
        $this->assertNotNull($connection->expires_at);
    }

    public function test_reconnecting_replaces_the_existing_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $existing = TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github', 'access_token' => 'old_token']);
        $socialiteUser = (new SocialiteUser)->setRaw([])->map(['nickname' => 'octocat', 'token' => 'new_token']);

        $connection = app(GitConnectionService::class)->store($tenant, $user, 'github', $socialiteUser);

        $this->assertSame($existing->id, $connection->id);
        $this->assertSame('new_token', $connection->fresh()->access_token);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=GitConnectionServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `GitConnectionService`**

```php
<?php

namespace App\Services\GitProviders;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class GitConnectionService
{
    public function store(Tenant $tenant, User $user, string $provider, SocialiteUser $socialiteUser): TenantGitConnection
    {
        return TenantGitConnection::updateOrCreate(
            ['tenant_id' => $tenant->id, 'provider' => $provider],
            [
                'account_login' => $socialiteUser->getNickname() ?: $socialiteUser->getName(),
                'access_token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken,
                'expires_at' => $socialiteUser->expiresIn ? now()->addSeconds($socialiteUser->expiresIn) : null,
                'connected_by_user_id' => $user->id,
                'connected_at' => now(),
            ]
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=GitConnectionServiceTest`
Expected: PASS

- [ ] **Step 5: Write the failing controller test**

```php
<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Illuminate\Support\Facades\Socialite as SocialiteFacade;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Feature\FeatureTest;

class GitConnectionOAuthTest extends FeatureTest
{
    public function test_redirect_requires_authentication(): void
    {
        $response = $this->get('/auth/github/redirect?intent=git_connection&tenant_id=1');

        $response->assertForbidden();
    }

    public function test_redirect_rejects_a_tenant_the_user_does_not_belong_to(): void
    {
        $user = User::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $response = $this->actingAs($user)->get("/auth/github/redirect?intent=git_connection&tenant_id={$otherTenant->id}");

        $response->assertForbidden();
    }

    public function test_callback_stores_the_connection_for_the_authorized_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $socialiteUser = (new SocialiteUser)->setRaw([])->map(['nickname' => 'octocat', 'token' => 'ghp_callback_token']);
        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')
            ->with('github')
            ->andReturnSelf();
        \Laravel\Socialite\Facades\Socialite::shouldReceive('user')->andReturn($socialiteUser);

        $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/github/callback');

        $this->assertDatabaseHas('tenant_git_connections', [
            'tenant_id' => $tenant->id,
            'provider' => 'github',
            'account_login' => 'octocat',
        ]);
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=GitConnectionOAuthTest`
Expected: FAIL — `redirect()` doesn't branch on `intent` yet, so an unauthenticated request 404s (via `OauthLoginProvider::firstOrFail()`) instead of 403ing, and the callback test stores nothing.

- [ ] **Step 7: Modify `OAuthController`**

Add these imports alongside the existing ones:

```php
use App\Models\Tenant;
use App\Services\GitProviders\GitConnectionService;
use App\Services\GitProviders\GitProviderResolver;
```

Replace the `redirect()` method:

```php
    public function redirect(string $provider)
    {
        if (request()->query('intent') === 'git_connection') {
            return $this->redirectForGitConnection($provider);
        }

        $providerObj = OauthLoginProvider::where('provider_name', $provider)->firstOrFail();

        if (! $providerObj->enabled) {
            abort(404);
        }

        if (Auth::check()) {
            return redirect()->route('home');
        }

        Redirect::setIntendedUrl(url()->previous());

        return Socialite::driver($provider)->redirect();
    }

    private function redirectForGitConnection(string $provider)
    {
        abort_unless(Auth::check(), 403);

        $tenantId = request()->query('tenant_id');
        abort_unless(
            $tenantId && Auth::user()->tenants()->where('tenants.id', $tenantId)->exists(),
            403
        );

        session(['git_connection_tenant_id' => $tenantId]);

        $scopes = app(GitProviderResolver::class)->forProviderName($provider)->authorizationScopes();

        return Socialite::driver($provider)->scopes($scopes)->redirect();
    }
```

Replace the start of `callback()` (everything up to the existing `DB::transaction(...)` call stays, this just wraps it):

```php
    public function callback(string $provider)
    {
        if (session()->has('git_connection_tenant_id')) {
            return $this->callbackForGitConnection($provider);
        }

        $providerObj = OauthLoginProvider::where('provider_name', $provider)->firstOrFail();
        // ... rest of the existing method body is unchanged ...
```

Add the new private method at the end of the class, before the closing `}`:

```php
    private function callbackForGitConnection(string $provider)
    {
        $tenantId = session()->pull('git_connection_tenant_id');
        $tenant = Tenant::findOrFail($tenantId);

        abort_unless(Auth::user()->tenants()->where('tenants.id', $tenant->id)->exists(), 403);

        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (Exception) {
            return redirect()->route('filament.dashboard.pages.git-connections', ['tenant' => $tenant])
                ->withErrors(['git_connection' => __('Connection was cancelled or failed. Please try again.')]);
        }

        app(GitConnectionService::class)->store($tenant, Auth::user(), $provider, $oauthUser);

        return redirect()->route('filament.dashboard.pages.git-connections', ['tenant' => $tenant])
            ->with('status', __(':provider connected.', ['provider' => ucfirst($provider)]));
    }
```

(The exact route name is confirmed in Task 8, which creates the `GitConnections` page — Filament auto-registers a page's route as `filament.{panel}.pages.{slug}`, and the page's default slug is derived from its class name, `git-connections`.)

- [ ] **Step 8: Add the missing `.env.example` entries**

In `.env.example`, near the existing `GITHUB_CLIENT_ID`/`GITHUB_CLIENT_SECRET` lines, add:

```
GITLAB_CLIENT_ID=""
GITLAB_CLIENT_SECRET=""
BITBUCKET_CLIENT_ID=""
BITBUCKET_CLIENT_SECRET=""
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=GitConnectionOAuthTest`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Services/GitProviders/GitConnectionService.php \
        app/Http/Controllers/Auth/OAuthController.php \
        .env.example \
        tests/Feature/Services/GitProviders/GitConnectionServiceTest.php \
        tests/Feature/Http/Controllers/Auth/GitConnectionOAuthTest.php
git commit -m "feat(audit): let a tenant connect their own GitHub/GitLab/Bitbucket account"
```

---

### Task 10: Copy updates — connect/reconnect, not invite

Retiring the shared token's copy everywhere it appears, before Task 11 removes the config keys these strings reference.

**Files:**
- Modify: `app/Mail/Audit/AuditRepoAccessNeeded.php`
- Modify: `resources/views/emails/audit/access-needed.blade.php`
- Modify: `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php:279-280`
- Modify: `resources/views/filament/dashboard/pages/audit-reports.blade.php:19`
- Test: `tests/Feature/Mail/Audit/AuditRepoAccessNeededTest.php` (extend or create)

**Interfaces:**
- Consumes: `GitProviderResolver` (Task 5).

- [ ] **Step 1: Write/extend the failing mail test**

```php
public function test_access_failure_email_names_the_repos_provider(): void
{
    $auditRequest = AuditRequest::factory()->create(['repo_url' => 'https://gitlab.com/acme/app']);

    $rendered = (new AuditRepoAccessNeeded($auditRequest, accessProblem: true))->render();

    $this->assertStringContainsString('Connect your GitLab account', $rendered);
    $this->assertStringNotContainsString('read-only collaborator', $rendered);
    $this->assertStringNotContainsString('business day', $rendered);
}
```

(Add this to whatever test class already covers `AuditRepoAccessNeeded`, or create `tests/Feature/Mail/Audit/AuditRepoAccessNeededTest.php` if none exists — check first with `grep -rl AuditRepoAccessNeeded tests/`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=test_access_failure_email_names_the_repos_provider`
Expected: FAIL — current copy says "invite our review account", not "Connect your GitLab account".

- [ ] **Step 3: Update `AuditRepoAccessNeeded`**

Add `use App\Services\GitProviders\GitProviderResolver;` and replace the `content()`/add a private method:

```php
    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.access-needed',
            with: [
                'rerunUrl' => $this->rerunUrl(),
                'providerLabel' => $this->providerLabel(),
            ],
        );
    }

    private function providerLabel(): string
    {
        if ($this->auditRequest->repo_url === null) {
            return 'Git';
        }

        return app(GitProviderResolver::class)->forUrl($this->auditRequest->repo_url)?->label() ?? 'Git';
    }
```

- [ ] **Step 4: Update `access-needed.blade.php`**

Replace the two paragraphs inside the `@if ($auditRequest->repo_url && $accessProblem)` branch (current lines 18-26):

```blade
                <p style="margin: 16px 0 0; line-height: 24px">
                    <strong>{{ __('1. Connect your :provider account.', ['provider' => $providerLabel]) }}</strong>
                    {{ __('From your workspace, connect the :provider account that can read this repository — private repos need this before we can analyze them.', ['provider' => $providerLabel]) }}
                </p>
                <p style="margin: 16px 0 0; line-height: 24px">
                    <strong>{{ __('2. Then run a new audit.') }}</strong>
                    {{ __('Start it from your dashboard — the repository is already filled in. Connecting your account takes effect immediately, so there is no waiting.') }}
                </p>
```

Replace the closing "reply" paragraph in the same branch (current line 33):

```blade
                <p style="margin: 24px 0 0; line-height: 24px; font-size: 13px; color: #64748b;">
                    {{ __('On another git host, or need help another way? Just reply to this email.') }}
                </p>
```

Replace the `@else` branch's final paragraph (current line 51):

```blade
                <p style="margin: 16px 0 0; line-height: 24px">
                    {{ __('Reply to this email with a repository URL — for a private repo, connect the matching GitHub, GitLab, or Bitbucket account from your workspace first.') }}
                </p>
```

- [ ] **Step 5: Update `AuditRequestResource::statusDescription()`**

Replace lines 279-280:

```php
            AuditRequestStatus::AWAITING_ACCESS->value => __('Connect your GitHub, GitLab, or Bitbucket account from your workspace, then run a new audit from the Run an audit page.'),
            AuditRequestStatus::NOT_ANALYZABLE->value => __("We couldn't analyze this repository, so this audit is closed and you weren't charged for it. If it's private, connect the matching account from your workspace, then run a new audit."),
```

- [ ] **Step 6: Update the launch form's private-repo disclosure**

Replace line 19 of `resources/views/filament/dashboard/pages/audit-reports.blade.php`:

```blade
                            {{ __('Connect your GitHub, GitLab, or Bitbucket account from the Git Connections page, then paste the URL here — private repos need a connected account before they can be analyzed.') }}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=test_access_failure_email_names_the_repos_provider`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Mail/Audit/AuditRepoAccessNeeded.php \
        resources/views/emails/audit/access-needed.blade.php \
        app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php \
        resources/views/filament/dashboard/pages/audit-reports.blade.php \
        tests/Feature/Mail/Audit/AuditRepoAccessNeededTest.php
git commit -m "docs(audit): replace invite-account copy with connect-account copy"
```

---

### Task 11: Retire the shared token

Everything referencing `config('audit.github_token')`/`config('audit.github_account')` or `GitHubApiClient` was replaced in Tasks 2-10. This task deletes what's left.

**Files:**
- Delete: `app/Services/GitHub/GitHubApiClient.php`
- Delete: `tests/Feature/Services/GitHub/GitHubApiClientTest.php`
- Modify: `config/audit.php` (remove `github_account`/`github_token`)
- Modify: `app/Support/Sentry/TokenScrubber.php` (remove the dead config-token-specific scrub)

**Interfaces:** none — this task only removes dead code.

- [ ] **Step 1: Confirm nothing still references the retired pieces**

Run: `grep -rn "GitHubApiClient\|audit.github_token\|audit.github_account" app/ tests/ resources/ config/ --include="*.php" --include="*.blade.php"`
Expected: no output. If anything is listed, it's a call site Tasks 2-10 missed — fix it before continuing.

- [ ] **Step 2: Delete `GitHubApiClient` and its test**

```bash
git rm app/Services/GitHub/GitHubApiClient.php tests/Feature/Services/GitHub/GitHubApiClientTest.php
```

- [ ] **Step 3: Remove the two config keys**

In `config/audit.php`, delete these two lines:

```php
    'github_account' => env('AUDIT_GITHUB_ACCOUNT', 'flexpick'),
    'github_token' => env('AUDIT_GITHUB_TOKEN'),
```

- [ ] **Step 4: Clean up `TokenScrubber`**

In `app/Support/Sentry/TokenScrubber.php`, `scrub()` currently does two things: a generic regex for any embedded `user:token@` credential pair (still needed — every provider's `cloneUrl()` produces this shape), and a second, now-dead check specifically for the retired `config('audit.github_token')` value. Replace the method body:

```php
    private static function scrub(string $value): string
    {
        // Any embedded credential pair, whether or not it belongs to a
        // connected tenant's own token.
        return (string) preg_replace(
            '#https://[^:/@\s]+:[^@\s]+@#i',
            'https://'.self::REPLACEMENT.'@',
            $value
        );
    }
```

- [ ] **Step 5: Run the full suite**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: PASS, same count as before minus the deleted `GitHubApiClientTest` cases.

- [ ] **Step 6: Format and static-analyze**

Run: `docker compose exec laravel.test vendor/bin/pint --format agent && docker compose exec laravel.test vendor/bin/pint --test && docker compose exec laravel.test vendor/bin/phpstan analyse`
Expected: Pint clean; PHPStan shows only the pre-existing `AuditGroupDeltaService` baseline error (per project memory), nothing new.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore(audit): retire the shared GitHub token and GitHubApiClient"
```

---

## Self-Review

**Spec coverage:**
- `GitProvider` interface, per-provider implementations, resolver — Tasks 2-5. ✓
- `tenant_git_connections` encrypted storage — Task 1. ✓
- Connection flow (OAuth routes, retiring the shared-token model) — Tasks 9, 11. ✓
- `RepositoryCloner` using tenant connections — Task 7. ✓
- Landing page public-repo-only / dashboard preflight-before-charge / mid-pipeline reconnect copy — Tasks 7 (mechanism) and 10 (copy). ✓
- Spec's Non-goals (self-hosted providers, auto-accept invites, resume-in-place) — deliberately not built; nothing in this plan does them. ✓

**Placeholder scan:** no TBD/TODO; every step has real, complete code. The one deliberately open-ended step (Task 7, Step 6: "run the full suite and fix fallout") names exactly what kind of failure to expect and how to fix it, because the fallout depends on which existing tests construct `AuditRequest`/`AuditSchedule` fixtures without a tenant — that set can't be enumerated without running the suite.

**Type consistency:** `RepositoryCloner::preflight/clone/remoteHeadSha` all take `?Tenant $tenant = null` consistently across Tasks 7's rewrite and all four call-site updates. `GitProvider::listBranches`/`cloneUrl` signatures match across the interface (Task 2) and all three implementations (Tasks 2-4) and the one caller outside `GitRepoAccessResolver` (Task 8's `loadBranches()`). `GitConnectionService::store()`'s parameter order (`Tenant, User, string, SocialiteUser`) matches its one call site in `OAuthController::callbackForGitConnection()` (Task 9).

**Review Focus:** all five items above have an owning test — cross-tenant isolation (Task 6 `test_tenant_a_connection_is_never_used_for_tenant_b`, Task 7 `test_preflight_does_not_leak_another_tenants_connection`), `remoteHeadSha()` no-throw (Task 7 `test_remote_head_sha_returns_null_instead_of_throwing_when_no_connection_exists`), landing-page anonymous passthrough (Task 6 `test_returns_the_url_unchanged_when_no_tenant_is_given`, Task 7 `test_preflight_succeeds_against_a_public_local_repo_with_no_tenant`), encrypted-at-rest (Task 1 `test_access_token_is_encrypted_at_rest`), and connect-flow tenant-membership authorization (Task 9 `test_redirect_rejects_a_tenant_the_user_does_not_belong_to`, `test_redirect_requires_authentication`).

---

## Execution Handoff

Plan complete and saved to `backend/docs/superpowers/plans/2026-09-27-tenant-git-oauth-access.md`. Please review the plan. Which execution approach would you prefer?

- **Subagent-driven** — A fresh subagent implements each task and a fresh reviewer checks it before the next one starts, then a whole-branch review at the end. Most thorough; costs a fresh context per task and per review.
- **Native** — I implement every task myself in this session, the way this harness runs work, then one fresh reviewer on the most capable model checks the whole branch. Cheapest and fastest; no independent review until the end.

For this plan I recommend **subagent-driven**, because Task 7 (wiring the fix into `RepositoryCloner`) is the single highest-stakes task — it's the actual security fix, touches four call sites across the codebase, and a mistake there (e.g. silently reintroducing a fallback, or breaking `remoteHeadSha()`'s no-throw contract) would be exactly the kind of thing worth an independent fresh-context review catching before Tasks 8-11 build on top of it. Does the plan capture what you want, and which approach should we use?
