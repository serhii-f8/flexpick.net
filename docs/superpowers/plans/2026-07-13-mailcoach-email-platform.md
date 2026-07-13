# Mailcoach Email Platform Implementation Plan (Workstream B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run standalone Mailcoach as a third app, route all 10 audit mailables through it with a `Mail::` fallback, and give admins a native Filament "Audit Emails" resource with statuses, confirmed resend, and status refresh.

**Architecture:** Mailcoach 8.1 (its own Laravel 12 app in `mailcoach/`, reusing the Sail image, MySQL, Redis, Mailpit). The backend talks to it through a thin `MailcoachClient` (Laravel `Http`, faked in tests) and a single `AuditMailer` entry point that renders each mailable, records a row in `audit_email_logs` (including the rendered subject/body so anything can be resent), and falls back to direct `Mail::` when Mailcoach is unconfigured or unreachable. The Filament resource reads only the local table; delivery statuses sync from the Mailcoach API on demand.

**Tech Stack:** Mailcoach 8.1 standalone (from `backend/spatie-Mailcoach-8.1.0.zip`), Laravel `Http` client (not the SDK — same REST endpoints, easier to fake, no extra dependency; documented deviation from the spec's SDK mention), Filament 5 admin resource.

**Spec:** `docs/superpowers/specs/2026-07-13-admin-audit-management-design.md` (Workstream B). Workstream A is a separate plan; its "email failures" widget starts reading real numbers once Task 2 here lands.

## Global Constraints

- Backend commands: `docker compose exec laravel.test <cmd>` from the repo root; tests `php artisan test --compact --filter=<Name>`; Pint before committing.
- **Never commit:** `mailcoach/auth.json`, `mailcoach/.env`, `mailcoach/vendor`, the Spatie license key (provided out-of-band by the project owner — it must not appear in any committed file, including this plan's executed artifacts), or the `spatie-*.zip` archives (already gitignored).
- Mailcoach endpoints used (Mailcoach v8 REST API): `POST {endpoint}/transactional-mails/send`, `GET {endpoint}/transactional-mails`, `POST {endpoint}/transactional-mails/{uuid}/resend`, bearer-token auth. Tests define this contract via `Http::fake`; if the live API differs in field names, adjustments are localized to `MailcoachClient` (verified in the Task 6 manual pass).
- When `services.mailcoach.endpoint` is empty (all tests, fresh installs), `AuditMailer` must skip HTTP entirely and use `Mail::` directly — every pre-existing `Mail::fake` test must stay green.
- Non-audit mail (orders, invitations, subscriptions) is untouched.

---

### Task 1: Mailcoach service scaffold (infra)

**Files:**
- Create: `mailcoach/` (unpacked from `backend/spatie-Mailcoach-8.1.0.zip`)
- Create: `mailcoach/auth.json.example`
- Modify: `mailcoach/.gitignore` (append `auth.json`)
- Modify: `compose.yml` (repo root — add `mailcoach` and `mailcoach.horizon` services)
- Create: `backend/docker/mysql/create-mailcoach-database.sh`
- Modify: `backend/compose.yml` (mount the new init script)
- Create: `mailcoach/README.flexpick.md` (setup notes)

**Interfaces:**
- Consumes: existing `sail-8.4/app` image, `mysql`/`redis`/`mailpit` services, `WWWUSER`/`WWWGROUP`.
- Produces: Mailcoach UI at `http://localhost:8090`, API at `http://mailcoach/api` (in-network) — Task 3's client and Task 6's manual pass target it. This task has no automated tests (infra); verification is command-based.

- [ ] **Step 1: Unpack the app**

Run from the repo root:

```bash
unzip -q backend/spatie-Mailcoach-8.1.0.zip -d .
mv spatie-Mailcoach-* mailcoach
echo "auth.json" >> mailcoach/.gitignore
```

- [ ] **Step 2: Create the composer auth template and setup notes**

Create `mailcoach/auth.json.example`:

```json
{
    "http-basic": {
        "satis.spatie.be": {
            "username": "<your-spatie-account-email>",
            "password": "<your-mailcoach-license-key>"
        }
    }
}
```

Create `mailcoach/README.flexpick.md`:

```markdown
# Mailcoach service (FlexPick)

Standalone Mailcoach app used for audit transactional email tracking.

## One-time setup

1. `cp auth.json.example auth.json` and fill in your Spatie account email and
   Mailcoach license key (provided by the project owner — never commit auth.json).
2. `cp .env.example .env`, then set:
   - `APP_URL=http://localhost:8090`
   - `DB_HOST=mysql`, `DB_DATABASE=mailcoach`, `DB_USERNAME=sail`, `DB_PASSWORD=password` (match backend/.env DB credentials)
   - `REDIS_HOST=redis`, `REDIS_DB=2`, `REDIS_CACHE_DB=3`
   - `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`
3. From the repo root:
   - `docker compose up -d mailcoach`
   - `docker compose exec mysql bash -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS mailcoach; GRANT ALL PRIVILEGES ON mailcoach.* TO '"'"'$MYSQL_USER'"'"'@'"'"'%'"'"';"'`
     (only needed on an existing MySQL volume; fresh volumes run the init script)
   - `docker compose exec mailcoach composer install`
   - `docker compose exec mailcoach php artisan key:generate`
   - `docker compose exec mailcoach php artisan migrate`
   - `docker compose exec mailcoach php artisan tinker --execute="\App\Models\User::create(['name' => 'Admin', 'email' => 'admin@flexpick.net', 'password' => bcrypt('password')]);"`
   - `docker compose up -d mailcoach.horizon`
4. Log in at http://localhost:8090, create an API token (Settings → API tokens),
   and put it into `backend/.env` as `MAILCOACH_API_TOKEN` with
   `MAILCOACH_ENDPOINT=http://mailcoach/api` and `MAILCOACH_UI_URL=http://localhost:8090`.
```

- [ ] **Step 3: Add the compose services and DB init script**

Append to the root `compose.yml` under `services:` (sibling of `frontend`):

```yaml
    mailcoach:
        image: sail-8.4/app
        environment:
            WWWUSER: '${WWWUSER:-1000}'
            LARAVEL_SAIL: 1
        volumes:
            - './mailcoach:/var/www/html'
        ports:
            - '8090:80'
        restart: unless-stopped
        networks:
            - sail
        depends_on:
            - mysql
            - redis
            - mailpit
    mailcoach.horizon:
        image: sail-8.4/app
        command: php artisan horizon
        environment:
            WWWUSER: '${WWWUSER:-1000}'
            LARAVEL_SAIL: 1
        volumes:
            - './mailcoach:/var/www/html'
        restart: unless-stopped
        networks:
            - sail
        depends_on:
            - mailcoach
```

(The `sail` network and the shared services come from the included `backend/compose.yml`; both new services reuse the already-built backend image, so no new Dockerfile.)

Create `backend/docker/mysql/create-mailcoach-database.sh`:

```bash
#!/usr/bin/env bash

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS mailcoach;
    GRANT ALL PRIVILEGES ON \`mailcoach\`.* TO '$MYSQL_USER'@'%';
EOSQL
```

In `backend/compose.yml`, add to the `mysql` service's `volumes` list:

```yaml
            - './docker/mysql/create-mailcoach-database.sh:/docker-entrypoint-initdb.d/11-create-mailcoach-database.sh'
```

- [ ] **Step 4: Boot and verify**

Follow `mailcoach/README.flexpick.md` steps 1–3 (auth.json needs the real license key from the project owner), then:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8090/login
```

Expected: `200`. Log in with the seeded admin, create the API token, fill `backend/.env` (`MAILCOACH_ENDPOINT`, `MAILCOACH_API_TOKEN`, `MAILCOACH_UI_URL`).

- [ ] **Step 5: Commit (scaffold only — vendor/env/auth stay untracked)**

```bash
git add mailcoach compose.yml backend/docker/mysql/create-mailcoach-database.sh backend/compose.yml
git status   # verify NO mailcoach/vendor, mailcoach/.env, or mailcoach/auth.json is staged
git commit -m "feat(mailcoach): standalone Mailcoach service scaffold and docker wiring"
```

---

### Task 2: audit_email_logs table and model

**Files:**
- Create: `backend/database/migrations/2026_07_13_110000_create_audit_email_logs_table.php`
- Create: `backend/app/Models/AuditEmailLog.php`
- Create: `backend/database/factories/AuditEmailLogFactory.php`
- Test: `backend/tests/Feature/Models/AuditEmailLogTest.php`

**Interfaces:**
- Consumes: `audit_requests` table.
- Produces: `AuditEmailLog` model — columns `audit_request_id` (nullable FK), `mailable` (class basename), `recipient`, `subject`, `body` (rendered HTML, enables resend of any row), `mailcoach_uuid` (nullable), `status` (`pending|sent|delivered|bounced|failed`), `attempts` (int), `last_error` (nullable), `sent_at` (nullable); relation `auditRequest()`. Tasks 4–5 create/read these rows; Workstream A's "email failures" stat counts `status = 'failed'`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Models/AuditEmailLogTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditEmailLogTest extends FeatureTest
{
    public function test_log_row_persists_and_links_to_audit_request(): void
    {
        $request = AuditRequest::factory()->create();

        $log = AuditEmailLog::factory()->create([
            'audit_request_id' => $request->id,
            'mailable' => 'AuditReportReady',
            'recipient' => 'client@example.com',
            'subject' => 'Your report',
            'body' => '<p>Hello</p>',
            'status' => 'sent',
            'attempts' => 1,
        ]);

        $this->assertSame($request->id, $log->fresh()->auditRequest->id);
        $this->assertSame('sent', $log->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditEmailLogTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement migration, model, factory**

Create `backend/database/migrations/2026_07_13_110000_create_audit_email_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mailable');
            $table->string('recipient');
            $table->string('subject');
            $table->longText('body');
            $table->string('mailcoach_uuid')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_email_logs');
    }
};
```

Create `backend/app/Models/AuditEmailLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEmailLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'audit_request_id', 'mailable', 'recipient', 'subject', 'body',
        'mailcoach_uuid', 'status', 'attempts', 'last_error', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<AuditRequest, $this>
     */
    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }
}
```

Create `backend/database/factories/AuditEmailLogFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AuditEmailLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mailable' => 'AuditReportReady',
            'recipient' => $this->faker->safeEmail(),
            'subject' => 'Your codebase health report is ready',
            'body' => '<p>Report body</p>',
            'status' => 'sent',
            'attempts' => 1,
            'sent_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed', 'last_error' => 'Connection refused']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditEmailLogTest`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint database/migrations/2026_07_13_110000_create_audit_email_logs_table.php app/Models/AuditEmailLog.php database/factories/AuditEmailLogFactory.php tests/Feature/Models/AuditEmailLogTest.php
git add backend/database/migrations/2026_07_13_110000_create_audit_email_logs_table.php backend/app/Models/AuditEmailLog.php backend/database/factories/AuditEmailLogFactory.php backend/tests/Feature/Models/AuditEmailLogTest.php
git commit -m "feat(backend): audit email log table and model"
```

