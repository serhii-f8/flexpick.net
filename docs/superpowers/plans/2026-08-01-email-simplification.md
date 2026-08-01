# Email Simplification (Phase 10 / D1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the Mailcoach email platform from the repository so audit mail is sent directly through a single `AuditMailer`, and close the render-failure gap that lets a message vanish without a log row.

**Architecture:** Two phases. Tasks 1–3 decouple the application from the platform — `AuditMailer` becomes render → log → send → record, and the Filament email-log resource loses its platform branches. Tasks 4–5 delete the now-unreferenced platform: client, exception, config, admin menu item, schema column, Docker services, and the standalone application directory.

**Tech Stack:** PHP 8.4, Laravel 13, Filament 5, Livewire 4, PHPUnit 11, Docker Compose, MySQL 8, Pint, Larastan 3.

**Design spec:** `docs/superpowers/specs/2026-08-01-email-simplification-design.md`
**Platform spec:** `docs/2026-07-27-flexpick-platform-specification.md` §5.8, §17 Phase 10, §20.2 *Mail routing*

## Global Constraints

- **Working directory is `backend/`** for every PHP command in this plan. There is no root-level task runner.
- **Run tests from the host with the forwarded DB port**, not through `docker compose exec`:
  `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --compact`
  Killing a `docker compose exec php artisan test` client orphans the server-side process and leaves MySQL lock contention on the testing database. The host form has been verified working against this repository. `3307` is `FORWARD_DB_PORT` from `backend/.env`.
- **`FeatureTest` has no `RefreshDatabase`.** It runs `migrate:fresh` + seed exactly once per process, then every test shares one database. Scope every assertion to records the test itself created. A bare `AuditEmailLog::where('status', 'failed')->count()` will pick up rows from other tests and is a plan violation.
- **Tests are PHPUnit**, classic `TestCase`-based classes. There is no Pest. Create tests with `php artisan make:test --phpunit {name}`.
- **Format before finalizing each task:** `vendor/bin/pint --dirty --format agent`.
- **Static analysis must introduce no new error category** (spec A16): `vendor/bin/phpstan analyse`. The accepted baseline is ~416 errors at level 3; the count may move, but no new *category* may appear.
- **`STATUS_DELIVERED` and `STATUS_BOUNCED` stay** on `AuditEmailLog`, along with their badge colors and status-filter options. Nothing sets them until ESP webhooks land. Deleting them is a plan violation (design decision D10.1).
- **Do not add a try/catch to the Filament resend action**, do not route resend through `AuditMailer`, and do not add an exhaustive-routing guard test. All three were explicitly deferred (design decisions D10.5, D10.6).
- **Verified pre-change baseline:** `587 passed (1613 assertions)` in ~93s from the host. After this plan the expected total is **579** — ten tests deleted (6 in `MailcoachClientTest`, 2 in `AuditMailerTest`, 2 in `AuditEmailLogResourceTest`) and two added.
- **Commit granularity note:** the design (D10.4) specifies two *steps* — decouple, then delete. This plan splits those into five commits (Tasks 1–3 decouple, Tasks 4–5 delete) so each carries its own test cycle and can be rejected independently. The step boundary the design cares about — behavior change separated from mass deletion — falls between Tasks 3 and 4 and is preserved.

## File Structure

**Created**
- `backend/app/Mail/Audit/StoredAuditEmail.php` — a `Mailable` reconstructed from a stored subject and HTML body. Sole consumer: the Filament resend action.
- `backend/tests/Feature/Services/Fixtures/UnrenderableMailable.php` — a test fixture whose `content()` points at a view that does not exist, so `render()` throws.

**Modified**
- `backend/app/Services/AuditMail/AuditMailer.php` — the behavioral heart of this plan.
- `backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php:79-105` — resend action.
- `backend/app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php` — header actions removed.
- `backend/app/Models/AuditEmailLog.php:25` — `$fillable`.
- `backend/database/migrations/2026_07_13_110000_create_audit_email_logs_table.php:18` — column removed.
- `backend/app/Providers/Filament/AdminPanelProvider.php:43-48` — user-menu item.
- `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php:62` — stale comment only; the `Schema::hasTable` guard stays.
- `backend/config/services.php:125-129`, `backend/.env.example:131-133`, `compose.yml:17-45`, `backend/compose.yml:43`, `CLAUDE.md:90`.
- `backend/tests/Feature/Services/AuditMailerTest.php`, `backend/tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php`.

