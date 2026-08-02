# Phase 9A-1 — In-repo observability

**Date:** 2026-08-02
**Branch:** `growth-retention`
**Source of truth:** `docs/2026-07-27-flexpick-platform-specification.md` (revision 2026-08-01).
**Expands:** `docs/2026-08-01-remaining-phases.md` § "Phase 9A — Launch-blocking operations".
**Spec sections:** §14.4, §15, §18.3 O1/O2/O9, §18.4, §18.5 SC1, §20.3 PR4–PR6.

---

## 1. Scope and decomposition

Phase 9A as listed in the remaining-phases checklist contains nine bullets spanning four
different kinds of work: application code, third-party account setup, infrastructure
provisioning, and organizational policy. A single spec would be half design document and half
runbook, and its plan would contain steps that can only be marked done by hand.

Phase 9A is therefore split:

| | Contents | Form |
| --- | --- | --- |
| **9A-1** *(this document)* | Error tracking wiring, health checks, worker and scheduler liveness, alert routing, smoke command | Design spec → implementation plan → TDD |
| **9A-2** *(separate, next)* | Staging site, CI pipeline, first production deploy, smoke gate, rehearsed rollback, ESP selection and SPF/DKIM/DMARC, support ownership | Executable runbook |

9A-1 is everything that lives in the repository and can be driven by tests. 9A-2 is everything
verifiable only by doing it against real infrastructure.

**Out of scope for 9A-1, deliberately:**

- §15.5's *dependency health* row (model provider, OSV, mail transport reachability). PR5 names
  exactly four checks — liveness, readiness, worker health, scheduler health — and dependency
  reachability is not among them. Each dependency already has a specified degradation path.
  Deferred to Phase 9B.
- §15.7's operations dashboard. Already assigned to Phase 9B by the remaining-phases checklist.
- Metrics collection and storage beyond what the checks compute. §15.2's additions are 9B work.

---

## 2. Established context

Verified against the working tree on 2026-08-02, not assumed:

| Item | State |
| --- | --- |
| Error tracking | No package in `backend/composer.json` |
| Health checks | Only Laravel's default `health: '/up'`, `backend/bootstrap/app.php:21` |
| CI | No `.github/` directory |
| Deploy | `backend/deploy.php` is untouched SaaSykit boilerplate — host `1.2.3.4`, domain `yourdomain.com`, repo `username/saasykit`. No smoke step, no rollback task. |
| Scheduler | 10 commands in `backend/routes/console.php`, 4 audit-critical |
| Audit queue | `config('audit.queue')` = `audit`; `supervisor-audit` runs `maxProcesses: 3` in production |
| Clone credentials | `RepositoryCloner:81` builds `https://x-access-token:{token}@github.com/…`, redacted only in messages it throws itself (`:19`, `:36`) |

**Decisions taken during brainstorming:**

| # | Decision |
| --- | --- |
| D9A.1 | Hosting is **Ploi**, single server. The server exists; the app has never been deployed. |
| D9A.2 | **Self-hosted only** — no third-party observability SaaS. |
| D9A.3 | Monitoring is co-located on the production server. Ploi's own off-box uptime monitoring covers "the server is down", which nothing co-located can. |
| D9A.4 | Alerts route to **Telegram, Slack, and email**, as a config-driven list. |
| D9A.5 | Error collector is **Bugsink** (single container against the existing MySQL, ~200 MB) over GlitchTip (~1 GB, Django + Postgres + Redis). The box already carries MySQL, Redis, PHP-FPM, and three audit workers. |
| D9A.6 | Health thresholds are env-overridable config, **not** operator-adjustable settings. §14.5 reserves the override mechanism for business settings; these are deployment-tuning values. |

**Rejected:** self-hosted Uptime Kuma. Its HTTP monitoring duplicates Ploi's, its push-heartbeat
trick for worker and scheduler liveness is achievable in-app via cache freshness, and it moves
alert configuration into a UI where it is not version-controlled.