---

### Task 3: MailcoachClient

**Files:**
- Create: `backend/app/Services/AuditMail/MailcoachClient.php`
- Create: `backend/app/Exceptions/MailcoachUnavailableException.php`
- Modify: `backend/config/services.php` (mailcoach block)
- Modify: `backend/.env.example` (three vars)
- Test: `backend/tests/Feature/Services/MailcoachClientTest.php`

**Interfaces:**
- Consumes: `services.mailcoach.endpoint` / `api_token` / `ui_url` config.
- Produces:
  - `MailcoachClient::isConfigured(): bool`
  - `MailcoachClient::sendTransactional(string $to, string $subject, string $html): ?string` — returns the transactional mail UUID when the API provides one, null otherwise; throws `MailcoachUnavailableException` on connection errors or non-2xx.
  - `MailcoachClient::resend(string $uuid): void` — throws `MailcoachUnavailableException` on failure.
  - `MailcoachClient::recentTransactionalMails(): array` — raw item arrays.
  Task 4 and 5 depend on these exact signatures.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Services/MailcoachClientTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Exceptions\MailcoachUnavailableException;
use App\Services\AuditMail\MailcoachClient;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class MailcoachClientTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'test-token');
    }

    public function test_unconfigured_without_endpoint(): void
    {
        config()->set('services.mailcoach.endpoint', null);

        $this->assertFalse(app(MailcoachClient::class)->isConfigured());
    }

    public function test_send_transactional_returns_uuid_when_present(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response(['data' => ['uuid' => 'tm-123']], 200)]);

        $uuid = app(MailcoachClient::class)->sendTransactional('a@b.com', 'Subject', '<p>Hi</p>');

        $this->assertSame('tm-123', $uuid);
        Http::assertSent(function ($request) {
            return $request->url() === 'http://mailcoach/api/transactional-mails/send'
                && $request['to'] === 'a@b.com'
                && $request['subject'] === 'Subject'
                && $request['html'] === '<p>Hi</p>'
                && $request['store'] === true
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_send_transactional_handles_bodyless_success(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response(null, 204)]);

        $this->assertNull(app(MailcoachClient::class)->sendTransactional('a@b.com', 'S', '<p></p>'));
    }

    public function test_send_transactional_throws_on_server_error(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response('nope', 500)]);

        $this->expectException(MailcoachUnavailableException::class);
        app(MailcoachClient::class)->sendTransactional('a@b.com', 'S', '<p></p>');
    }

    public function test_resend_posts_to_uuid_endpoint(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails/tm-9/resend' => Http::response(null, 200)]);

        app(MailcoachClient::class)->resend('tm-9');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transactional-mails/tm-9/resend'));
    }

    public function test_recent_transactional_mails_returns_items(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails*' => Http::response(['data' => [['uuid' => 'tm-1']]], 200)]);

        $items = app(MailcoachClient::class)->recentTransactionalMails();

        $this->assertSame('tm-1', $items[0]['uuid']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=MailcoachClientTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement client, exception, config**

Create `backend/app/Exceptions/MailcoachUnavailableException.php`:

```php
<?php

namespace App\Exceptions;

use Exception;

class MailcoachUnavailableException extends Exception {}
```

Create `backend/app/Services/AuditMail/MailcoachClient.php`:

```php
<?php

namespace App\Services\AuditMail;

use App\Exceptions\MailcoachUnavailableException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class MailcoachClient
{
    public function isConfigured(): bool
    {
        return (string) config('services.mailcoach.endpoint') !== ''
            && (string) config('services.mailcoach.api_token') !== '';
    }

    public function sendTransactional(string $to, string $subject, string $html): ?string
    {
        $response = $this->request('post', '/transactional-mails/send', [
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'from' => (string) config('mail.from.address'),
            'store' => true,
        ]);

        return $response->json('data.uuid');
    }

    public function resend(string $uuid): void
    {
        $this->request('post', "/transactional-mails/{$uuid}/resend");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentTransactionalMails(): array
    {
        return $this->request('get', '/transactional-mails')->json('data') ?? [];
    }

    private function request(string $method, string $path, array $payload = []): Response
    {
        $endpoint = rtrim((string) config('services.mailcoach.endpoint'), '/');

        try {
            $response = Http::withToken((string) config('services.mailcoach.api_token'))
                ->acceptJson()
                ->timeout(10)
                ->{$method}($endpoint.$path, $payload);
        } catch (Throwable $e) {
            throw new MailcoachUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new MailcoachUnavailableException("Mailcoach API {$path} responded {$response->status()}");
        }

        return $response;
    }
}
```

In `backend/config/services.php`, add:

```php
    'mailcoach' => [
        'endpoint' => env('MAILCOACH_ENDPOINT'),
        'api_token' => env('MAILCOACH_API_TOKEN'),
        'ui_url' => env('MAILCOACH_UI_URL'),
    ],
```

In `backend/.env.example`, add:

```dotenv
MAILCOACH_ENDPOINT=http://mailcoach/api
MAILCOACH_API_TOKEN=
MAILCOACH_UI_URL=http://localhost:8090
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=MailcoachClientTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Services/AuditMail/MailcoachClient.php app/Exceptions/MailcoachUnavailableException.php config/services.php tests/Feature/Services/MailcoachClientTest.php
git add backend/app/Services/AuditMail/MailcoachClient.php backend/app/Exceptions/MailcoachUnavailableException.php backend/config/services.php backend/.env.example backend/tests/Feature/Services/MailcoachClientTest.php
git commit -m "feat(backend): Mailcoach transactional API client"
```

---

### Task 4: AuditMailer and call-site swap

**Files:**
- Create: `backend/app/Services/AuditMail/AuditMailer.php`
- Modify: `backend/app/Services/AuditRequestService.php` (6 `Mail::to` sites, constructor)
- Modify: `backend/app/Services/AuditReport/AuditReportService.php` (2 `Mail::to` sites, constructor)
- Test: `backend/tests/Feature/Services/AuditMailerTest.php`

**Interfaces:**
- Consumes: `MailcoachClient` (Task 3), `AuditEmailLog` (Task 2).
- Produces: `AuditMailer::send(Mailable $mailable, string $recipient, ?AuditRequest $auditRequest = null): AuditEmailLog`. Rendered subject comes from `$mailable->envelope()->subject`, body from `$mailable->render()`. Behavior contract: Mailcoach configured + reachable → API send, log `sent` with uuid; unconfigured → direct `Mail::` send, log `sent`, uuid null; configured but failing → `Mail::` fallback, log `sent`, uuid null, `last_error` records the API failure; both paths failing → log `failed` and rethrow.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Services/AuditMailerTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use App\Services\AuditMail\AuditMailer;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class AuditMailerTest extends FeatureTest
{
    public function test_sends_via_mailcoach_when_configured(): void
    {
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'token');
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response(['data' => ['uuid' => 'tm-42']], 200)]);
        Mail::fake();

        $request = AuditRequest::factory()->create(['email' => 'client@example.com']);
        $mailable = new AuditRequestReceived($request, 'https://status.example');

        $log = app(AuditMailer::class)->send($mailable, $request->email, $request);

        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->status);
        $this->assertSame('tm-42', $log->mailcoach_uuid);
        $this->assertSame('AuditRequestReceived', $log->mailable);
        $this->assertSame(1, $log->attempts);
        $this->assertNotSame('', $log->body);
        Mail::assertNothingOutgoing();
    }

    public function test_falls_back_to_mail_when_mailcoach_unreachable(): void
    {
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'token');
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response('down', 500)]);
        Mail::fake();

        $request = AuditRequest::factory()->create();

        $log = app(AuditMailer::class)->send(new AuditRequestReceived($request, 'https://s'), $request->email, $request);

        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->status);
        $this->assertNull($log->mailcoach_uuid);
        $this->assertStringContainsString('500', (string) $log->last_error);
        Mail::assertQueued(AuditRequestReceived::class);
    }

    public function test_sends_directly_when_unconfigured_without_http_calls(): void
    {
        config()->set('services.mailcoach.endpoint', null);
        Http::fake();
        Mail::fake();

        $request = AuditRequest::factory()->create();

        $log = app(AuditMailer::class)->send(new AuditRequestReceived($request, 'https://s'), $request->email, $request);

        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->status);
        Http::assertNothingSent();
        Mail::assertQueued(AuditRequestReceived::class);
    }

    public function test_call_sites_create_log_rows(): void
    {
        Mail::fake();

        $request = AuditRequest::factory()->create();
        app(AuditRequestService::class)->sendVerificationEmail($request);

        $this->assertSame(1, AuditEmailLog::where('audit_request_id', $request->id)->where('mailable', 'AuditVerifyEmail')->count());
    }
}
```

(If `sendVerificationEmail` is named differently in `AuditRequestService` — the method at line ~56 sending `AuditVerifyEmail` — use its actual name in the last test.)

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditMailerTest`
Expected: FAIL — `AuditMailer` not found.