**Deleted**
- `backend/app/Services/AuditMail/MailcoachClient.php`
- `backend/app/Exceptions/MailcoachUnavailableException.php`
- `backend/tests/Feature/Services/MailcoachClientTest.php`
- `backend/docker/mysql/create-mailcoach-database.sh`
- `mailcoach/` — 78 tracked files

---

### Task 1: Log render failures in AuditMailer

Closes the §18.7 gap: `$mailable->render()` is currently evaluated inside the `AuditEmailLog::create()` array, so a mailable that fails to render throws before any row exists. The platform branch is left alone here — Task 2 removes it.

**Files:**
- Create: `backend/tests/Feature/Services/Fixtures/UnrenderableMailable.php`
- Modify: `backend/app/Services/AuditMail/AuditMailer.php`
- Test: `backend/tests/Feature/Services/AuditMailerTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `AuditMailer::send(Mailable $mailable, string $recipient, ?AuditRequest $auditRequest = null): AuditEmailLog` — signature unchanged. On render failure it now writes an `AuditEmailLog` with `status = 'failed'`, `last_error` prefixed `'Render failed: '`, empty `subject` and `body`, then rethrows the original exception.

- [ ] **Step 1: Create the fixture mailable**

Create `backend/tests/Feature/Services/Fixtures/UnrenderableMailable.php`:

```php
<?php

namespace Tests\Feature\Services\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UnrenderableMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'This mailable cannot render',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.no-such-view-exists',
        );
    }
}
```

The envelope resolves fine; only `render()` throws. That is deliberate — it exercises the render branch specifically.

- [ ] **Step 2: Write the failing test**

Add to `backend/tests/Feature/Services/AuditMailerTest.php`. Add `use Tests\Feature\Services\Fixtures\UnrenderableMailable;` and `use Throwable;` to the imports.

```php
public function test_render_failure_logs_failed_row_and_rethrows(): void
{
    Mail::fake();

    $request = AuditRequest::factory()->create(['email' => 'render-fail@example.com']);

    try {
        app(AuditMailer::class)->send(new UnrenderableMailable, $request->email, $request);
        $this->fail('Expected the render failure to be rethrown.');
    } catch (Throwable $e) {
        // Expected — the mailer must rethrow after recording the failure.
    }

    $log = AuditEmailLog::where('audit_request_id', $request->id)->sole();

    $this->assertSame(AuditEmailLog::STATUS_FAILED, $log->status);
    $this->assertSame('UnrenderableMailable', $log->mailable);
    $this->assertSame('render-fail@example.com', $log->recipient);
    $this->assertStringStartsWith('Render failed: ', (string) $log->last_error);
    Mail::assertNothingOutgoing();
}
```

Note the scoping: `where('audit_request_id', $request->id)->sole()` — the factory-created request is unique to this test, so this cannot collide with rows written by other tests in the shared database.

- [ ] **Step 3: Run the test and confirm it fails**

Run: `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --filter=test_render_failure_logs_failed_row_and_rethrows`

Expected: FAIL. The exception escapes `AuditEmailLog::create()` before a row is written, so `sole()` raises `NoRecordsFoundException`.

- [ ] **Step 4: Restructure `AuditMailer::send()` to render first**

In `backend/app/Services/AuditMail/AuditMailer.php`, replace the body of `send()` down to and including the `$log = AuditEmailLog::create([...])` call. Leave the `if ($this->mailcoach->isConfigured())` block and everything after it untouched — Task 2 handles that.

```php
public function send(Mailable $mailable, string $recipient, ?AuditRequest $auditRequest = null): AuditEmailLog
{
    $mailableName = class_basename($mailable);

    try {
        // Illuminate\Mail\Mailable doesn't declare envelope() itself — it's a convention every
        // class-based mailable in this app follows, but Larastan can't verify it structurally.
        // @phpstan-ignore-next-line method.notFound
        $subject = (string) $mailable->envelope()->subject;
        $body = $mailable->render();
    } catch (Throwable $e) {
        AuditEmailLog::create([
            'audit_request_id' => $auditRequest?->id,
            'mailable' => $mailableName,
            'recipient' => $recipient,
            'subject' => '',
            'body' => '',
            'status' => AuditEmailLog::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'Render failed: '.$e->getMessage(),
            'sent_at' => now(),
        ]);

        throw $e;
    }

    $log = AuditEmailLog::create([
        'audit_request_id' => $auditRequest?->id,
        'mailable' => $mailableName,
        'recipient' => $recipient,
        'subject' => $subject,
        'body' => $body,
        'status' => AuditEmailLog::STATUS_PENDING,
        'attempts' => 1,
        'sent_at' => now(),
    ]);

    // ... existing mailcoach branch and direct-send branch stay as they are for now
}
```

`subject` and `body` are `NOT NULL` columns and the render is exactly what failed, so empty strings are the honest values. `sent_at` is stamped because the operator-facing column is labelled "Last attempt", not "Sent at".

- [ ] **Step 5: Run the test and confirm it passes**

Run: `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --filter=test_render_failure_logs_failed_row_and_rethrows`

Expected: PASS.

- [ ] **Step 6: Run the whole mailer test class**

Run: `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --filter=AuditMailerTest --compact`

Expected: all 5 tests pass. The four pre-existing tests must be unaffected — this step is a regression check, not a new assertion.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/Services/Fixtures/UnrenderableMailable.php \
        tests/Feature/Services/AuditMailerTest.php \
        app/Services/AuditMail/AuditMailer.php
git commit -m "fix(backend): record a log row when an audit mailable fails to render

Render was evaluated inside the AuditEmailLog::create() argument list, so a
render failure threw before any row existed - the one gap in the 'audit email
never silently stops' guarantee (spec A11, §18.7)."
```

