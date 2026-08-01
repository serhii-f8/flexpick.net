# Email simplification (Phase 10 / D1) — Design

**Date:** 2026-08-01
**Phase:** 10 of `docs/2026-08-01-remaining-phases.md`
**Authority:** `docs/2026-07-27-flexpick-platform-specification.md` §5.8, §17 Phase 10, §18.6, §20.2 *Mail routing*, Q30

## Purpose

Decision **D1** removed the standalone Mailcoach email platform from the architecture: its license expired 2025-10-15, composer access to the private repository is closed, and the live path was never exercised. This phase deletes the platform from the repository so the code matches the specified §5.8 shape — all audit email sent directly from the product application through a single mailer, with a per-message log.

What this phase does **not** touch: the email log itself, resend-from-stored-rendering, and the guarantee that all ten audit message types route through `AuditMailer`. Those were delivered by Phase 8 and remain delivered.

Delivery/open/click tracking is deferred to ESP event webhooks (spec §4.2, Q31) and is out of scope here.

## Decisions taken during design

| # | Decision | Rationale |
| --- | --- | --- |
| D10.1 | **Drop `mailcoach_uuid`; keep `STATUS_DELIVERED` and `STATUS_BOUNCED`** | Nothing can set the column once the platform is gone. The two status constants cost nothing — the badge colors and status filter already handle them — and reserve the vocabulary for ESP webhooks. When that lands, the column is added shaped to whatever the chosen provider returns, rather than pre-committing now to a shape from a platform being deleted. |
| D10.2 | **Fold in the render-failure fix (§18.7, top-five #5)** | `AuditMailer::send()` currently evaluates `$mailable->render()` inside the `AuditEmailLog::create()` array, so a render failure throws before any row exists — the one hole in the A11 "audit email never silently stops" guarantee. The file is being rewritten anyway. |
| D10.3 | **Edit the original create migration in place** | `growth-retention` is 123 commits ahead of `main` and has never been merged or deployed, so the column exists only in local Docker volumes. Editing the create migration keeps schema history free of a column for a platform that never shipped. Requires `migrate:fresh` locally. |
| D10.4 | **Two commits: decouple, then delete** | The deletion is 78 files and 1.1 MB of pure removal; the behavior change is small and is the only part that can break. Splitting them keeps the interesting diff readable and gives a clean bisect point if audit mail misbehaves after merge. |
| D10.5 | **Resend keeps bypassing `AuditMailer`** | Routing resend through the mailer would create a new log row instead of bumping `attempts` on the existing one, and would log `StoredAuditEmail` rather than the original mailable name. That is a question about A11's wording, not part of D1. |
| D10.6 | **No exhaustive-routing guard test** | §20.2's "proven by exhaustive search" is currently satisfied by manual grep. Making it a regression test is worthwhile but out of D1's scope; recorded as a backlog item below. |

## Scope

### In

- Removal of the Mailcoach application, its containers, database bootstrap, client, exception, configuration, and admin entry point
- Simplification of `AuditMailer` to **render → log → send → record outcome**
- The render-failure fix (D10.2)
- Schema and test updates that follow from the above
- Documentation updates

### Out

- ESP selection and configuration (Q31 — Phase 9A)
- Delivery/open/click tracking (deferred, §4.2)
- Try/catch on the Filament resend action (§18.7 — stays on the backlog)
- Exhaustive-routing guard test (D10.6)
- Any change to the ten audit mailables themselves

---

## Step 1 — Decouple (behavior change)

### `AuditMailer`

Loses its constructor dependency entirely. Three phases, each recording its own failure:

```php
class AuditMailer
{
    public function send(Mailable $mailable, string $recipient, ?AuditRequest $auditRequest = null): AuditEmailLog
    {
        // 1. Render — a failure here now produces a row instead of swallowing the message
        try {
            // Illuminate\Mail\Mailable doesn't declare envelope() itself — it's a convention every
            // class-based mailable in this app follows, but Larastan can't verify it structurally.
            // @phpstan-ignore-next-line method.notFound
            $subject = (string) $mailable->envelope()->subject;
            $body = $mailable->render();
        } catch (Throwable $e) {
            AuditEmailLog::create([
                'audit_request_id' => $auditRequest?->id,
                'mailable' => class_basename($mailable),
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

        // 2. Log the attempt
        $log = AuditEmailLog::create([
            'audit_request_id' => $auditRequest?->id,
            'mailable' => class_basename($mailable),
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => AuditEmailLog::STATUS_PENDING,
            'attempts' => 1,
            'sent_at' => now(),
        ]);

        // 3. Send — outcome recorded either way
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

Three properties this fixes in place:

- **The render-failure row stores empty `subject` and `body`.** Both columns are `NOT NULL` and the render is precisely what failed — there is nothing to store. The row's value is that it exists and names the mailable and recipient.
- **It stamps `sent_at`.** The operator-facing column is labelled "Last attempt", so recording an attempt that never left is consistent with what is read.
- **Both paths rethrow.** Unchanged from current behavior; call sites still treat a throw as failure. The log row is an addition, not a substitution.

### `AuditEmailLogResource`

- Drop the `MailcoachClient` import and the `$record->mailcoach_uuid !== null && $client->isConfigured()` branch of the resend action. What was the `else` becomes the whole action.
- Extract the inline anonymous `Mailable` into `App\Mail\Audit\StoredAuditEmail(string $subject, string $html)`. Same behavior; the action body becomes readable and the mailable is independently testable.
- Resend continues to bump `attempts` and `sent_at` on the existing row (D10.5) and continues to have no try/catch (out of scope).

### `ListAuditEmailLogs`

`getHeaderActions()` contained exactly one action — the platform status refresh. The method and its now-unused imports (`Action`, `Notification`, `MailcoachClient`, `AuditEmailLog`) are removed. The class remains as the resource's index page.

### Step 1 exit

Suite green. `MailcoachClient` still exists but has zero callers — verifiable by grep.

---

## Step 2 — Delete

### Application code

| Target | Location |
| --- | --- |
| `MailcoachClient` | `backend/app/Services/AuditMail/MailcoachClient.php` |
| `MailcoachUnavailableException` | `backend/app/Exceptions/MailcoachUnavailableException.php` |
| `services.mailcoach` config block | `backend/config/services.php:125-129` |
| "Open Mailcoach" user-menu item | `backend/app/Providers/Filament/AdminPanelProvider.php:43-48` |

Removing the menu item leaves `userMenuItems()` with its `user-dashboard` entry — the array does not become empty.

### Schema

| Target | Location |
| --- | --- |
| `mailcoach_uuid` column | `database/migrations/2026_07_13_110000_create_audit_email_logs_table.php:18` |
| `mailcoach_uuid` in `$fillable` | `app/Models/AuditEmailLog.php:25` |

`AuditEmailLogFactory` requires no change — it never set the column. `STATUS_DELIVERED` and `STATUS_BOUNCED`, their badge colors, and the status filter options all stay (D10.1).

### Infrastructure

| Target | Location |
| --- | --- |
| `mailcoach` and `mailcoach.horizon` services | `compose.yml:17-45` |
| MySQL init-script mount | `backend/compose.yml:43` |
| `create-mailcoach-database.sh` | `backend/docker/mysql/` |
| `MAILCOACH_ENDPOINT` / `MAILCOACH_API_TOKEN` / `MAILCOACH_UI_URL` | `backend/.env.example:131-133` |
| The application | `mailcoach/` — 78 tracked files, 1.1 MB |

### Documentation

- `CLAUDE.md:90` names `MailcoachClient` as part of the delivery path. Neither root nor backend `AGENTS.md` references the platform (verified by grep).
- `backend/app/Filament/Admin/Widgets/AuditAdminStatsWidget.php:62` — the comment claiming the table "ships with the Mailcoach workstream" is stale. **The `Schema::hasTable` guard it sits above stays**: §20.2 requires widgets to tolerate a missing sibling table.

### Host-side cleanup (not expressed in git)

1. `docker compose down mailcoach mailcoach.horizon` before removing the directory, or the containers linger against a bind mount that no longer exists.
2. **Drop the `mailcoach` MySQL database by hand.** The init script only runs on a fresh volume, so the database survives on existing volumes.
3. `mailcoach/auth.json` and `mailcoach/.env` are untracked and die with the directory. `auth.json` holds the Spatie account email and license key — expired and not being renewed, but capture a copy first if that credential is wanted for any other Spatie package.
4. `php artisan migrate:fresh --seed` locally, since the create migration is edited in place rather than superseded (D10.3).
5. Remove `MAILCOACH_*` from any deployed `.env`, if it was ever set.

### Step 2 exit

`grep -ri mailcoach` returns nothing outside historical documents (this spec, `docs/2026-08-01-remaining-phases.md`, and the SDD progress ledger).

---

## Testing

### Delete

- `backend/tests/Feature/Services/MailcoachClientTest.php` — entirely
- `AuditMailerTest::test_sends_via_mailcoach_when_configured`
- `AuditMailerTest::test_falls_back_to_mail_when_mailcoach_unreachable`
- `AuditEmailLogResourceTest::test_resend_uses_mailcoach_api_for_uuid_rows`
- `AuditEmailLogResourceTest::test_refresh_statuses_maps_api_delivery_data`

### Rewrite

- `AuditMailerTest::test_sends_directly_when_unconfigured_without_http_calls` → **`test_sends_and_logs_sent_row`**. The `config()->set(...)` and `Http::fake()` scaffolding existed only to prove the platform was not reached; with no platform it is noise.
- `AuditEmailLogResourceTest::test_resend_falls_back_to_direct_mail_for_rows_without_uuid` → **`test_resend_sends_stored_subject_and_body`**, with the assertion strengthened. It currently asserts `fn (Mailable $mail) => true`, which passes for any mail; §20.2 requires resend to reproduce the *stored* subject and body, so assert those values. Its factory call also drops the now-removed `'mailcoach_uuid' => null` argument.

### Add

Both on `AuditMailer`:

- **`test_render_failure_logs_failed_row_and_rethrows`** — a mailable pointing at a missing view. This is the RED test for D10.2: it fails against current code because no row exists.
- **`test_send_failure_logs_failed_row_and_rethrows`** — the `Mail` facade made to throw. `Mail::fake()` cannot express this, so use the facade's Mockery seam: `Mail::shouldReceive('to')->andReturnSelf()` then `->shouldReceive('send')->andThrow(new RuntimeException('transport down'))`. This passes as soon as it is written, because current code already logs on the send path. It is a characterization test closing an A11 coverage gap (there is no send-failure test today), not a TDD driver — the plan should say so rather than dress it as RED.

### Unchanged

`AuditMailerTest::test_call_sites_create_log_rows`, `AuditEmailLogResourceTest::test_list_renders_log_rows`, `AuditEmailLogTest`, `SendAuditVerificationRemindersTest`, `SendAuditUnlockRemindersTest` — all verified free of platform references.

### Hazard

`FeatureTest` has no `RefreshDatabase`; tests share database state across methods. Every assertion in the new tests must be scoped to the record it created — a bare `AuditEmailLog::where('status', 'failed')->count()` will pick up rows written by other tests.

---

## Acceptance

This phase is complete when:

| # | Criterion |
| --- | --- |
| 1 | `grep -ri mailcoach` returns matches only in historical documents |
| 2 | `AuditMailer::send()` is render → log → send → record, with no platform branch and no configuration gate |
| 3 | A mailable that fails to render produces an `AuditEmailLog` row with status `failed` and the reason, then rethrows |
| 4 | A send failure produces a row with status `failed` and the reason, then rethrows |
| 5 | All ten audit message types still route through `AuditMailer` and produce a log row (A11, §20.2 *Mail routing*) |
| 6 | Resend reproduces the stored subject and body and requires confirmation (§20.2) |
| 7 | `docker compose up -d` boots with no Mailcoach service and no missing bind mount |
| 8 | `php artisan test --compact` green; `vendor/bin/pint --dirty` reports no changes; PHPStan introduces no new error category (A16) |

## Deferred from this phase

Recorded so they are not rediscovered:

- Try/catch on the Filament resend action (§18.7 — "resend and status-refresh paths lack explicit exception handling"; status-refresh is deleted here, leaving resend as the only survivor)
- Exhaustive-routing guard test making A11 a regression-tested invariant (D10.6)
- Whether resend should route through `AuditMailer` and create its own log row (D10.5) — a question about A11's wording
- ESP selection, delivery/open/click tracking, and the column that would carry a provider message id (Q31, Phase 9A / deferred)