- [ ] **Step 3: Implement AuditMailer**

Create `backend/app/Services/AuditMail/AuditMailer.php`:

```php
<?php

namespace App\Services\AuditMail;

use App\Exceptions\MailcoachUnavailableException;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AuditMailer
{
    public function __construct(
        private MailcoachClient $mailcoach,
    ) {}

    public function send(Mailable $mailable, string $recipient, ?AuditRequest $auditRequest = null): AuditEmailLog
    {
        $log = AuditEmailLog::create([
            'audit_request_id' => $auditRequest?->id,
            'mailable' => class_basename($mailable),
            'recipient' => $recipient,
            'subject' => (string) $mailable->envelope()->subject,
            'body' => $mailable->render(),
            'status' => AuditEmailLog::STATUS_PENDING,
            'attempts' => 1,
            'sent_at' => now(),
        ]);

        if ($this->mailcoach->isConfigured()) {
            try {
                $uuid = $this->mailcoach->sendTransactional($recipient, $log->subject, $log->body);
                $log->update(['status' => AuditEmailLog::STATUS_SENT, 'mailcoach_uuid' => $uuid]);

                return $log;
            } catch (MailcoachUnavailableException $e) {
                $log->update(['last_error' => 'Mailcoach unavailable, fell back to direct send: '.$e->getMessage()]);
            }
        }

        try {
            Mail::to($recipient)->send($mailable);
            $log->update(['status' => AuditEmailLog::STATUS_SENT]);
        } catch (Throwable $e) {
            $log->update(['status' => AuditEmailLog::STATUS_FAILED, 'last_error' => $e->getMessage()]);

            throw $e;
        }

        return $log;
    }
}
```