---

### Task 2: Remove the platform path from AuditMailer

`AuditMailer` becomes render → log → send → record, with no configuration gate and no fallback. `MailcoachClient` still exists after this task but has zero callers from `AuditMailer`.

**Files:**
- Modify: `backend/app/Services/AuditMail/AuditMailer.php`
- Test: `backend/tests/Feature/Services/AuditMailerTest.php`

**Interfaces:**
- Consumes: `AuditMailer::send(...)` as restructured in Task 1.
- Produces: `AuditMailer` with **no constructor**. Anything resolving it from the container gets a dependency-free instance. Its four call sites — `AuditRequestService`, `AuditReportService`, `SendAuditVerificationReminders`, `SendAuditUnlockReminders` — all inject it by type and need no change.

- [ ] **Step 1: Delete the two platform tests**

From `backend/tests/Feature/Services/AuditMailerTest.php`, delete these two methods entirely:
- `test_sends_via_mailcoach_when_configured`
- `test_falls_back_to_mail_when_mailcoach_unreachable`

They exist only to assert the platform's HTTP contract against `Http::fake`.

- [ ] **Step 2: Simplify the surviving direct-send test**

Replace `test_sends_directly_when_unconfigured_without_http_calls` with:

```php
public function test_sends_and_logs_sent_row(): void
{
    Mail::fake();

    $request = AuditRequest::factory()->create(['email' => 'direct-send@example.com']);

    $log = app(AuditMailer::class)->send(
        new AuditRequestReceived($request, 'https://status.example'),
        $request->email,
        $request
    );

    $this->assertSame(AuditEmailLog::STATUS_SENT, $log->status);
    $this->assertSame('AuditRequestReceived', $log->mailable);
    $this->assertSame(1, $log->attempts);
    $this->assertNotSame('', $log->body);
    $this->assertNull($log->last_error);
    Mail::assertQueued(AuditRequestReceived::class);
}
```

The `config()->set(...)` and `Http::fake()` scaffolding existed only to prove the platform was not reached. With no platform, it is noise.