**Dependency compatibility confirmed:** `spatie/laravel-health` 1.40.1 declares
`illuminate/* ^11.0|^12.0|^13.0`, compatible with the Laravel 13 pin in `composer.json`.

---

## 3. The structural problem this design solves

If the scheduler runs the health checks, and the scheduler dies, no check runs, nothing fails,
and no alert fires. The system reports health by staying silent, which is indistinguishable from
being healthy. This is §18.3 O2, and it is the reason a purely in-app design cannot work.

The answer is two independent paths:

1. **In-app.** The scheduler runs checks, persists results, and notifies on failure. This detects
   everything that goes wrong *while the app is alive* — stalled queues, dead workers, failure-rate
   spikes, disk pressure.
2. **Off-box.** Ploi's uptime monitor polls a token-guarded endpoint every minute. That endpoint
   returns 503 when a Critical- or High-band check is failing **or when the newest stored result is
   stale**. The staleness arm is the dead-man's switch: it converts the app's silence into an
   audible signal, because the app cannot report its own absence.

Ploi is off-box, already paid for, and already wired to Telegram, Slack, and email.

---

## 4. Components

Six units, each with a single responsibility.

### 4.1 `spatie/laravel-health` (vendor, MIT)

Owns check registration, scheduled execution, result persistence, and result storage. Configured,
not wrapped. Built-in checks used as-is: `DatabaseCheck`, `RedisCheck`, `CacheCheck`,
`UsedDiskSpaceCheck`, `HorizonCheck`, `ScheduleCheck`, and `QueueCheck` pinned to the queue named
by `config('audit.queue')`.

### 4.2 `app/Health/Checks/` — three custom checks

Each is one class answering one domain question, depending only on a model and configuration.

- **`OldestPendingAuditCheck`** — age of the oldest `queued` request, and separately of the oldest
  stranded `analyzing` request. The `analyzing` arm measures from **`analysis_started_at`**, a
  dedicated column added by `2026_07_13_100000_add_admin_fields_to_audit_requests_table`. The
  `queued` arm has no dedicated transition timestamp and uses **`updated_at`** as the proxy: a
  request sitting in `queued` receives no writes, so `updated_at` is the moment it entered the
  state. Adding a transition-timestamp column is not in scope for this phase.
- **`AuditPipelineFailureRateCheck`** — share of *attempted runs* (requests whose
  `analysis_started_at` falls inside the window) that ended `failed`. `needs_followup` is excluded
  from the numerator; see §5.
- **`MailFailureRateCheck`** — share of `AuditEmailLog` rows in `failed` within the window.

### 4.3 `HealthResultsController` — one thin controller

Serves spatie's *stored* results as JSON; it does not evaluate checks at request time, so
per-minute polling costs one indexed read. Guarded by a token compared with `hash_equals`; a wrong
or absent token returns **404**, not 401, so the endpoint does not advertise itself.

**Status-code rule.** The endpoint returns 503 when a **Critical- or High-band** check is failing or
crashed, when the newest result is stale beyond the freshness window, or when no results exist at
all. **Medium-band checks never affect the status code** — they appear in the JSON body and alert
through the in-app path only.

The reason is that the two paths mean different things. The in-app path says "look at this soon";
the Ploi path is a pager. A cache write failure or 85% disk usage should not page, and if it does,
the pager stops being trusted — which costs you the Critical alerts too.

### 4.4 `OperationsAlert` notification and three channels

`via()` reads a configured channel list, so the channel set is one config entry rather than three
code paths. An unresolved channel name is logged as a warning and skipped, never silently dropped —
a typo in `HEALTH_ALERT_CHANNELS` must not produce an alerting system that is mute and looks fine.