- [ ] **Step 4: Swap the call sites**

In `backend/app/Services/AuditRequestService.php`: add `AuditMailer` to the constructor (promoted property `private AuditMailer $auditMailer`), then replace each of the six `Mail::to($X)->send(new Y(...))` calls (lines ~56, ~97, ~106, ~116, ~127, ~139) with:

```php
$this->auditMailer->send(new Y(...), $X, $auditRequest);
```

and the admin-notification site (line ~148) with:

```php
$this->auditMailer->send(new NewAuditRequestAdminNotification($auditRequest), $adminEmail, $auditRequest);
```

Remove the now-unused `Mail` facade import if no other usage remains.

In `backend/app/Services/AuditReport/AuditReportService.php`: add `AuditMailer` to the constructor, replace the two `Mail::to($report->auditRequest->email)->send(...)` calls (in `unlock()` ~line 73 and `send()` ~line 79) with:

```php
$this->auditMailer->send(new AuditReportUnlocked($report, $this->signedUrl($report)), $report->auditRequest->email, $report->auditRequest);
```

```php
$this->auditMailer->send(new AuditReportReady($report, $this->signedUrl($report), $this->deltaService->deltasFor($report)), $report->auditRequest->email, $report->auditRequest);
```

- [ ] **Step 5: Run the mailer test and every suite that asserts audit mail**