- [ ] **Step 3: Add the send-failure characterization test**

```php
public function test_send_failure_logs_failed_row_and_rethrows(): void
{
    $request = AuditRequest::factory()->create(['email' => 'send-fail@example.com']);

    Mail::shouldReceive('to')->once()->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('transport down'));

    try {
        app(AuditMailer::class)->send(
            new AuditRequestReceived($request, 'https://status.example'),
            $request->email,
            $request
        );
        $this->fail('Expected the send failure to be rethrown.');
    } catch (RuntimeException $e) {
        $this->assertSame('transport down', $e->getMessage());
    }

    $log = AuditEmailLog::where('audit_request_id', $request->id)->sole();

    $this->assertSame(AuditEmailLog::STATUS_FAILED, $log->status);
    $this->assertSame('transport down', $log->last_error);
}
```

Add `use RuntimeException;` to the imports.

**Be straight about what this test is:** it passes the moment it is written, because the current code already logs on the send path. It is a characterization test closing a real coverage gap — there is no send-failure test today, yet spec A11 requires "a send failure is recorded with its reason." It is not a TDD driver, and the plan does not ask you to watch it fail.

`Mail::fake()` cannot express a throw, which is why this uses the facade's Mockery seam instead.

- [ ] **Step 4: Run the test class against the unchanged implementation**

Run: `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --filter=AuditMailerTest --compact`

Expected: 4 tests, all passing — `test_sends_and_logs_sent_row`, `test_send_failure_logs_failed_row_and_rethrows`, `test_render_failure_logs_failed_row_and_rethrows`, `test_call_sites_create_log_rows`. They pass because the platform is unconfigured in the test environment, so the code already takes the direct path.

- [ ] **Step 5: Remove the platform branch from the implementation**

In `backend/app/Services/AuditMail/AuditMailer.php`: delete the constructor, delete the `use App\Exceptions\MailcoachUnavailableException;` import, and delete the entire `if ($this->mailcoach->isConfigured()) { ... }` block. The file becomes:

```php
<?php

namespace App\Services\AuditMail;

use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AuditMailer
{
    public function send(Mailable $mailable, string $recipient, ?AuditRequest $auditRequest = null): AuditEmailLog
    {
        $mailableName = class_basename($mailable);

        try {
            // Illuminate\Mail\Mailable doesn't declare envelope() itself — it's a convention every
            // class-based mailable in this app follows, but Larastan can't verify it structurally.
            // @phpstan-ignore-next-line method.notFound
            $subject = (string) $mailable->envelope()->subject;
            $body = $mailable->render();
        } catch (Throwable $e) {
            AuditEmailLog::create([
                'audit_request_id' => $auditRequest?->id,
                'mailable' => $mailableName,
                'recipient' => $recipient,
                'subject' => '',
                'body' => '',
                'status' => AuditEmailLog::STATUS_FAILED,
                'attempts' => 1,
                'last_error' => 'Render failed: '.$e->getMessage(),
                'sent_at' => now(),
            ]);

            throw $e;
        }

        $log = AuditEmailLog::create([
            'audit_request_id' => $auditRequest?->id,
            'mailable' => $mailableName,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => AuditEmailLog::STATUS_PENDING,
            'attempts' => 1,
            'sent_at' => now(),
        ]);

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

- [ ] **Step 6: Run the test class again**

Run: `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --filter=AuditMailerTest --compact`

Expected: the same 4 tests still pass. Unchanged results across Steps 4 and 6 are the point — they demonstrate that removing the platform branch changed no observable behavior.

- [ ] **Step 7: Verify no `Http::fake` remains in the file**

Run: `grep -n "Http::\|mailcoach" tests/Feature/Services/AuditMailerTest.php`

Expected: no output. If `use Illuminate\Support\Facades\Http;` is still imported, remove it.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AuditMail/AuditMailer.php tests/Feature/Services/AuditMailerTest.php
git commit -m "refactor(backend): send audit mail directly, without the Mailcoach path

Per decision D1: the platform's license expired and its live path was never
exercised. AuditMailer is now render -> log -> send -> record with no
configuration gate and no fallback."
```