All three channels are ours, and none of them may throw. `TelegramChannel` and `SlackWebhookChannel`
are each a single `Http::post` with a short timeout — no additional packages. **`MailAlertChannel`
is a custom channel rather than Laravel's built-in `mail` channel**, because the built-in rethrows:
`NotificationSender::sendToNotifiable` re-raises after dispatching `NotificationFailed`, so a mail
transport error would kill the remaining channels for that check *and* every subsequent check's
alert. A mail outage would suppress the alert about the mail outage. It sends via `Mail::raw()` from
the same `toAlertText()` rendering the other two use, and it warns-and-skips when no destination is
configured, symmetric with the other two.

Delivery is to an `AnonymousNotifiable`; there is no user to notify.

### 4.5 `sentry/sentry-laravel` (vendor)

DSN points at the self-hosted Bugsink instance. `traces_sample_rate` 0 — performance tracing is not
warranted at this scale and would inflate storage on a co-located collector. Errors sample at 100%.
`send_default_pii` false. Includes the `before_send` scrubber described in §6.

### 4.6 `app:smoke` console command

Post-deploy gate for 9A-2. Read-only, side-effect-free, safe to re-run against production. The exit
code is the entire contract.

### 4.7 Endpoints

| Route | Purpose | Behavior |
| --- | --- | --- |
| `/up` | Liveness | Unchanged from Laravel's default |
| `/health/ready` | Readiness | Database and cache reachable only. 503 on failure. **Never contacts an external dependency** (§15.5 `[R]`) |
| `/health` | Monitoring | Token-guarded, serves §4.3 |

**`/health/ready` evaluates at request time and is independent of the stored-result set.** Readiness
is a question about *this process, right now* — "can I serve traffic?" — so it cannot be answered
from results a scheduler wrote up to five minutes ago. It performs its own trivial database and
cache round-trip. It shares no code path with §4.1's checks, and it is deliberately the only
endpoint that computes anything inline.

---

## 5. The check set

Severity is mapped onto §15.6's priority bands so an alert is triageable from a phone.

| Check | Source | Fails when | Band |
| --- | --- | --- | --- |
| `DatabaseCheck` | built-in | Database unreachable | Critical |
| `RedisCheck` | built-in | Redis unreachable | Critical |
| `HorizonCheck` | built-in | Horizon not active | Critical |
| `QueueCheck` (`audit`) | built-in | Heartbeat job not round-tripped within 10 min | Critical |
| `OldestPendingAuditCheck` | custom | Oldest `queued` > 30 min, **or** oldest `analyzing` > 30 min | Critical |
| `AuditPipelineFailureRateCheck` | custom | > 40% of attempted runs in 24 h ended `failed` | High |
| `MailFailureRateCheck` | custom | > 25% of email log rows in 24 h are `failed` | High |
| `ScheduleCheck` | built-in | Heartbeat stale > 10 min | Medium |
| `UsedDiskSpaceCheck` | built-in | > 85% used | Medium |
| `CacheCheck` | built-in | Cache unwritable | Medium |

**Why `needs_followup` is not a pipeline failure.** `needs_followup` marks *user-caused* outcomes —
a private repository, a repository over the size limit, a submission with no URL — where the
pipeline ran and concluded correctly. Counting them as system failures would let three private or
typo'd repositories out of six submissions read as a 50% failure rate at pre-launch volume, pinning
`/health` at 503 for up to 24 hours; worse, while the endpoint is already 503 a genuinely dead
scheduler produces no change in signal, so the false alarm masks the staleness dead-man's switch.
Such a request still counts in the denominator — the pipeline did attempt it — and the denominator
is *attempted runs* (`analysis_started_at` inside the window), not all submissions, so statuses like
`new` and `pending_verification` that never ran cannot dilute the rate.

### Why the two custom liveness signals both exist

`QueueCheck` proves *a* worker round-trips *its own* heartbeat job. It stays green while real audit
jobs pile up behind a poison message or a wedged clone. `OldestPendingAuditCheck` watches the age of
the oldest genuinely pending request, which is §18.5 SC1's requirement to alert on oldest-pending
age rather than depth alone — depth alone hides a stalled queue.