Run: `docker compose exec laravel.test php artisan test --compact --filter="AuditMailerTest|AuditPipelineTest|AuditVerificationTest|AuditRequestStatusTest"`
Expected: PASS — with Mailcoach unconfigured in testing, `AuditMailer` delegates to `Mail::`, so all existing `Mail::fake` assertions hold.

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: full suite PASS (this task touches high-traffic services; run everything before committing).

- [ ] **Step 6: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Services/AuditMail/AuditMailer.php app/Services/AuditRequestService.php app/Services/AuditReport/AuditReportService.php tests/Feature/Services/AuditMailerTest.php
git add backend/app/Services/AuditMail backend/app/Services/AuditRequestService.php backend/app/Services/AuditReport/AuditReportService.php backend/tests/Feature/Services/AuditMailerTest.php
git commit -m "feat(backend): route audit email through Mailcoach with direct-send fallback and logging"
```

---

### Task 5: Audit Emails admin resource (resend, refresh, Mailcoach link)

**Files:**
- Create: `backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php`
- Create: `backend/app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php`
- Modify: `backend/app/Providers/Filament/AdminPanelProvider.php` (user-menu link)
- Test: `backend/tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php`

**Interfaces:**
- Consumes: `AuditEmailLog` (Task 2), `MailcoachClient::resend/recentTransactionalMails/isConfigured` (Task 3), `AuditMailer` behavior contract (Task 4), `services.mailcoach.ui_url`.
- Produces: admin resource `audit-email-logs` (list only). No downstream dependents.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Filament\Admin\Resources\AuditEmailLogs\Pages\ListAuditEmailLogs;
use App\Models\AuditEmailLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditEmailLogResourceTest extends FeatureTest
{
    public function test_list_renders_log_rows(): void
    {
        $admin = $this->createAdminUser();
        AuditEmailLog::factory()->create(['recipient' => 'render-check@example.com']);
        AuditEmailLog::factory()->failed()->create(['recipient' => 'broken@example.com']);

        $this->actingAs($admin);

        $this->get(AuditEmailLogResource::getUrl('index', panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('render-check@example.com')
            ->assertSee('broken@example.com');
    }

    public function test_resend_uses_mailcoach_api_for_uuid_rows(): void
    {
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'token');
        Http::fake(['http://mailcoach/api/transactional-mails/tm-7/resend' => Http::response(null, 200)]);

        $admin = $this->createAdminUser();
        $log = AuditEmailLog::factory()->create(['mailcoach_uuid' => 'tm-7', 'attempts' => 1]);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->callTableAction('resend', $log);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transactional-mails/tm-7/resend'));
        $this->assertSame(2, $log->fresh()->attempts);
    }

    public function test_resend_falls_back_to_direct_mail_for_rows_without_uuid(): void
    {
        config()->set('services.mailcoach.endpoint', null);
        Mail::fake();

        $admin = $this->createAdminUser();
        $log = AuditEmailLog::factory()->create(['mailcoach_uuid' => null, 'attempts' => 1, 'subject' => 'Re-sub', 'body' => '<p>again</p>']);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->callTableAction('resend', $log);

        Mail::assertSent(fn (\Illuminate\Mail\Mailable $mail) => true);
        $this->assertSame(2, $log->fresh()->attempts);
    }

    public function test_refresh_statuses_maps_api_delivery_data(): void
    {
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'token');

        $log = AuditEmailLog::factory()->create(['mailcoach_uuid' => 'tm-1', 'status' => AuditEmailLog::STATUS_SENT]);
        Http::fake(['http://mailcoach/api/transactional-mails*' => Http::response(['data' => [
            ['uuid' => 'tm-1', 'delivered_at' => now()->toIso8601String()],
        ]], 200)]);

        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->callAction('refreshStatuses');

        $this->assertSame(AuditEmailLog::STATUS_DELIVERED, $log->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditEmailLogResourceTest`
