# Phase 9A-2 — Launch operations runbook

**Date:** 2026-08-02
**Spec:** `docs/superpowers/specs/2026-08-02-launch-operations-runbook-design.md`
**Plan:** `docs/superpowers/plans/2026-08-02-launch-operations.md`
**Satisfies:** PR4 (release context), PR8, PR9, PR13, PR17, D9A2.5

This document stands up the production infrastructure. Every command it cites exists in the
repository today — tasks 1–9 of the plan built them first, precisely so this document could be
executable rather than aspirational.

**How to use it.** Work the steps in order; the ordering is not a preference (§6.1). DNS is first
because propagation is the long pole. Staging is fully exercised before production, or the first
production deploy *is* the rehearsal — the thing PR9 exists to prevent. Alert delivery is proven
before production serves traffic, because a day-one outage with silent alerting is
indistinguishable from having no monitoring at all.

**Every step has the same shape:** preconditions → exact commands → expected output →
verification → what to do if it fails. Do not tick a checkbox you did not personally observe.
Paste the actual output into the evidence log at the end. Under PR18, "not verified" is the
correct entry where evidence is absent — it is never a checkmark.

**Fixed facts this runbook assumes:**

| | |
| --- | --- |
| Marketing site | `flexpick.net` (Astro static) |
| Production app | `app.flexpick.net`, deploy path `~/app`, database `flexpick` |
| Staging app | `staging.app.flexpick.net`, deploy path `~/staging`, database `flexpick_staging` |
| Server | One Ploi server hosting all of the above plus Bugsink |
| Repository | `git@github.com:serhii-f8/flexpick.net.git`, monorepo, app in `backend/` |
| Deployer aliases | `production` and `staging` (`backend/deploy.php`) |
| Redis | production `REDIS_DB=0` / `REDIS_CACHE_DB=1`; staging `2` / `3` |
| Horizon supervisors | `horizon-production` and `horizon-staging` |

> **Naming note.** Spec §4 writes the production supervisor as `horizon-prod`. The implementation
> derives the program name from the Deployer host alias, so it is `horizon-production`. Use the
> implementation's name — it is what `provision:supervisor` actually writes.

---

## Step 0 — Secrets inventory

**Preconditions:** none. Do this before touching a server, so no credential is invented ad hoc
and then lost.

**Commands:** none. This step produces a written record.

Fill this table in your password manager — **not** in this file, and not in git:

| Secret | Where it lives | Used by | Rotation owner |
| --- | --- | --- | --- |
| `DEPLOY_SSH_KEY` | GitHub repo secret (private half); public half in the server's `deployer` `authorized_keys` | `deploy-staging.yml` | you |
| `DEPLOY_HOST` | GitHub repo secret | `deploy-staging.yml`, `backend/deploy.php` | you |
| `POSTMARK_TOKEN` (production) | production `.env` on the server | mail transport | you |
| `POSTMARK_TOKEN` (staging) | staging `.env` on the server | mail transport | you |
| `SENTRY_LARAVEL_DSN` | both server `.env` files | error tracking | you |
| `HEALTH_ENDPOINT_TOKEN` | both server `.env` files + the Ploi monitor URL | `/health` | you |
| `HEALTH_TELEGRAM_BOT_TOKEN` | both server `.env` files | alerting | you |
| `HEALTH_TELEGRAM_CHAT_ID` | both server `.env` files | alerting | you |
| `HEALTH_SLACK_WEBHOOK_URL` | both server `.env` files | alerting | you |
| `ANTHROPIC_API_KEY` | both server `.env` files | the audit pipeline's AI step | you |
| Database password (production) | Ploi + production `.env` | `flexpick` | you |
| Database password (staging) | Ploi + staging `.env` | `flexpick_staging` | you |
| `APP_KEY` (×2) | generated per environment, stored in the manager | encryption | you |

**Verification:** every row has a value and a location. Then confirm nothing leaked into the
repository:

```bash
cd /var/www/html/flexpick.net
grep -nE "POSTMARK_TOKEN=.+|SENTRY_LARAVEL_DSN=.+|HEALTH_ENDPOINT_TOKEN=.+|ANTHROPIC_API_KEY=.+" backend/.env.example
git log --all -p -- backend/.env | grep -c . || echo "backend/.env never committed"
```

**Expected output:** the first command prints nothing. The second prints
`backend/.env never committed`.

**If it fails:** a match in `.env.example` is a committed secret — remove it, rotate the
credential, and only then continue. A tracked `backend/.env` means history rewriting, not just
deletion.

- [ ] Secrets inventory complete, nothing in git

---

## Step 1 — All DNS at once

**Preconditions:** step 0. Registrar access. A Postmark account with both Message Streams
created (`outbound` for production, a second stream for staging), because its DKIM and
Return-Path records are created here and verified in step 4.

**Records to create, all in one sitting:**

| Type | Name | Value | Why here |
| --- | --- | --- | --- |
| A | `flexpick.net` | server IP | marketing site |
| A | `app.flexpick.net` | server IP | production app |
| A | `staging.app.flexpick.net` | server IP | staging app |
| CNAME | Postmark DKIM host (from the Postmark UI) | Postmark's value | verified in step 4 |
| CNAME | Postmark Return-Path host | Postmark's value | verified in step 4 |
| TXT | `flexpick.net` | `v=spf1 a mx include:spf.mtasv.net ~all` | Postmark's sending host |
| TXT | `_dmarc.flexpick.net` | `v=DMARC1; p=none; rua=mailto:<your address>` | see below |

DMARC starts at `p=none` and stays there until a week of aggregate reports has been reviewed.
Moving to `quarantine` or `reject` sooner risks silently dropping the product's own audit-report
emails — which *are* the deliverable. This is a predicted evidence-log entry, not an oversight.

**Verification:**

```bash
dig +short A flexpick.net app.flexpick.net staging.app.flexpick.net
dig +short TXT flexpick.net
dig +short TXT _dmarc.flexpick.net
```

**Expected output:** three A records resolving to the server IP; an SPF record containing
`include:spf.mtasv.net`; a DMARC record containing `p=none`.

**If it fails:** propagation, not configuration, is the usual cause. Re-check after an hour
before changing anything. Continue to step 2 while waiting — nothing before step 4 depends on
DNS having propagated.

- [ ] Three A records resolve
- [ ] SPF present
- [ ] DMARC present at `p=none`
- [ ] Postmark DKIM and Return-Path CNAMEs created (verification is step 4)

---

## Step 2 — Server prep and Bugsink

**Preconditions:** step 1's records created. A Ploi server provisioned with PHP 8.4, MySQL,
Redis, Supervisor and Node.

**Commands** (on the server):

```bash
# 1. Bugsink, bound to loopback only. Ploi terminates TLS and proxies to it;
#    binding to 0.0.0.0 would expose an unauthenticated ingest endpoint.
docker run -d --name bugsink \
  --restart unless-stopped \
  -p 127.0.0.1:8000:8000 \
  -v bugsink-data:/data \
  -e SECRET_KEY=<generate one> \
  -e CREATE_SUPERUSER=<user>:<password> \
  -e BEHIND_HTTPS_PROXY=True \
  bugsink/bugsink:latest

curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/
```

Then create a Ploi site for the Bugsink hostname, point its nginx block at
`http://127.0.0.1:8000`, and issue a certificate.

**Expected output:** the `curl` prints `200` or `302`.

**Verification** — a hand-fired event must actually land, not merely be sent. From your
workstation, with the project's DSN:

```bash
curl -sS -X POST '<SENTRY_LARAVEL_DSN store endpoint>' \
  -H 'Content-Type: application/json' \
  -d '{"message":"runbook step 2 smoke event","level":"error","platform":"other"}'
```

