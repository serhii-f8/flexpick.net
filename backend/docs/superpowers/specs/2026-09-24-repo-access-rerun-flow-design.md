# Repo access: close, refund, run again — design

Date: 2026-09-24 · Branch: `growth-retention`

## Problem

When we cannot reach a customer's repository, the customer is told to invite
our review account, and then promised that *we* will start the analysis:

- `emails/audit/access-needed.blade.php`: "We'll start the analysis as soon as
  the invite is accepted — usually within one business day."
- Dashboard status hint for `awaiting_access`: "We launch the audit as soon as
  the invite lands."

Nothing does that automatically. The request sits in `awaiting_access`
(landing flow) or `needs_followup` (pipeline flow) until an operator clicks
**Launch report** / **Retry pipeline**. Worse, a dashboard run spends its
credit *before* the pipeline discovers the repo is unreachable, and the credit
is never returned — so telling the customer to "just run it again" would charge
them twice.

## Goal

A single, customer-driven flow: **we couldn't reach it → invite us → run a new
audit yourself.** The original request is closed for good, never restarts, and
never costs the customer anything.

## Behaviour

### 1. Dashboard runs check access before charging

`AuditReports::launchAudit()` calls `RepositoryCloner::preflight($repoUrl)`
(with our token, same as the pipeline) after validating the URL and tier and
**before** the quota check, so it also gates the card-checkout path
(`purchase()`).

On failure: no `AuditRequest` is created, nothing is charged, and a persistent
danger notification shows the invite instructions and "then click Run audit
again". Probing this way reveals nothing valuable: a success always proceeds
straight into a charged run.

### 2. A run that turns out unanalyzable is closed and refunded

New terminal status `AuditRequestStatus::NOT_ANALYZABLE` (`not_analyzable`,
label "Couldn't analyze", colour `danger`, triage `terminal`).

`AuditPipeline` catches `AuditNotAnalyzableException` (preflight fail, clone
fail, too large — all raised before any AI spend) and calls a new
`AuditRequestService::closeNotAnalyzable($request, $reason)`, replacing
`markNeedsFollowup()` on that path. It:

1. sets `status = not_analyzable`, `failure_reason = $reason`;
2. refunds the run via `AuditEntitlementService::refund($request)`;
3. sends `AuditRepoAccessNeeded` (rewritten, see §4).

**Refund** — new nullable `audit_requests.credit_refunded_at` timestamp,
set once (idempotent; a second call is a no-op):

| funding     | how it was spent                         | refund                                              |
|-------------|------------------------------------------|-----------------------------------------------------|
| `free`      | `free_run = true` row counted            | metering ignores rows with `credit_refunded_at`     |
| `allowance` | row counted in `runsUsedThisMonth()`     | metering ignores rows with `credit_refunded_at`     |
| `purchase`  | tenant credit −1, or paid by card        | `grantPurchasedCredit(tenant, tier)` (+1)           |

A purchase refund on a tenantless request (should not happen — purchases are
always workspace-owned) is logged to the pipeline log instead of silently lost.

`needs_followup` stays for the "no repository URL" case only.

### 3. Landing-page requests close instead of waiting

`routeVerified()`: when the anonymous preflight fails, the request goes to
`not_analyzable` (reason "Repository is not publicly accessible") instead of
`awaiting_access`. No credit was spent on this path, so there is nothing to
refund. The admin notification is kept.

The landing preflight stays anonymous on purpose: our token can read every
customer's private repo, so an unauthenticated visitor must not be able to aim
it at one. The customer runs the private audit from the dashboard instead.

### 4. Copy

`AuditRepoAccessNeeded` (subject "We couldn't reach your repository"):

- Access failure: invite `:account` as a read-only collaborator (same steps as
  today), then **start a new audit** — "This request is closed and won't
  restart on its own. You haven't been charged for it." CTA button: "Run the
  audit again" → the dashboard Run-an-audit page with the repository prefilled
  (`?repo=`), or the login page for a request with no workspace yet.
- Other failures (clone failed, too large): the reason, "you haven't been
  charged", and "reply to this email and we'll help".

Status texts:
- Dashboard hint for `not_analyzable`: "We couldn't reach this repository. Invite
  :account as a read-only collaborator, then run a new audit — you weren't
  charged for this one."
- The public status page (`AuditRequestController::label`): "We couldn't reach
  your repository — check your email for next steps".
- The legacy `awaiting_access` hint drops the "we launch it" promise.

### 5. Nothing restarts it

`not_analyzable` is excluded from the admin **Retry pipeline** and **Launch
report** actions, and from `needsManualAction` / the "needs action" stats. The
job itself never retried this path (the exception is caught). Legacy rows
already in `awaiting_access` / `needs_followup` keep their operator actions —
those customers were promised an operator-driven launch.

The dashboard page reads `?repo=` in `mount()` to prefill the form.

## Out of scope / noted

- **Cross-workspace access (security, separate item).** `launchAudit()` accepts
  any URL and the pipeline clones with the shared PAT, which is a collaborator
  on every customer's private repos. A signed-in user of workspace B can
  therefore audit workspace A's private repo and receive the report. This
  change neither fixes nor widens that; it needs its own design (repo ↔ workspace
  binding at invite acceptance, or proof of access via the customer's GitHub
  OAuth).
- Auto-accepting GitHub invitations.
- Size-based run consumption and GitLab/Bitbucket (separate specs).

## Tests

- Pipeline: unreachable repo → `not_analyzable`, email queued, credit refunded
  for each funding kind; refund is idempotent.
- Entitlements: a refunded free/allowance run no longer counts; a refunded
  purchase grants +1 credit.
- Routing: unreachable landing repo → `not_analyzable`, no free run spent.
- Dashboard: unreachable repo → no request, no credit spent, no checkout
  redirect; `?repo=` prefills the form.
- Admin: Retry/Launch hidden for `not_analyzable`.
- Mapper/triage/status hint cover the new status; email renders both variants.