Expected: FAIL — resource class not found.

- [ ] **Step 3: Implement the resource**

Create `backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php`:

```php
<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs;

use App\Filament\Admin\Resources\AuditEmailLogs\Pages\ListAuditEmailLogs;
use App\Models\AuditEmailLog;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditEmailLogResource extends Resource
{
    protected static ?string $model = AuditEmailLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    public static function getNavigationGroup(): ?string
    {
        return __('Audits');
    }

    public static function getModelLabel(): string
    {
        return __('Audit Email');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('recipient')->searchable(),
                TextColumn::make('mailable')->label(__('Notification'))->badge()->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AuditEmailLog::STATUS_DELIVERED => 'success',
                        AuditEmailLog::STATUS_SENT => 'info',
                        AuditEmailLog::STATUS_PENDING => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('attempts'),
                TextColumn::make('sent_at')->label(__('Last attempt'))->dateTime(config('app.datetime_format'))->sortable(),
                TextColumn::make('last_error')->limit(60)->placeholder('—')->tooltip(fn (AuditEmailLog $record): ?string => $record->last_error),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    AuditEmailLog::STATUS_PENDING => __('Pending'),
                    AuditEmailLog::STATUS_SENT => __('Sent'),
                    AuditEmailLog::STATUS_DELIVERED => __('Delivered'),
                    AuditEmailLog::STATUS_BOUNCED => __('Bounced'),
                    AuditEmailLog::STATUS_FAILED => __('Failed'),
                ]),
                SelectFilter::make('mailable')
                    ->options(fn (): array => AuditEmailLog::query()->distinct()->pluck('mailable', 'mailable')->all()),
            ])
            ->defaultSort('sent_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEmailLogs::route('/'),
        ];
    }
}
```