---

### Task 3: Decouple the Filament email-log resource

Removes the platform branch from resend and deletes the status-refresh header action, which only the platform could serve.

**Files:**
- Create: `backend/app/Mail/Audit/StoredAuditEmail.php`
- Modify: `backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php`
- Modify: `backend/app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php`
- Test: `backend/tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php`

**Interfaces:**
- Consumes: `AuditEmailLog::STATUS_SENT`; `AuditMailer` is deliberately *not* used here (design decision D10.5 — resend bumps `attempts` on the existing row rather than creating a new one).
- Produces: `App\Mail\Audit\StoredAuditEmail(string $storedSubject, string $storedBody)` — a `Mailable` that reproduces a stored message, constructed positionally from `$record->subject` and `$record->body`. It does **not** implement `ShouldQueue`; resend is an operator action and should surface transport problems in the request, matching today's behavior.

- [ ] **Step 1: Create the named mailable**

Create `backend/app/Mail/Audit/StoredAuditEmail.php`:

```php
<?php

namespace App\Mail\Audit;

use Illuminate\Mail\Mailable;

class StoredAuditEmail extends Mailable
{
    public function __construct(
        private readonly string $storedSubject,
        private readonly string $storedBody,
    ) {}

    public function build(): self
    {
        return $this->subject($this->storedSubject)->html($this->storedBody);
    }
}
```

This replaces the anonymous class currently inlined in the resend action. Same behavior; the action becomes readable and the mailable becomes independently testable.

- [ ] **Step 2: Delete the two platform tests from the resource test**

From `backend/tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php`, delete entirely:
- `test_resend_uses_mailcoach_api_for_uuid_rows`
- `test_refresh_statuses_maps_api_delivery_data`

- [ ] **Step 3: Rewrite the surviving resend test with a real assertion**

Replace `test_resend_falls_back_to_direct_mail_for_rows_without_uuid` with:

```php
public function test_resend_sends_stored_subject_and_body(): void
{
    Mail::fake();

    $admin = $this->createAdminUser();
    $log = AuditEmailLog::factory()->create([
        'attempts' => 1,
        'recipient' => 'resend-target@example.com',
        'subject' => 'Your codebase health report is ready',
        'body' => '<p>stored body</p>',
    ]);

    Livewire::actingAs($admin)
        ->test(ListAuditEmailLogs::class)
        ->callTableAction('resend', $log);

    Mail::assertSent(StoredAuditEmail::class, function (StoredAuditEmail $mail): bool {
        $mail->build();

        return $mail->subject === 'Your codebase health report is ready'
            && $mail->hasTo('resend-target@example.com');
    });

    $this->assertSame(2, $log->fresh()->attempts);
    $this->assertSame(AuditEmailLog::STATUS_SENT, $log->fresh()->status);
}
```

The old assertion was `fn (Mailable $mail) => true`, which passes for literally any mail. Spec §20.2 requires resend to reproduce the **stored** subject and body, so assert those. Note the factory call no longer passes `'mailcoach_uuid' => null`.

Update imports: add `use App\Mail\Audit\StoredAuditEmail;`, drop `use Illuminate\Mail\Mailable;` and `use Illuminate\Support\Facades\Http;` if they become unused.

- [ ] **Step 4: Run the test and confirm it fails**

Run: `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --filter=test_resend_sends_stored_subject_and_body`

Expected: FAIL. The action currently sends an anonymous `Mailable`, so `Mail::assertSent(StoredAuditEmail::class, ...)` finds nothing.

- [ ] **Step 5: Simplify the resend action**

In `backend/app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php`, replace the action's `->action(...)` closure with:

```php
->action(function (AuditEmailLog $record): void {
    Mail::to($record->recipient)->send(
        new StoredAuditEmail($record->subject, $record->body)
    );

    $record->update([
        'attempts' => $record->attempts + 1,
        'sent_at' => now(),
        'status' => AuditEmailLog::STATUS_SENT,
    ]);

    Notification::make()->title(__('Email resent'))->success()->send();
}),
```