Open Bugsink in a browser and confirm the event is listed.

**If it fails:** a 4xx on ingest is almost always the DSN's project id or key. If the container
answers on loopback but the public hostname does not, the fault is in the Ploi nginx block, not
in Bugsink. Do not proceed — steps 3 and 6 both rely on captured errors being visible.

- [ ] Bugsink reachable over TLS at its hostname
- [ ] A hand-fired test event is visible in the UI

---

## Step 3 — Staging site and first deploy

**Preconditions:** steps 1 and 2. GitHub deploy key installed (the public half of
`DEPLOY_SSH_KEY` added to the repository's Deploy Keys, read access is enough).

**3a. Create the site in Ploi:** hostname `staging.app.flexpick.net`, web root pointing at the
Deployer layout (`/home/deployer/staging/current/public`), PHP 8.4, and a database
`flexpick_staging` with its own user.

**3b. Write `/home/deployer/staging/shared/.env`.** Start from `backend/.env.example` and set at
minimum:

```
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging.app.flexpick.net
APP_KEY=<generated>

DB_DATABASE=flexpick_staging
DB_USERNAME=<staging user>
DB_PASSWORD=<staging password>

# Isolation. Production takes 0/1; staging MUST take 2/3, or staging workers
# dequeue production audit jobs and run real users' analyses.
REDIS_DB=2
REDIS_CACHE_DB=3
REDIS_PREFIX=flexpick_staging_database_
HORIZON_PREFIX=flexpick_staging_horizon:

MAIL_MAILER=postmark
POSTMARK_TOKEN=<staging token>
POSTMARK_MESSAGE_STREAM_ID=<staging stream>

SENTRY_LARAVEL_DSN=<dsn>
SENTRY_ENVIRONMENT=staging
# SENTRY_RELEASE is injected by deploy:sentry-release. Leave it out.

HEALTH_ENDPOINT_TOKEN=<long random value>
HEALTH_ALERT_CHANNELS=mail
```

Leave the four `AUDIT_*_BIN` keys **commented out** so the `/opt/flexpick/bin` defaults apply. A
key that is present but blank resolves to an empty string, not to the default, and every scanner
would report unavailable.

**3c. Install the scanner binaries.** Ploi does not provision these, and `app:smoke` asserts
them, so a deploy without them fails its own gate. As root on the server:

```bash
cd /var/www/html/flexpick.net/backend   # or any checkout containing deploy.php
php vendor/bin/dep provision:scanners staging
```

The task's `apt-get` calls need root; if the `deployer` user cannot escalate, run the equivalent
commands from `backend/deploy.php`'s `provision:scanners` task by hand as root.

**3d. Supervisor.** Create the `horizon-staging` program (Ploi's daemon UI or
`dep provision:supervisor staging`) running
`php /home/deployer/staging/current/artisan horizon`.

**3e. Deploy:**

```bash
cd /var/www/html/flexpick.net/backend
DEPLOY_HOST=<server ip or hostname> php vendor/bin/dep deploy staging
```

**Expected output:** every task green, ending with `deploy:smoke` and then `deploy:success`. The
smoke gate is meaningful on staging — `SmokeCommand` exempts only `local` and `testing`, so all
nine assertions run under `APP_ENV=staging`.

**Verification:**

```bash
ssh deployer@<server> 'cd ~/staging/current && php artisan app:smoke; echo "exit=$?"'
curl -sS -o /dev/null -w '%{http_code}\n' https://staging.app.flexpick.net/up
curl -sS "https://staging.app.flexpick.net/health?token=<HEALTH_ENDPOINT_TOKEN>" | head -c 400
```

**Expected output:** `exit=0` with nine `PASS` lines; `200` from `/up`; JSON from `/health`
listing every check with a `band`.

**If it fails:** read which assertion failed — the command names it. `configuration and routes
cached` means `artisan:optimize` did not run; `horizon worker` means the supervisor program is
not running; `audit scanners provisioned` means 3c is incomplete or the `AUDIT_*_BIN` keys are
blank rather than absent. A failing deploy has already rolled back (`after('deploy:failed',
'rollback')`), so the site is on the previous release — on a *first* deploy there is no previous
release, and the site will be down until the next successful attempt. That is expected and is
why staging goes first.

**3f. Create the GitHub secrets** so merges to `main` deploy staging automatically:
`DEPLOY_SSH_KEY` (the private half) and `DEPLOY_HOST`, under the repository's `staging`
environment.

**3g. Verify CI actually gates a failure.** Task 3 of the plan could not prove this locally, so
it is proven here. Push a branch with a deliberately broken assertion, open a PR, and watch:

```bash
cd /var/www/html/flexpick.net
git switch -c ci-gate-rehearsal
# edit any test so it fails, e.g. flip an assertion in
# backend/tests/Unit/Factories/UserFactoryEmailTest.php
git commit -am "chore: deliberately break a test to prove CI gates"
git push -u origin ci-gate-rehearsal
gh pr create --fill
gh pr checks --watch
```

**Expected output:** the `backend` job goes **red**. Then revert the break, push, and confirm it
goes green. Delete the branch without merging.

**If it fails:** a green run on a broken test means the workflow is not running the suite —
check the path filter matched (`backend/**`) and that the `backend` job was not skipped.

- [ ] `dep deploy staging` green
- [ ] `app:smoke` exits 0 on staging, nine PASS lines
- [ ] `/health` returns JSON with a real token, 404s without one
- [ ] `DEPLOY_SSH_KEY` and `DEPLOY_HOST` secrets created
- [ ] CI observed red on a broken test, then green when fixed

---

## Step 4 — Postmark and live mail

**Preconditions:** step 3. Step 1's DKIM and Return-Path CNAMEs created at least an hour ago.

**Commands:**

1. In the Postmark UI, confirm the sender signature / domain shows **DKIM verified** and
   **Return-Path verified**.
2. Send a real message from staging:

```bash
ssh deployer@<server> 'cd ~/staging/current && php artisan tinker --execute="
  \Illuminate\Support\Facades\Mail::raw(\"runbook step 4 live send\", function (\$m) {
      \$m->to(\"<your real inbox>\")->subject(\"FlexPick staging live send\");
  });
  echo \"sent\n\";
"'
```

3. Then prove the *product's* path, not just the framework's: submit an audit on staging through
   the public form and let the pipeline deliver its report.

**Expected output:** `sent`, followed by the message arriving in a real inbox.

**Verification** — all four, not just receipt:

- The message is in the inbox (check spam; if it landed there, say so in the evidence log).
- Its raw headers show `dkim=pass` and `spf=pass`.
- An `AuditEmailLog` row exists for the audit-report send:

```bash
ssh deployer@<server> 'cd ~/staging/current && php artisan tinker --execute="
  echo \App\Models\AuditEmailLog::latest()->first()?->toJson(), \"\n\";
"'
```

- Resend from the admin panel's Audit Email Logs resource succeeds and produces a second receipt.

**If it fails:** a Postmark 422 naming the stream means `POSTMARK_MESSAGE_STREAM_ID` does not
match a stream on that server token. Delivery to spam with `dkim=pass` is a reputation matter,
not a configuration one — record it and continue. No `AuditEmailLog` row at all means the send
did not route through `AuditMailer`; that is a code defect, not a configuration one, and blocks
the phase.

- [ ] DKIM verified in Postmark
- [ ] Return-Path verified in Postmark
- [ ] Real inbox receipt with `dkim=pass` and `spf=pass` in the headers
- [ ] `AuditEmailLog` row written for a report send
- [ ] Resend succeeds

---

## Step 5 — Prove the alert channels deliver

**Preconditions:** Step 3 complete (staging deployed, `app:smoke` exits 0). A Telegram bot
created via @BotFather and its chat ID resolved; an incoming Slack webhook URL; a deliverable
address for `HEALTH_ALERT_MAIL_TO`.

**Why this step is destructive on purpose:** the 9A-1 suite proved alert fan-out under
`Notification::fake()`. It never proved delivery. The only way to prove delivery is to make a
check genuinely fail.

**Commands** (on the server, in the staging site directory):

```bash
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
```

**Expected output:** `app:health-alerts` reports a dispatched alert for
`OldestPendingAuditCheck`.

**Verification:** the alert arrives in **all three** of Telegram, Slack and the mailbox. Paste
each into the evidence log. If one channel is silent but the others deliver, that is the
channel's credentials — `MailAlertChannel` is self-guarding and will not let one failure kill the
others, which is the design being confirmed here.

**Restore and confirm recovery:**

```bash
HEALTH_OLDEST_QUEUED_MINUTES=30
php artisan config:clear
php artisan health:check
php artisan app:health-alerts
```

**Verification:** a recovery notification arrives on all three channels. A missing recovery
notification is a real defect, not a configuration slip — recovery delivery is one of the three
requirements that justified building custom alert dispatch instead of using Spatie's built-in
notifications.

**If it fails:** do not proceed to step 6. Production must not serve traffic with unproven
alerting; a day-one outage with silent alerts is indistinguishable from having no monitoring at
all.

- [ ] Forced failure delivered on Telegram
- [ ] Forced failure delivered on Slack
- [ ] Forced failure delivered by mail
- [ ] `HEALTH_OLDEST_QUEUED_MINUTES` restored to 30
- [ ] Recovery notification delivered on all three

---

## Step 6 — Production site and first deploy

**Preconditions:** steps 3, 4 and 5 all green. Staging has been exercised: deployed, sending
mail, alerting.

**Commands:** identical in shape to step 3, with the production values:

- Ploi site `app.flexpick.net`, web root `/home/deployer/app/current/public`, database `flexpick`.
- `/home/deployer/app/shared/.env` with `APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL=https://app.flexpick.net`, `REDIS_DB=0`, `REDIS_CACHE_DB=1`,
  `REDIS_PREFIX=flexpick_database_`, `HORIZON_PREFIX=flexpick_horizon:`,
  `MAIL_MAILER=postmark` with the **production** token and stream, `SENTRY_ENVIRONMENT=production`,
  a fresh `HEALTH_ENDPOINT_TOKEN`, and `HEALTH_ALERT_CHANNELS=mail,telegram,slack`.
- One production-only key the staging site does not need:
  `SESSION_DOMAIN=.flexpick.net`, so the marketing site's `/api/auth/status` call carries the app
  session cookie cross-subdomain (Q24).
- Scanner binaries are already installed system-wide at `/opt/flexpick/bin` from step 3c; confirm
  rather than reinstall.
- Supervisor program `horizon-production`.

```bash
cd /var/www/html/flexpick.net/backend
DEPLOY_HOST=<server ip or hostname> php vendor/bin/dep deploy production
```

**Expected output:** all tasks green through `deploy:smoke` and `deploy:success`.

**Verification:**

```bash
ssh deployer@<server> 'cd ~/app/current && php artisan app:smoke; echo "exit=$?"'
ssh deployer@<server> 'grep ^SENTRY_RELEASE= ~/app/shared/.env'
ssh deployer@<server> 'readlink -f ~/app/current/.env'
curl -sS -o /dev/null -w '%{http_code}\n' https://app.flexpick.net/up
```

**Expected output:** `exit=0`; a `SENTRY_RELEASE=` line carrying the deployed SHA; the `.env`
symlink still resolving into `shared/` (proving `deploy:sentry-release` edited the target rather
than replacing the link); `200` from `/up`.

Then close PR4 properly — release context is only proven by an *event*, not by a config line.
Trigger one deliberate error in production and confirm the Bugsink event carries the SHA:

```bash
ssh deployer@<server> 'cd ~/app/current && php artisan tinker --execute="
  \Sentry\captureMessage(\"runbook step 6 release-context probe\");
  echo \"captured\n\";
"'
```

**If it fails:** an empty `SENTRY_RELEASE` means `REVISION` was empty — the task throws rather
than deploying without release context, so this shows up as a failed deploy, not a silent gap. A
`.env` that is a regular file rather than a symlink means the symlink guard regressed; fix it
before the next deploy or shared config silently detaches.

- [ ] `dep deploy production` green
- [ ] `app:smoke` exits 0 against `app.flexpick.net`
- [ ] `SENTRY_RELEASE` carries the deployed SHA
- [ ] `~/app/current/.env` is still a symlink into `shared/`
- [ ] A Bugsink event shows the release

---

## Step 7 — Ploi uptime monitor

**Preconditions:** step 6. `HEALTH_ENDPOINT_TOKEN` set to a long random value on production —
the endpoint returns **404** on an empty token, deliberately, so it does not advertise itself.

**Commands:** in Ploi's monitoring, create **two** rules against production:

1. An interval check on `https://app.flexpick.net/health?token=<HEALTH_ENDPOINT_TOKEN>`, at most
   every 5 minutes.
2. An explicit alert on any non-2xx response from that URL.

**Why two rules, stated plainly:** both the health result store and `app:health-alerts` read
MySQL. If MySQL is down, the alerter is blind and `/health` returns 500 rather than the designed
503. **A database outage is detected off-box or not at all.** This is structural (§6.4), carried
forward from 9A-1, and mitigated only as far as an off-box monitor allows. The non-2xx rule is
what covers the 500.

The `/health` endpoint also returns 503 when results are **stale** — that arm is the dead-man's
switch for a dead scheduler, and it exists only at this endpoint. Until this monitor is live, a
dead scheduler is completely silent.

**Verification:**

```bash
# 1. The monitor's own URL answers 200 for you.
curl -sS -o /dev/null -w '%{http_code}\n' \
  "https://app.flexpick.net/health?token=<token>"

# 2. A wrong token 404s rather than leaking the endpoint's existence.
curl -sS -o /dev/null -w '%{http_code}\n' \
  "https://app.flexpick.net/health?token=wrong"

# 3. Force a 503 and confirm the monitor pages you: set a critical/high-band
#    check to fail, exactly as in step 5, and watch for the Ploi alert.
```

**Expected output:** `200`, then `404`, then a page from Ploi on the forced 503 — and the monitor
returning to green when the check is restored.

**If it fails:** a 404 with the correct token means `HEALTH_ENDPOINT_TOKEN` differs between the
`.env` and the monitor URL, or config caching predates the value (`php artisan config:clear`,
then redeploy). If the forced 503 does not page, the monitor exists but its alert channel does
not — fix that before relying on it.

- [ ] Interval monitor green against `/health`
- [ ] Non-2xx rule configured
- [ ] Wrong token returns 404
- [ ] A forced 503 paged, and recovery returned the monitor to green

---

## Step 8 — Rollback rehearsal

**Preconditions:** steps 3 and 6. At least two releases exist on each host (deploy twice if not).

This step **measures** rather than merely performs. Record wall-clock from "decision to roll
back" to "smoke green on the previous release". That number is the real recovery objective, and
guessing it is how outages become long ones.

**Commands — staging first:**

```bash
cd /var/www/html/flexpick.net/backend
DEPLOY_HOST=<host> php vendor/bin/dep releases staging      # note the current release
date +%T                                                    # decision time — write it down
DEPLOY_HOST=<host> php vendor/bin/dep rollback staging
ssh deployer@<server> 'cd ~/staging/current && php artisan app:smoke; echo "exit=$?"'
date +%T                                                    # recovery time
```

**Then production, as the second attempt:**

```bash
DEPLOY_HOST=<host> php vendor/bin/dep releases production
date +%T
DEPLOY_HOST=<host> php vendor/bin/dep rollback production
ssh deployer@<server> 'cd ~/app/current && php artisan app:smoke; echo "exit=$?"'
date +%T
```

**Expected output:** `rollback` reports the previous release symlinked; `app:smoke` exits 0 on
both; the site serves normally.

**Verification:** both timings recorded in the evidence log, and both sites working afterwards.
Then deploy forward again so both hosts sit on the current release.

**If it fails:** `rollback` refusing for want of a previous release means only one release
exists — deploy again and retry. A rollback that succeeds but fails smoke means the previous
release was already broken; that is a finding worth writing down, because it means the smoke gate
was not in place when that release shipped.

- [ ] Staging rollback rehearsed, time recorded
- [ ] Production rollback rehearsed, time recorded
- [ ] Both hosts redeployed forward afterwards

---

## Step 9 — Marketing site

**Preconditions:** step 1's A record for `flexpick.net`. Node 22.12+ available to Ploi's deploy
script.

**Commands:** create a Ploi **static** site for `flexpick.net` with a deploy script of:

```bash
cd $SITE_PATH
git pull origin main
cd frontend
npm ci
npm run build
```

Serve `frontend/dist/`, and add the immutable-cache header for Astro's fingerprinted assets in
the site's nginx block:

```nginx
location /_astro/ {
    add_header Cache-Control "public, max-age=31536000, immutable";
}
```

Rollback for this site is a redeploy of the previous commit — there is no release-directory
mechanism here, and none is needed for static output.

**Verification:**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://flexpick.net/
curl -sS https://flexpick.net/ | grep -o 'app\.flexpick\.net[^"]*' | head -3
curl -sS -D- -o /dev/null https://flexpick.net/_astro/ 2>/dev/null | grep -i cache-control
```

**Expected output:** `200`; at least one link into `app.flexpick.net` (the call-to-action);
`Cache-Control: public, max-age=31536000, immutable` on `/_astro/` assets.

**If it fails:** a 404 on the CTA target means the marketing build's `PUBLIC_APP_URL` still
points at localhost — rebuild with the production value. Missing cache headers are a performance
matter only; record and move on.

- [ ] `flexpick.net` serves the built site
- [ ] Its call-to-action reaches `app.flexpick.net`
- [ ] `/_astro/` assets carry immutable cache headers

---

## Step 10 — Support ownership

**Preconditions:** everything above. This step is written, not configured.

**The record (D9A2.6 — solo):**

| | |
| --- | --- |
| Owner of `failed` and `needs_followup` | Serhii Fedorenko (sole operator) |
| `failed` response window | **Same day** |
| `needs_followup` response window | **Next business day** |
| Escalation | None — single operator. If unavailable, the window is missed and that is the accepted risk at this scale. |

**The daily signal.** PR17 requires a signal that surfaces these states rather than relying on
vigilance. **No automated daily digest exists today.** What exists is:

- the admin panel's audit list, filtered to `failed` and `needs_followup`;
- `AuditAdminStatsWidget`'s tiles on the admin dashboard;
- `app:health-alerts` every five minutes, which pages on pipeline failure *rate* — not on an
  individual request sitting in `needs_followup`.

So the daily signal is currently **a calendar reminder to open the filtered admin list**, which is
vigilance with a nudge, not automation. Record it honestly in the evidence log as *partially
satisfied*, and carry "build a daily `failed` / `needs_followup` digest" into 9B. Do not tick a
box claiming automation that does not exist.

**Verification:** the owner and both windows appear in this table, and a recurring daily reminder
exists in the operator's calendar.

**If it fails:** an unstaffed state is worse than an unmonitored one — a customer is waiting.
Either staff it or change the product so the state cannot arise.

- [ ] Owner and both windows recorded
- [ ] Daily reminder created
- [ ] Automated digest carried to 9B as a known gap

---

## Evidence log

One row per claim from spec §7. Paste the **actual** output. Under PR18, anything not personally
observed is **not verified** — never a checkmark.

| Claim | How proven | Observed output | Status |
| --- | --- | --- | --- |
| CI gates real failures | Step 3g: PR red on a broken test, green when fixed | | not verified |
| `deploy:smoke` blocks a bad release | Step 3e / a deliberate break on staging | | not verified |
| `SENTRY_RELEASE` is injected | Step 6: Bugsink event carrying the deployed SHA | | not verified |
| Alerts deliver on Telegram | Step 5 forced failure | | not verified |
| Alerts deliver on Slack | Step 5 forced failure | | not verified |
| Alerts deliver by mail | Step 5 forced failure | | not verified |
| Recovery notification delivers | Step 5 restore | | not verified |
| Mail is deliverable | Step 4: inbox receipt, `dkim=pass`, `spf=pass` | | not verified |
| `AuditEmailLog` row + resend | Step 4 | | not verified |
| Rollback works — staging | Step 8, with measured time | | not verified |
| Rollback works — production | Step 8, with measured time | | not verified |
| Health monitor pages on 503 | Step 7 forced failure | | not verified |
| Marketing site serves, CTA reaches the app | Step 9 | | not verified |
| Flake is gone | Full suite run twice, green | 836 passed, 1 pre-existing risky, both runs (2026-08-03, local) | **observed locally; not yet observed in CI** |

**Predicted entries — recorded here in advance rather than discovered later:**

| Entry | Status |
| --- | --- |
| DMARC stays at `p=none` until a week of aggregate reports has been reviewed. Moving to quarantine or reject sooner risks silently dropping the product's own audit-report emails, which are the deliverable. | **accepted, by design** |
| A database outage is alertable off-box only. Both the result store and `app:health-alerts` read MySQL, so `DatabaseCheck` failing leaves the in-app alerter blind and `/health` returning 500 rather than 503. Step 7's non-2xx rule is the whole mitigation. | **accepted, structural** |
| No automated daily digest of `failed` / `needs_followup` exists; the signal is a calendar reminder to open a filtered admin list. | **partially satisfied — carried to 9B** |
| `FeatureTest` never rolls back (§3.1). The ULID fix removes the symptom for `users.email`; the next unique column hits the same wall. | **carried to 9B** |
| `MailFailureRateCheck` counts `failed` but not `bounced`. Postmark's bounce webhook removes the blocker; the fix stays in 9B. | **carried to 9B** |

---

## Exit criteria (spec §8)

Phase 9A-2 is complete when every one of these is true **and** its evidence row is filled in:

- [ ] **PR8** — deploy automation with a post-release smoke gate; rollback documented *and*
      rehearsed, with a measured recovery time
- [ ] **PR9** — staging exists, deploys by the same path as production, and has demonstrated live
      email delivery
- [ ] **PR13** — Postmark configured; SPF/DKIM/DMARC verified in received headers; send → log row
      → resend proven end to end
- [ ] **PR17** — named owner and both response windows recorded, with the daily signal's actual
      state stated honestly
- [ ] **PR4** — closed in full: an observed event carrying the deployed SHA
- [ ] **D9A2.5** — `flexpick.net` serves the built marketing site, its CTA reaches
      `app.flexpick.net`, and the three superseded deploy configurations are gone *(the
      repository half is done — `netlify.toml`, `vercel.json`, `Dockerfile` and `nginx/` were
      removed in `b14aeff`)*
- [ ] All three in-repo gates pass — `php artisan test --compact` green,
      `vendor/bin/pint --dirty --format agent` clean, `vendor/bin/phpstan analyse` introducing no
      new error category
- [ ] The evidence log is complete, including its "not verified" rows