The `analyzing` arm covers the other half of O1: a worker that dies mid-run strands its request in
`analyzing` permanently, and nothing in the system currently notices. With `clone_timeout` at 120 s,
30 minutes lies far outside any legitimate run.

### Why disk space is not filler

This is §18.3 O9. `max_repo_size_mb` is 500 and the audit supervisor runs `maxProcesses: 3`, so
concurrent clones can claim 1.5 GB of working set plus git objects, on a box that also hosts MySQL,
Redis, and Bugsink.

### Minimum-sample floor

Both rate checks require at least 5 samples in the window. Below the floor the check reports **Ok**,
not a computed rate. Without this, a single failure on a quiet day reads as a 100% failure rate and
the alert is noise. This mirrors the `benchmark_min_sample` rule already in `config/audit.php`.

### Thresholds

All values are env-overridable entries in one named block in the published `config/health.php` —
one authoritative entry each, per §14.5 and Appendix A.

| Setting | Default |
| --- | --- |
| Check run interval | 5 min |
| Result freshness window (503 threshold) | 15 min |
| `QueueCheck` failure window | 10 min |
| `ScheduleCheck` heartbeat max age | 10 min |
| Oldest `queued` age | 30 min |
| Oldest `analyzing` age | 30 min |
| Pipeline failure rate window / floor / threshold | 24 h / 5 / 40% |
| Mail failure rate window / floor / threshold | 24 h / 5 / 25% |
| Disk space | 85% |
| Alert throttle per check | 60 min |

The check interval, the two heartbeat windows, and the freshness window are chosen so no threshold
sits at or below the interval that feeds it — a heartbeat window equal to the run interval flaps on
ordinary scheduling jitter.

---

## 6. Error tracking

### Scrubbing is a component, not a setting

`RepositoryCloner:81` constructs `https://x-access-token:{token}@github.com/…`. It redacts that URL
in the two messages it throws itself, but anything Sentry captures beyond those messages —
breadcrumbs, process context, framework-level exceptions raised from the same call — is unredacted.

§15.1 makes "no repository access tokens in any log line" an `[R]`, and §18.4 names token redaction
as one of four rules that must be guarded by a test because a well-meaning future change can quietly
break it. Shipping an error tracker without scrubbing writes `AUDIT_GITHUB_TOKEN` into a second
datastore the first time a clone fails in an unanticipated way.

The `before_send` scrubber strips any `x-access-token:…@` credential pair and the configured token
value from message, breadcrumbs, and context. It has its own test (§8).

### Release context

`SENTRY_RELEASE` is set to the deployed git SHA by the Ploi deploy script. PR4 names release context
explicitly, so **this item is not satisfied without that injection**. The injection is a 9A-2 runbook
step; it is recorded here as a cross-phase dependency rather than allowed to fall between the two
specs.

`SENTRY_ENVIRONMENT` distinguishes production from staging.

### Correlation

Exceptions raised anywhere in the audit pipeline are tagged with the audit request's public
identifier. §15.3 makes that identifier the deliberate substitute for distributed tracing — it is
the one key spanning HTTP, queue, and mail contexts.

---

## 7. Alert routing and the smoke gate

### Routing

One `config/health.php` block holds the `alert_channels` list plus per-channel credentials. A
channel listed without credentials is **skipped with a logged warning, never thrown**. An alerting
path that crashes the health run converts a degraded system into a silent one — the exact failure
this phase exists to prevent.

### `app:smoke` assertions

Each is fast, read-only, and safe to repeat against production. The command sends no email and runs
no audit.

1. `/up` returns 200
2. `/health/ready` returns 200
3. No pending migrations
4. Configuration and routes are cached
5. Horizon is active with a worker on the `audit` queue
6. Mail transport is neither `log` nor `array` when the environment is production
7. A key public page renders 200 with the Vite manifest present

Assertion 7 guards the documented "Vite manifest not found" 500 that takes out `/pricing` — precisely
the class of breakage a smoke gate exists to catch.