Update imports: remove `use App\Services\AuditMail\MailcoachClient;` and `use Illuminate\Mail\Mailable;`, add `use App\Mail\Audit\StoredAuditEmail;`. Keep `use Illuminate\Support\Facades\Mail;`.

Do **not** add a try/catch here (design decision — deferred to the §18.7 backlog).

- [ ] **Step 6: Delete the status-refresh header action**

In `backend/app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php`, delete the entire `getHeaderActions()` method and the imports it alone used. The file becomes:

```php
<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs\Pages;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditEmailLogs extends ListRecords
{
    protected static string $resource = AuditEmailLogResource::class;
}
```

The class stays — it is the resource's index page, referenced by `AuditEmailLogResource::getPages()`.

- [ ] **Step 7: Run the resource test class**

Run: `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --filter=AuditEmailLogResourceTest --compact`

Expected: 2 tests pass — `test_list_renders_log_rows` and `test_resend_sends_stored_subject_and_body`.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mail/Audit/StoredAuditEmail.php \
        app/Filament/Admin/Resources/AuditEmailLogs/ \
        tests/Feature/Filament/Admin/Resources/AuditEmailLogResourceTest.php
git commit -m "refactor(backend): resend audit mail directly from the stored rendering

Drops the Mailcoach branch of resend and the status-refresh header action,
which only the platform could serve. The stored subject and body are now
reproduced through a named StoredAuditEmail mailable."
```

---

### Task 4: Delete the platform code and the schema column

After Task 3 nothing references `MailcoachClient`. This removes it, its exception, its configuration, the admin menu entry, and the `mailcoach_uuid` column.

**Files:**
- Delete: `backend/app/Services/AuditMail/MailcoachClient.php`
- Delete: `backend/app/Exceptions/MailcoachUnavailableException.php`
- Delete: `backend/tests/Feature/Services/MailcoachClientTest.php`
- Modify: `backend/config/services.php:125-129`
- Modify: `backend/app/Providers/Filament/AdminPanelProvider.php:43-48`
- Modify: `backend/app/Models/AuditEmailLog.php:25`
- Modify: `backend/database/migrations/2026_07_13_110000_create_audit_email_logs_table.php:18`

**Interfaces:**
- Consumes: the decoupled `AuditMailer` and resource from Tasks 2–3.
- Produces: no PHP class named `Mailcoach*` anywhere in `backend/`. `audit_email_logs` has no `mailcoach_uuid` column.

- [ ] **Step 1: Confirm there are no remaining references**

Run: `grep -rn "Mailcoach\|mailcoach" app/ tests/ --include=*.php`

Expected output, and nothing else:
- `app/Services/AuditMail/MailcoachClient.php` (the class being deleted)
- `app/Exceptions/MailcoachUnavailableException.php` (the class being deleted)
- `app/Providers/Filament/AdminPanelProvider.php` (the menu item, deleted in Step 3)
- `app/Models/AuditEmailLog.php` (the `$fillable` entry, deleted in Step 4)
- `tests/Feature/Services/MailcoachClientTest.php` (deleted in Step 2)
- `app/Filament/Admin/Widgets/AuditAdminStatsWidget.php` (a stale comment — Task 5 handles it)

If anything else appears, stop and resolve it before continuing.

- [ ] **Step 2: Delete the three files**

```bash
git rm app/Services/AuditMail/MailcoachClient.php \
       app/Exceptions/MailcoachUnavailableException.php \
       tests/Feature/Services/MailcoachClientTest.php