Create `backend/app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php`:

```php
<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs\Pages;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Models\AuditEmailLog;
use App\Services\AuditMail\MailcoachClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Mail;

class ListAuditEmailLogs extends ListRecords
{
    protected static string $resource = AuditEmailLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshStatuses')
                ->label(__('Refresh statuses'))
                ->visible(fn (): bool => app(MailcoachClient::class)->isConfigured())
                ->action(function (): void {
                    $items = collect(app(MailcoachClient::class)->recentTransactionalMails())->keyBy('uuid');

                    AuditEmailLog::query()
                        ->whereNotNull('mailcoach_uuid')
                        ->whereIn('mailcoach_uuid', $items->keys())
                        ->get()
                        ->each(function (AuditEmailLog $log) use ($items): void {
                            $item = $items[$log->mailcoach_uuid];

                            $status = match (true) {
                                ! empty($item['bounced_at']) => AuditEmailLog::STATUS_BOUNCED,
                                ! empty($item['delivered_at']) => AuditEmailLog::STATUS_DELIVERED,
                                default => $log->status,
                            };

                            $log->update(['status' => $status]);
                        });

                    Notification::make()->title(__('Statuses refreshed'))->success()->send();
                }),
        ];
    }

    public function getTable(): \Filament\Tables\Table
    {
        return parent::getTable()->recordActions([$this->resendAction()]);
    }

    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('Resend'))
            ->requiresConfirmation()
            ->modalDescription(fn (AuditEmailLog $record): string => __('This email was last sent to :recipient on :date. Sending again may duplicate it in their inbox.', [
                'recipient' => $record->recipient,
                'date' => $record->sent_at?->format(config('app.datetime_format')) ?? __('unknown'),
            ]))
            ->action(function (AuditEmailLog $record): void {
                $client = app(MailcoachClient::class);

                if ($record->mailcoach_uuid !== null && $client->isConfigured()) {
                    $client->resend($record->mailcoach_uuid);
                } else {
                    Mail::html($record->body, function ($message) use ($record): void {
                        $message->to($record->recipient)->subject($record->subject);
                    });
                }

                $record->update([
                    'attempts' => $record->attempts + 1,
                    'sent_at' => now(),
                    'status' => AuditEmailLog::STATUS_SENT,
                ]);

                Notification::make()->title(__('Email resent'))->success()->send();
            });
    }
}
```