---

## 8. Failure modes and testing

### Failure modes

| Mode | Answer |
| --- | --- |
| A check throws | spatie records `Crashed`, distinct from `Failed`. Crashed alerts identically. A check that crashes every run without alerting reads as coverage while measuring nothing. |
| A channel is unreachable | Short per-channel timeout, independently guarded. One hanging channel neither stalls the health run nor suppresses the others. |
| Bugsink is down | The SDK fails open with a short transport timeout. Error reporting must never surface as an error. |
| The throttle cannot be read | Throttle state lives in cache, and `RedisCheck` failing is when the alert matters most. A throttle lookup failure **falls through to sending**, never to suppressing. |
| Something recovers | Recovery notifications are enabled. Without them you learn something broke and never that it cleared, so you either assume the worst or stop trusting the channel. |

### Testing

PHPUnit, per `CLAUDE.md` — created with `php artisan make:test --phpunit`, not the Pest syntax in
`AGENTS.md`. The clock is frozen with `Carbon::setTestNow` throughout: §18.2 T7 already records one
time-sensitive flaky test in this suite, and the rate checks are entirely time-windowed.

- **Custom checks** at their boundaries: empty table; just under and just over each threshold; below
  and at the minimum-sample floor. `OldestPendingAuditCheck` gets an explicit case proving old
  requests in *terminal* states (`sent`, `handled`, `failed`) do not alert — otherwise the check
  fires forever on historical data.
- **`/health/ready`**: 200 normally; 503 with the database down; and, under
  `Http::preventStrayRequests()`, a test proving it makes no external call. That turns §15.5's
  "readiness must never be gated by a dependency" from prose into a guard.
- **`HealthResultsController`**: fresh and healthy → 200; fresh with a failing **Critical** check →
  503; fresh with a failing **High** check → 503; fresh with a failing **Medium** check → **200**,
  with the failure present in the body; a **crashed** check behaves exactly as a failing check of the
  same band; stale results → 503; no results at all → 503; wrong token → 404; absent token → 404.
- **The scrubber**: a fixture exception carrying a real-shaped `x-access-token:…@` pair, asserting
  the outbound payload contains no token substring. This is §18.4's guarded rule.
- **Notifications** under `Notification::fake()`: fan-out to configured channels only; a
  credential-less channel skipped without throwing; throttle honored; recovery sent.
- **`app:smoke`**: exit 0 when healthy, plus a separate assertion per precondition that it exits
  non-zero.

### What these tests do not prove

Stated plainly because PR18 requires it. None of the following is verifiable in the suite:

- that Ploi's uptime monitor actually polls `/health`
- that Telegram and Slack actually deliver
- that Bugsink actually receives an event
- that the release SHA actually lands in captured events

All four are observed-evidence items for 9A-2. Until then the correct report is **"not verified"**,
never "done".

### Gates

- `php artisan test --compact` green
- `vendor/bin/pint --dirty --format agent` clean
- `vendor/bin/phpstan analyse` introducing no new error category

---

## 9. Exit criteria

9A-1 is complete when:

- PR5 is satisfied in code: liveness, readiness, worker health, and scheduler health checks exist,
  and no dependency check gates readiness — proven by the `preventStrayRequests` test.
- PR6's in-app half is satisfied: the §15.6 alert set is configured with worker-liveness alerting
  present, covering both the heartbeat signal and oldest-pending age.
- PR4's in-repo half is satisfied: the SDK is wired, exceptions are tagged with the audit request
  identifier, and the scrubber is proven by test. The release-context half carries into 9A-2.
- `app:smoke` exists and its exit-code contract is proven by test.
- All three gates pass.

**Carried into 9A-2 as explicit dependencies:** Bugsink container provisioning, `SENTRY_RELEASE`
injection in the Ploi deploy script, Ploi uptime monitor pointed at `/health` with its token, the
Telegram bot and Slack webhook credentials, and first observed evidence for each of the four
unproven items above.