```

- [ ] **Step 3: Remove the configuration block and the admin menu item**

In `backend/config/services.php`, delete the whole `'mailcoach' => [...]` entry (endpoint, api_token, ui_url), leaving the `'anthropic'` block above it intact.

In `backend/app/Providers/Filament/AdminPanelProvider.php`, delete the `Action::make('open-mailcoach')` entry from `userMenuItems()`. The `Action::make('user-dashboard')` entry stays, so the array does not become empty and the `Action` import is still needed.

- [ ] **Step 4: Remove the column**

In `backend/app/Models/AuditEmailLog.php`, drop `'mailcoach_uuid'` from `$fillable`. The array becomes:

```php
protected $fillable = [
    'audit_request_id', 'mailable', 'recipient', 'subject', 'body',
    'status', 'attempts', 'last_error', 'sent_at',
];
```

Leave `STATUS_DELIVERED` and `STATUS_BOUNCED` in place — they are reserved for ESP webhooks.

In `backend/database/migrations/2026_07_13_110000_create_audit_email_logs_table.php`, delete this line:

```php
$table->string('mailcoach_uuid')->nullable()->index();
```

Editing the create migration rather than adding a drop migration is deliberate: this branch has never been merged or deployed, so the column exists only in local Docker volumes.

- [ ] **Step 5: Rebuild the local database**

Run: `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan migrate:fresh --seed`

Expected: migrations run clean with no `mailcoach_uuid` column. This is required because the create migration was edited in place — an existing database still has the old column.

- [ ] **Step 6: Run the full suite**

Run: `DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --compact`

Expected: green. Test count drops by 5 relative to the pre-change baseline (4 platform tests deleted, plus the whole `MailcoachClientTest` class), and rises by 2 (the render-failure and send-failure tests added in Tasks 1–2).

- [ ] **Step 7: Run static analysis**

Run: `vendor/bin/phpstan analyse`

Expected: no *new* error category versus the accepted baseline. The absolute count may fall, since deleted files carried errors.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app/ config/ database/ tests/
git commit -m "chore(backend): delete the Mailcoach client, config, and log column

Nothing references the platform after the mailer and resource were decoupled.
The mailcoach_uuid column is removed from the create migration rather than
dropped in a new one - this branch was never merged or deployed. The delivered
and bounced status constants stay, reserved for ESP webhooks."
```

---

### Task 5: Delete the platform application, its containers, and stale documentation

The last task. Removes the standalone application, its Docker services, its database bootstrap, its environment variables, and the documentation that still points at it.

**Files:**
- Delete: `mailcoach/` (78 tracked files)
- Delete: `backend/docker/mysql/create-mailcoach-database.sh`
- Modify: `compose.yml:17-45`
- Modify: `backend/compose.yml:43`
- Modify: `backend/.env.example:131-133`
- Modify: `CLAUDE.md:90`
- Modify: `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php:62`

**Interfaces:**
- Consumes: everything from Tasks 1–4.
- Produces: `grep -ri mailcoach` matches only historical documents.

- [ ] **Step 1: Preserve the license credential, then stop the containers**

`mailcoach/auth.json` is untracked and holds your Spatie account email and license key. It expired 2025-10-15 and is not being renewed, but if you want that credential for any other Spatie package, copy it out now — this task destroys it.

```bash
cp mailcoach/auth.json ~/spatie-auth.json.bak   # optional; skip if not wanted
docker compose down mailcoach mailcoach.horizon
```

Stopping first matters: the services bind-mount `./mailcoach`, and removing the directory underneath a running container leaves it in a broken state.

- [ ] **Step 2: Remove the Docker services**

In `compose.yml` (repository root), delete both service blocks — `mailcoach:` and `mailcoach.horizon:`, lines 17–45. The `frontend:` service above them stays.

In `backend/compose.yml`, delete this volume mount from the `mysql` service (line 43):

```yaml
- './docker/mysql/create-mailcoach-database.sh:/docker-entrypoint-initdb.d/11-create-mailcoach-database.sh'
```

The `10-create-testing-database.sh` mount on the line above stays — the test suite depends on it.

- [ ] **Step 3: Delete the application and its database bootstrap**

```bash
git rm -r mailcoach
git rm backend/docker/mysql/create-mailcoach-database.sh
```

- [ ] **Step 4: Remove the environment variables**

In `backend/.env.example`, delete these three lines (131–133):

```
MAILCOACH_ENDPOINT=http://mailcoach/api
MAILCOACH_API_TOKEN=
MAILCOACH_UI_URL=http://localhost:8090
```

Then remove the same three keys from your local `backend/.env` if present. If `MAILCOACH_*` was ever set in a deployed environment, remove it there too.

