# Phase 9A-2 — Launch operations

**Date:** 2026-08-02
**Branch:** `growth-retention`
**Source of truth:** `docs/2026-07-27-flexpick-platform-specification.md` (revision 2026-08-01).
**Expands:** `docs/2026-08-01-remaining-phases.md` § "Phase 9A — Launch-blocking operations".
**Companion:** `docs/superpowers/specs/2026-08-02-launch-blocking-operations-design.md` (9A-1).
**Spec sections:** §15, §17 Phase 9, §18.3 O6/O8, §20.3 PR4, PR8, PR9, PR13, PR17, PR18.

---

## 1. Scope

9A-1 built everything observability-related that lives in the repository and can be driven by
tests. 9A-2 is the other half: the infrastructure that observability was built to watch, and the
four launch-blocking controls that only exist once someone performs them against real systems.

9A-1's spec framed 9A-2 as "everything verifiable only by doing it against real infrastructure."
That framing is very slightly wrong, and correcting it is the first decision below. Several of
9A-2's manual steps invoke commands and pipelines that **do not exist yet** — `deploy.php` is
still SaaSykit boilerplate pointing at `1.2.3.4`, no root `.github/workflows` exists, and
`SENTRY_RELEASE` has nothing injecting it. A runbook step reading "run the deploy with the smoke
gate" is not executable until the smoke gate is written.

Phase 9A-2 therefore has two halves, in order:

| | Contents | Form |
| --- | --- | --- |
| **In-repo enablers** | `deploy.php` rewrite, CI + staging-deploy workflows, `SENTRY_RELEASE` injection, Postmark env wiring, suite flake fix | Code, verified by use |
| **The runbook** | DNS, Bugsink, staging, Postmark, alert channels, production, uptime monitor, rollback rehearsal, marketing site, support ownership | Executable document with an evidence log |

**Out of scope, deliberately:**

- Everything already assigned to Phase 9B: the operations dashboard (§15.7), dependency health
  checks (§15.5), metrics collection beyond what the checks compute (§15.2).
- The six non-blocking items deferred out of 9A-1 (boundary-value check coverage, `bounced`
  counting in `MailFailureRateCheck`, the untested Vite-manifest branch of `app:smoke`, the inert
  Spatie `notifications` block, cache-outage alert throttling, `/health/ready` disclosure). One of
  these — `bounced` counting — becomes *possible* for the first time in this phase, since Postmark
  is the event source. It stays deferred; this phase only removes its blocker.
- Any change to audit-pipeline behaviour. This phase deploys the product; it does not modify it.

---

## 2. Established context

Verified against the working tree on 2026-08-02, not assumed:

| Item | State |
| --- | --- |
| `backend/deploy.php` | Untouched boilerplate — host `1.2.3.4`, domain `yourdomain.com`, repo `username/saasykit`, `$subDirectory = ''`. No smoke step, no rollback task, no release injection. |
| Root `.github/` | Does not exist. |
| `frontend/.github/workflows/actions.yaml` | Exists and is git-tracked, but GitHub Actions only reads workflows from the repository root. In this monorepo it has **never run**, and its bare `npm ci` would fail at root regardless. Inert template residue. |
| Frontend deploy config | `netlify.toml`, `vercel.json`, and a `Dockerfile` + `nginx/` all present, all unused. |
| Domains | `frontend/src/config.yaml`: marketing `https://flexpick.net`, app `https://app.flexpick.net`. |
| Postmark | **Already wired.** `symfony/postmark-mailer` in `composer.json`, `postmark` mailer in `config/mail.php`, `services.postmark.token` defined. Only env values are missing. |
| Sentry SDK | `sentry/sentry-laravel` present; `SENTRY_RELEASE` ships blank in `.env.example` with a comment saying the deploy script injects it. Nothing does. |
| `DatabaseSeeder` | `callOnce` over eight reference-data seeders (intervals, currencies, providers, roles, monetization). Safe to run on every production deploy; the existing `after('artisan:migrate', 'artisan:db:seed')` hook stays. |
| `tests/Feature/FeatureTest.php` | `migrate:fresh` + `TestingDatabaseSeeder` once per process via a static flag. No `RefreshDatabase`, no `DatabaseTransactions` — rows never roll back. |
| `UserFactory` | `'email' => fake()->unique()->safeEmail()`. |
| `app:smoke` | Exists at `app/Console/Commands/SmokeCommand.php`; exit code is the contract, proven by test. |