(If overriding `getTable()` conflicts with the installed Filament build, register the resend action inside `AuditEmailLogResource::table()` via `->recordActions([...])` instead — same action code.)

In `backend/app/Providers/Filament/AdminPanelProvider.php`, add to the existing `->userMenuItems([...])` array (or add the method if absent):

```php
                Action::make('open-mailcoach')
                    ->label(__('Open Mailcoach'))
                    ->url(fn (): string => (string) config('services.mailcoach.ui_url'))
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => (string) config('services.mailcoach.ui_url') !== '')
                    ->icon('heroicon-o-envelope'),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditEmailLogResourceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Filament/Admin/Resources/AuditEmailLogs app/Providers/Filament/AdminPanelProvider.php tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php
git add backend/app/Filament/Admin/Resources/AuditEmailLogs backend/app/Providers/Filament/AdminPanelProvider.php backend/tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php
git commit -m "feat(backend): audit email log admin resource with resend and status refresh"
```

---

### Task 6: Full regression gate and end-to-end verification

**Files:** none (verification only).

- [ ] **Step 1: Full suite + static analysis**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: PASS, 0 failures.

Run: `docker compose exec laravel.test vendor/bin/phpstan analyse`
Expected: no new errors.

- [ ] **Step 2: End-to-end against the real Mailcoach (requires Task 1 setup completed with the license key)**

1. `docker compose ps` — `mailcoach` and `mailcoach.horizon` are Up; `http://localhost:8090/login` returns the Mailcoach login.
2. With `MAILCOACH_*` set in `backend/.env`: submit a Free Audit on the landing page → the verification email appears in Mailpit AND as a transactional mail in Mailcoach's UI AND as a `sent` row (with uuid) in Admin → Audit Emails.
3. Stop Mailcoach (`docker compose stop mailcoach`), trigger another audit email → row appears with no uuid, `last_error` mentions the fallback, and the mail still reaches Mailpit. Restart Mailcoach.
4. Resend a row from the admin (confirmation modal appears) → attempts increments; the copy arrives in Mailpit.
5. "Refresh statuses" runs without error (statuses stay `sent` on SMTP/Mailpit — delivery data needs an ESP; note this in the report).
6. Admin user menu shows "Open Mailcoach" linking to `:8090`.
7. Workstream A's stats widget now shows real "Email failures" counts (create one by pointing `MAIL_HOST` at a bogus SMTP briefly, or skip and note).
8. Verify `git status` shows no `mailcoach/vendor`, `mailcoach/.env`, or `mailcoach/auth.json`.

- [ ] **Step 3: Report**

Summarize suite results, the E2E matrix, any Mailcoach API field-name adjustments made in `MailcoachClient` (the localized-contract escape hatch), and remaining manual config (ESP for production delivery statuses).