- [ ] **Step 5: Drop the leftover MySQL database**

The init script only runs on a *fresh* volume, so the `mailcoach` database survives on your existing one:

```bash
docker compose exec mysql bash -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS mailcoach;"'
```

- [ ] **Step 6: Update the documentation**

In `CLAUDE.md`, line 90 currently reads:

> `- Delivery via `app/Services/AuditMail/` (`AuditMailer`, `MailcoachClient`); the acquisition funnel is tracked through `AuditFunnelRecorder` / `AuditFunnelEvent`.`

Replace with:

> `- Delivery via `app/Services/AuditMail/` (`AuditMailer`, which logs every message to `AuditEmailLog` and sends directly through the framework mailer); the acquisition funnel is tracked through `AuditFunnelRecorder` / `AuditFunnelEvent`.`

In `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php`, line 62, replace the stale comment:

```php
// audit_email_logs ships with the Mailcoach workstream; render 0 until it lands
```

with:

```php
// Tolerate the table being absent so the widget still renders on a partial schema
```

**Keep the `Schema::hasTable('audit_email_logs')` guard below it** — spec §20.2 requires widgets to tolerate a missing sibling table.

- [ ] **Step 7: Verify the platform is gone**

```bash
grep -ri mailcoach --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=vendor .
```

Expected: matches only in historical documents — `docs/superpowers/specs/2026-08-01-email-simplification-design.md`, `docs/2026-08-01-remaining-phases.md`, `docs/2026-08-01-pre-launch-backlog.md`, `docs/2026-07-27-flexpick-platform-specification.md`, `docs/superpowers/plans/2026-07-13-mailcoach-email-platform.md`, this plan, and `.superpowers/sdd/`. No matches under `backend/app/`, `backend/config/`, `backend/tests/`, `backend/database/`, or any compose file.

- [ ] **Step 8: Verify the environment still boots**

```bash
docker compose up -d
docker compose ps
```

Expected: `frontend`, `laravel.test`, `mysql`, `redis`, `mailpit` running. No `mailcoach` or `mailcoach.horizon`. No error about a missing bind mount or a missing init script.

- [ ] **Step 9: Run the full suite and static analysis one last time**

```bash
DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test --compact
vendor/bin/phpstan analyse
vendor/bin/pint --dirty --format agent
```

Expected: suite green, no new PHPStan error category, Pint reports no changes.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "chore: remove the Mailcoach application and its infrastructure

Deletes the standalone app, its compose services, MySQL bootstrap, and
environment variables. Completes Phase 10 (decision D1): audit mail is sent
directly from the product application, with AuditEmailLog as the sole record."
```

---

## Acceptance

The phase is complete when all of the following hold, each backed by an observed command:

| # | Criterion | Verified by |
| --- | --- | --- |
| 1 | `grep -ri mailcoach` matches only historical documents | Task 5, Step 7 |
| 2 | `AuditMailer::send()` is render → log → send → record, with no platform branch and no configuration gate | Task 2, Step 5 |
| 3 | A mailable that fails to render produces a `failed` row with its reason, then rethrows | Task 1, Step 5 |
| 4 | A send failure produces a `failed` row with its reason, then rethrows | Task 2, Step 4 |
| 5 | All ten audit message types still route through `AuditMailer` and produce a log row (A11, §20.2) | Task 4, Step 6 — `test_call_sites_create_log_rows` plus both console reminder test classes |
| 6 | Resend reproduces the stored subject and body and requires confirmation (§20.2) | Task 3, Step 7 |
| 7 | `docker compose up -d` boots with no Mailcoach service and no missing bind mount | Task 5, Step 8 |
| 8 | Suite green, Pint reports no changes, no new PHPStan error category (A16) | Task 5, Step 9 |

## Deferred — do not implement in this plan

- Try/catch on the Filament resend action (§18.7)
- An exhaustive-routing guard test making A11 a regression-tested invariant
- Routing resend through `AuditMailer` so it creates its own log row
- ESP selection, delivery/open/click tracking, and any column carrying a provider message id (Q31 — Phase 9A)