### 2.1 Root cause of the suite flake

Recorded because the carried-over note in `remaining-phases.md` names the symptom, not the cause.

Faker's `unique()` pool is rebuilt per application instance, which Laravel refreshes between
tests. The `users` rows it must avoid are **never rolled back**. So a fresh pool re-emits an
address a much earlier test already committed, and the collision probability rises the later a
file runs in the process. This is why the failure appears in a different file on different runs —
`LemonSqueezyControllerTest`, `InvoiceServiceTest`, `SubscriptionService` — and why the file that
fails is never the file that is broken.

---

## 3. Decisions

| # | Decision | Rationale |
| --- | --- | --- |
| D9A2.1 | Deliver both halves, enablers first | A runbook citing commands that do not exist cannot be executed or verified |
| D9A2.2 | Staging is a **second Ploi site on the existing server** | No second server cost; exercises the real deploy path. Accepts that a staging spike touches production CPU — tolerable while production has no traffic |
| D9A2.3 | ESP is **Postmark** | Transactional deliverability is load-bearing for gated audit reports; full event webhooks keep deferred delivery/bounce tracking a config change (§ PR13's stated criterion). Already wired in code |
| D9A2.4 | CI **gates PRs and auto-deploys staging**; production is a deliberate human `dep deploy` | Keeps a human at the production boundary before any deploy has ever been rehearsed, and makes the rollback drill meaningful |
| D9A2.5 | Marketing site is a **third Ploi site**; Netlify/Vercel/Docker configs pruned | One vendor, one place to look. Three competing unused deploy configs are a maintenance trap |
| D9A2.6 | Support: **solo owner**, next-business-day for `needs_followup`, same-day for `failed` | PR17 requires an owner *and* a window. A commitment that can actually be met beats an aspirational one |
| D9A2.7 | Flake fix: **ULID local-part in `UserFactory`**; `FeatureTest` rollback recorded as follow-up | Removes dependence on Faker state entirely, in two lines, with no change to suite semantics on the eve of launch |

### 3.1 D9A2.7 — what is deliberately not fixed

Adding `DatabaseTransactions` to `FeatureTest` would fix this flake *and* every future
unique-column flake. It is the correct long-term fix and it is not being done now, because it
changes the isolation semantics of the entire suite and any test leaning on state left by an
earlier test would begin failing — a tail of unknown size, immediately before a launch. The
factory fix is provably sufficient for the observed failure. The design flaw is recorded as a
follow-up, not pretended away.

---

## 4. Server layout

One Ploi server, four things:

| Site | Serves | Isolation |
| --- | --- | --- |
| `flexpick.net` | Astro `dist/` static output | none needed |
| `app.flexpick.net` | Laravel production | DB `flexpick`, Redis DB **0**, supervisor `horizon-prod` |
| `staging.app.flexpick.net` | Laravel staging | DB `flexpick_staging`, Redis DB **1**, supervisor `horizon-staging` |
| Bugsink | Docker, bound to loopback, Ploi-proxied with TLS | own container and volume |

### 4.1 The isolation that actually matters

The Redis database index and the Horizon supervisor/prefix split are load-bearing. Sharing either
one means staging workers dequeue **production audit jobs** — running real users' analyses against
staging's config, its AI credentials, and its mail transport. Separate `REDIS_DB` and a distinct
Horizon prefix per environment are not tidiness; they are the boundary.

Postmark gets **two Message Streams**, selected per environment through the mailer's
`message_stream_id`. PR9 requires staging to prove *live* email delivery — Mailpit cannot satisfy
it — so staging must genuinely send, and a separate stream keeps that sending off production's
reputation.

Staging's scheduler runs against its own database, so `app:run-scheduled-audits` there cannot
touch a real user's audit.

---

## 5. In-repo enablers

### 5.1 `backend/deploy.php`

Rewritten from boilerplate. Real host, `app.flexpick.net`, the real repository, and
`$subDirectory = 'backend'` — the current empty value is wrong for this monorepo and would deploy
the repository root, which has no `composer.json`.

Two Deployer hosts, `production` and `staging`, so one file drives both and staging is deployed by
the same code path it is meant to rehearse.

Three tasks added:

- **`deploy:sentry-release`** — writes the deployed git SHA into the release's environment as
  `SENTRY_RELEASE`. This closes PR4's outstanding half; without it, captured events carry no
  release context and a regression cannot be tied to a deploy.
- **`deploy:smoke`** — runs `php artisan app:smoke` and fails the deploy on a non-zero exit.
- **rollback wiring** — `deploy:failed` unlocks and surfaces the `dep rollback` invocation. Deployer
  ships the rollback primitive; what is missing today is that nothing runs it and nobody has.

### 5.2 Smoke gate placement — a stated trade-off

`app:smoke` asserts against the running application, so it must execute **after** the symlink
swap. That means a failing smoke leaves the bad release briefly live until rollback completes.

The alternative — smoking the release directory before the swap — cannot see the real URL, TLS, or
the served Vite manifest, which is most of what the command checks. So the exposure window is
accepted, bounded by the rehearsed rollback in runbook step 8, and stated explicitly here rather
than discovered during an incident.

### 5.3 GitHub Actions

New root `.github/workflows/`:

- **`ci.yml`** — path-filtered. Backend job on `backend/**`: MySQL and Redis services, PHP 8.4,
  `php artisan test`, `pint --test`, `phpstan analyse`. Frontend job on `frontend/**`: Node 22,
  `npm run check` and `npm run build`. Path filtering keeps a marketing copy edit from running the
  PHP suite.
- **`deploy-staging.yml`** — on merge to `main`, deploys staging via Deployer over SSH and runs
  `app:smoke` against `staging.app.flexpick.net`.

`frontend/.github/` is deleted. It has never executed and keeping it invites the belief that
frontend CI exists.

### 5.4 Postmark and the suite flake

Postmark needs only env wiring — `MAIL_MAILER=postmark`, `POSTMARK_TOKEN`, a per-environment
message stream, and `.env.example` documentation. No package, no config change.

`UserFactory`'s email becomes a ULID local-part, unique by construction and independent of Faker's
per-instance state.

### 5.5 Marketing site

Per D9A2.5 the marketing site is served from the same Ploi server, so `netlify.toml`,
`vercel.json`, and `frontend/Dockerfile` + `frontend/nginx/` are deleted. Three unused deploy
configurations for a site with one deployment target is a trap: the next person to touch it has no
way to know which one is real.

Deployment is Ploi's own static-site script — pull, `npm ci`, `npm run build`, publish `dist/` —
not Deployer. Astro emits static output with no PHP, no migrations, and no queue, so routing it
through the Laravel release/rollback machinery would add ceremony without adding a single
guarantee. Its rollback is a redeploy of the previous commit.

---

## 6. The runbook

### 6.1 Ordering

Three constraints fix the sequence. DNS propagation is the long pole, so every record is created
in step 1 and verified later. Staging must be fully exercised before production, or the first
production deploy *is* the rehearsal — precisely what PR9 exists to prevent. And alert delivery
must be proven before production serves traffic, because a day-one outage with silent alerting is
indistinguishable from having no monitoring at all.

| # | Step | Gate before proceeding |
| --- | --- | --- |
| 0 | Secrets inventory — every credential named, storage location, rotation owner | List complete; nothing in git |
| 1 | **All DNS at once** — A records for three hosts, Postmark DKIM + Return-Path CNAMEs, SPF, DMARC at `p=none` | Records created |
| 2 | Server prep + **Bugsink** container, loopback-bound, Ploi-proxied, TLS | A hand-fired test event appears in Bugsink |
| 3 | **Staging site** + first deploy | `dep deploy staging` green; `app:smoke` exits 0 |
| 4 | **Postmark** — DKIM/SPF/DMARC verified propagated; live send from staging | Real inbox receipt + `AuditEmailLog` row + successful resend |
| 5 | **Alert channels** — Telegram bot, Slack webhook, mail | A forced check failure delivers on all three, then recovers |
| 6 | **Production site** + first deploy | `app:smoke` exits 0 against `app.flexpick.net` |
| 7 | **Ploi uptime monitor → `/health`** with a real token, plus a non-2xx rule | Monitor green; a forced 503 pages you |
| 8 | **Rollback rehearsal** — staging first, then production | Two timed rollbacks, both recovering to a working app |
| 9 | Marketing site deploy | `flexpick.net` serves; CTA reaches `app.flexpick.net` |
| 10 | **Support ownership** recorded, plus the daily check enforcing it | Owner and both windows written down |

### 6.2 Step shape

Every step is **preconditions → exact commands → expected output → verification → what to do if it
fails**. No step says "configure Postmark" and moves on; it names the screen, the value, and what
should come back. A step that cannot be verified does not get a checkbox.

### 6.3 The two rehearsals that carry the phase

**Step 5 is deliberately destructive.** Set `HEALTH_OLDEST_QUEUED_MINUTES=0` on staging, run
`app:health-alerts`, confirm all three channels deliver, restore the value, confirm the recovery
notification arrives. This is the only way to prove 9A-1's alerting works end to end: the suite
proved fan-out under `Notification::fake()` and never proved delivery. It also exercises
`MailAlertChannel`'s self-guarding behaviour against a real transport.

**Step 8 measures rather than merely performs.** Record wall-clock from "decision to roll back" to
"smoke green on the previous release." That number is the real recovery objective, and guessing it
is how outages become long ones. Staging first, so the production rehearsal is the second attempt.

### 6.4 Step 7 and the database-outage gap

The item carried out of 9A-1 is correct and is not resolved by this phase. Both the health result
store and `app:health-alerts` read MySQL, so `DatabaseCheck` failing means the alerter is blind
too, and `/health` returns 500 rather than the designed 503.

Step 7 therefore configures **two** monitor rules — an interval check against `/health` and an
explicit non-2xx alert — and the runbook states plainly that MySQL being down is detected off-box
or not at all. Structural, mitigated as far as an off-box monitor allows, documented rather than
hidden.

---

## 7. Verification

`deploy.php` and the workflows are not meaningfully unit-testable. They are proven by use:

| Claim | How it is proven |
| --- | --- |
| CI gates real failures | A PR that goes red on a deliberately broken test, then green when fixed |
| `deploy:smoke` blocks a bad release | Break a precondition on staging; confirm the deploy fails non-zero |
| `SENTRY_RELEASE` is injected | A captured Bugsink event carrying the deployed SHA |
| Alerts deliver | Step 5's forced failure, observed on all three channels, plus recovery |
| Mail is deliverable | Step 4's real inbox receipt, with DKIM and SPF passing in the received headers |
| Rollback works | Step 8's two timed rehearsals |
| Flake is gone | Full suite, run twice, green |

Gates for the in-repo half, unchanged from 9A-1: `php artisan test --compact` green,
`vendor/bin/pint --dirty --format agent` clean, `vendor/bin/phpstan analyse` introducing no new
error category.

### 7.1 The evidence log

The runbook ends with a table: one row per claim, with actual pasted output. Under PR18, anything
not observed is recorded as **"not verified"** rather than checked off.

Two entries are expected to land there at completion, and are predicted here rather than
discovered later:

- **DMARC stays at `p=none`.** Moving to quarantine or reject before watching a week of aggregate
  reports risks silently dropping the product's own audit-report emails, which are the deliverable.
- **The database-outage path (§6.4)** remains off-box-only.

---

## 8. Exit criteria

Phase 9A-2 is complete when:

- **PR8** — deploy automation exists with a post-release smoke gate, and rollback has been
  documented *and rehearsed*, with a measured recovery time.
- **PR9** — staging exists, is deployed by the same path as production, and has demonstrated live
  email delivery.
- **PR13** — Postmark is configured, SPF/DKIM/DMARC verified in received headers, and send → log
  row → resend proven end to end.
- **PR17** — a named owner and both response windows are recorded, with a daily signal that
  surfaces `failed` and `needs_followup` rather than relying on vigilance.
- **PR4** — closed in full: the release-context half carried out of 9A-1 is satisfied by an
  observed event carrying the deployed SHA.
- **D9A2.5** — `flexpick.net` serves the built marketing site and its call-to-action reaches
  `app.flexpick.net`, with the three superseded deploy configurations removed.
- All three in-repo gates pass, and the evidence log is complete — including its "not verified"
  rows.

### 8.1 Carried forward

- `FeatureTest` never rolls back (§3.1). The next unique column hits the same wall.
- `MailFailureRateCheck` counts `failed` but not `bounced`. Postmark's bounce webhook removes the
  blocker; the fix itself stays in 9B.
- The database-outage alerting gap (§6.4).
