# Phase 13 — Expert Review Workflow (from $999)

**Date:** 2026-08-05
**Spec references:** F5.12.4, F5.12.5, §17 Phase 13, Milestone M9, §18.7 (statistics-bucket
consistency defect)
**Depends on:** Phase 12's deep-review payload (`file_findings`, `deep_review`) and tier profiles

---

## 1. Scope

Phase 13 delivers the **Expert Audit (from $999)**: a human review gate sitting after the
existing automated + deep-AI pipeline, before delivery. This phase is being built ahead of the
first real order, not in response to one — the roadmap's original "defer until an order justifies
it" condition is being waived deliberately.

Phase 12 left this phase smaller than the roadmap checklist implies. Already shipped:

- `AuditTier::EXPERT` exists, and `TierProfileResolver` resolves an `expert` profile that already
  carries the same `deep_review` sub-profile as `deep_ai` — an explicit comment in
  `config/audit.php` records that Phase 12 did this on purpose: *"tier 3 is everything in tiers
  1–2 plus human review, so it runs the same deep review. Phase 13 adds only the delivery hold and
  the reviewer queue."* Expert-tier runs already produce a full tier-1 + tier-2 payload with no
  code changes.
- **The $999 product already exists** (`config/pricing.php` → `audit-expert`, $99900 cents),
  seeded idempotently by `AuditMonetizationSeeder` as part of Phase 11's catalog rework, and
  `HandleAuditTierOrder` already creates an `expert`-tier run on purchase, auto-unlocked via
  `prepaid` — same mechanism Phase 12 relied on for `deep_ai`.

One of the roadmap's checklist bullets — the $999+ product — is therefore already satisfied. This
design covers the remainder:

1. `expert_review` status and its display mapping, everywhere status is branched on today
2. The delivery hold (pipeline stops after persistence; report is not auto-sent)
3. Payload contract v4 (the expert-review section)
4. The operator review queue and the structured editing surface
5. The reviewer permission
6. The publish action
7. Human-verified report rendering

### Out of scope, deliberately

- **Per-order custom pricing.** F5.12.4 doesn't ask for it; the codebase has no infrastructure for
  it today (checkout always derives price from the catalog `OneTimeProductPrice` record, and
  `OrderResource` is entirely view-only — no create, no edit). Building it would mean new
  plumbing through `CartDto` → `CalculationService` → checkout, which is a separate feature. The
  existing `Discount`/`DiscountCode` system (single-use, fixed-amount-off or percentage-off code)
  is the manual lever if a custom-priced deal comes up before that's built.
- **Reviewer staffing beyond the existing admin team.** Q34 stays open. The reviewer permission is
  built as a real, separately-grantable capability, but `is_admin`'s panel-entry gate is untouched
  — a non-admin reviewer cannot enter the admin panel today, and making that possible is out of
  scope until staffing actually changes.
- **A published review SLA.** Per Q34, that's a marketing-copy decision made after the first three
  expert reviews are delivered on time, not a code change.
- **Editable `fix_first_plan`, `groups`, `scores`, or `summary`.** The reviewer's structured
  editing surface covers `risks` and `file_findings` only — see D3.
- **A "preview" mechanism for unpublished reports.** An admin can already view any `AuditReport`
  record directly in Filament; no customer-facing route exists to an expert report before
  `publish()` sends it.

---

## 2. Decisions

### D1 — Dedicated `ExpertReview` Filament resource, not an extension of `AuditRequestResource`

`AuditRequestResource` already carries 11 statuses, a JSON-override action, and no policy class at
all — any `is_admin` user can use every action on it today. Piling the review workflow on top
would make the resource's existing status-branching problem worse and leaves no clean way to gate
"reviewer, not admin" on just one action, since the resource has no per-action authorization today.

A new, small resource scoped to the review queue keeps the queue, the editing surface, and the
permission boundary in one place, and costs little: it leans entirely on services and validation
that already exist (`ReportPayload::validate()`, `AuditMailer`, the private PDF generator).

### D2 — The hold/send policy lives in `AuditReportService`, not in `AuditPipeline`

`AuditPipeline::run()` currently ends with unconditional, back-to-back `create()` then `send()`
calls. Rather than add a tier check inline in the pipeline, a new `createAndDeliver()` method on
`AuditReportService` owns the decision. Business logic belongs in services; the pipeline's job is
orchestrating stages, not encoding delivery policy.

### D3 — The reviewer edits `risks` and `file_findings` only

These are the two payload arrays that are actually findings. `fix_first_plan` is a derived
prioritized plan, `groups` is scanner-narrated prose, and `scores` are deterministic — none of
these are "findings" a reviewer removes as false positives. Scoping the structured form to the two
finding arrays keeps the form's size proportional to what reviewing actually means, and keeps
`ScoreCalculator`'s "measurement owns the numbers, findings feed the formulas and never the
reverse" invariant intact — a reviewer cannot hand-edit a score.

### D4 — Edits are in place; no parallel "reviewed" copy

The spec describes the reviewer editing findings and removing false positives, not annotating a
separate reviewed set alongside the original. `risks`/`file_findings` are overwritten in place on
save. There is no audit trail of what the AI originally produced versus what the reviewer changed
beyond what `AuditReport`'s own timestamps and any future revision history would capture — YAGNI
for this phase; if a diff/history requirement surfaces later, it is an additive change to
`publish()` and `create()`'s handling, not a redesign.

### D5 — `reviewed_by` / `reviewed_at` are stamped at publish, not reviewer-editable fields

Letting the reviewer type these in would let a report be misattributed or backdated. `publish()`
sets both from `auth()->user()->name` and `now()` at the moment the report is actually sent —
matching what "reviewed" is meant to assert.

### D6 — Payload contract bumps to v4 for the expert-review section

Following the exact precedent Phase 12 set for `file_findings`/`deep_review`: a new optional
top-level key is a contract change, and the contract version is what lets a report be reproduced
against the schema that produced it. `validateV4()` extends `validateV3()`, adding
`validateExpertReview()` for the new optional key. The validator stays tier-agnostic — it doesn't
know only expert-tier reports will ever populate `expert_review`, it just validates the shape when
present, exactly like `deep_review` today.

### D7 — The reviewer permission is granted to the existing `admin` role, not a new panel

Since reviewers are the existing admin team for now (per explicit decision, not an oversight), the
permission needs to be real and separately checkable — future-proofing for when staffing changes —
but does not need to solve panel entry for a non-admin reviewer today. `review expert audits` is
added to `RolesAndPermissionsSeeder` and granted to the `admin` spatie role, following the exact
`hasPermissionTo()` pattern the existing settings pages already use.

---

## 3. Architecture

### Status and display mapping

`EXPERT_REVIEW ('expert_review')` is added to `App\Constants\AuditRequestStatus`'s closed
enumeration, and wired into every place that currently branches on status — the codebase has three
independent, unsynchronized status→copy sites, and this phase makes all three consistent rather
than fixing the underlying duplication (a separate, unscheduled defect):

| Site | Change |
| --- | --- |
| `AuditRequestStatusMapper::mapForDisplay()` / `mapColor()` | New case: label "Awaiting expert review," a color distinct from `report_ready`/`analyzing` so it reads as its own state |
| Dashboard `AuditRequestResource::statusDescription()` | Customer-facing sentence, e.g. "Your report is complete and is being reviewed by our expert auditor before delivery." |
| `AuditRequestController::label()` (public pre-login polling page) | Same copy |
| `AuditRequestController::statusJson()` | `done` computation (`in_array($status, [REPORT_READY, SENT])`) stays exclusive of `expert_review` — a held report must not read as "done" to a polling customer |
| `AuditAdminStatsWidget` / `AuditStatsWidget` | New bucket (pending review, distinct from generic pending) |
| Admin `AuditRequestResource` `SelectFilter`, `retry` action visibility | New filter option (automatic via the mapper); `retry`'s `in_array` gains `EXPERT_REVIEW`, so an admin can re-run the whole pipeline pre-review if something looks wrong |

A new test asserts `AuditRequestStatus::cases()` is fully covered by the stats widgets' bucket
sets, closing the §18.7 "one status reconciles to no statistics bucket" defect for good — adding a
status can no longer silently create an unaccounted-for case.

### Pipeline placement

`AuditPipeline::run()` currently ends with:

```php
$report = $this->reportService->create($auditRequest, $payload, $scoreSet->scoringVersion);
$this->reportService->send($report);
```

This becomes one call:

```php
$this->reportService->createAndDeliver($auditRequest, $payload, $scoreSet->scoringVersion);
```

```php
// AuditReportService
public function createAndDeliver(AuditRequest $auditRequest, array $payload, int $scoringVersion): AuditReport
{
    $report = $this->create($auditRequest, $payload, $scoringVersion);

    if ($auditRequest->tier === AuditTier::EXPERT) {
        $auditRequest->update(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);
    } else {
        $this->send($report);
    }

    return $report;
}
```

`create()` itself is unchanged — it still generates a PDF immediately for unlocked requests (true
for every paid tier including expert). That PDF is from the pre-review payload and is a throwaway:
`publish()` (§5) unconditionally regenerates it from the reviewed payload before sending. This is
a small wasted render, not a correctness issue, and avoids threading a "skip PDF" flag through
`create()` for a single caller.

Nothing upstream of this point changes. Scanning, deduplication, scoring, tier-1 analysis, and
deep review all run identically for `expert` as they already do for `deep_ai` — per D6 of the
Phase 12 design, the `expert` tier profile was deliberately pre-wired to the deep-review profile
for exactly this reason.

### New components

| Component | Responsibility |
| --- | --- |
| `AuditReportService::createAndDeliver()` | Holds expert-tier reports at `expert_review`; sends every other tier as before |
| `AuditReportService::publish()` | Merges reviewer edits, validates, regenerates the PDF, sends |
| `App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource` | The review queue and editing surface |
| `ReportPayload::validateV4()` / `validateExpertReview()` | Contract extension for the expert section |
| `reports/partials/expert-review.blade.php` | Shared rendering partial (PDF + web) |

---

## 4. Payload contract v4

`ReportPayload::VERSION` goes to 4. `validate()` gains a v4 arm and keeps v1–v3 so stored reports
keep rendering. v4 is v3's rules plus one new optional top-level key:

```php
'expert_review' => [
    'expert_summary' => string, // required if the key is present
    'review_notes'   => string, // required if the key is present, may be an empty string
    'reviewed_by'    => string, // required if the key is present
    'reviewed_at'    => string, // ISO 8601, required if the key is present
]
```

The key is optional — the validator is context-free per the existing design principle (it must not
learn about tiers, or the contract becomes coupled to the catalog). All four fields are required
strings when the key is present; there is no partial-expert-review state, since `publish()` is the
only writer and always supplies all four together.

---

## 5. Operator review queue and reviewer permission

`App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource`, backed by `AuditRequest`:

- **List page** (the queue): `AuditRequest::query()->where('status', AuditRequestStatus::EXPERT_REVIEW->value)`.
  Columns: repo URL, customer, submitted date, days awaiting review (a plain age indicator — no
  SLA enforcement per Q34).
- **Edit page**: loads the associated `AuditReport`. Two `Repeater` fields over `report.payload`:
  - `risks` — `impact` (select: high/medium/low), `title`, `evidence`, `recommendation`;
    deletable (= false-positive removal); reorderable (array order = display priority).
  - `file_findings` — `path`, `title`, `evidence`, `recommendation`, `severity`, `category`,
    `effort`; deletable, reorderable. Present whenever the report has deep-review findings, which
    is always true for expert tier.
  - Expert section fields: `expert_summary` (Textarea, required before publish), `review_notes`
    (Textarea, optional). `reviewed_by`/`reviewed_at` are not form fields (D5).
  - **Save** persists the edited arrays and expert-section fields onto `report.payload`, validated
    through `ReportPayload::validate()` on submit — the same validation the existing raw-JSON
    "Edit results" action on `AuditRequestResource` already relies on, just field-scoped instead of
    freeform. Saving is a draft: it does not publish, so a reviewer can work across sessions.
  - **Publish** header action — §6.

The existing raw-JSON "Edit results" action on `AuditRequestResource` is untouched and remains
available for any tier as a general-purpose escape hatch.

**Permission**: `review expert audits`, added to `RolesAndPermissionsSeeder`, granted to the
`admin` spatie role. `ExpertReviewResource`'s `canViewAny()`/`canEdit()` check
`auth()->user()->hasPermissionTo('review expert audits')`, following the pattern the existing
settings pages (`AuditSettings`, `GeneralSettings`, etc.) already use for `hasPermissionTo('update settings')`.

---

## 6. Publish action

```php
// AuditReportService
public function publish(AuditReport $report, array $reviewedFields): void
{
    $payload = array_merge($report->payload, $reviewedFields, [
        'expert_review' => [
            ...$reviewedFields['expert_review'],
            'reviewed_by' => auth()->user()->name,
            'reviewed_at' => now()->toIso8601String(),
        ],
    ]);

    $validated = ReportPayload::validate($payload, ReportPayload::VERSION);

    $report->update(['payload' => $validated, 'payload_schema_version' => ReportPayload::VERSION]);
    $this->regeneratePdf($report); // new public one-line wrapper around the existing private generatePdf()
    $this->send($report);        // unchanged: emails, sets status SENT, records the funnel event
}
```

Wired to a Filament header action on `ExpertReviewResource`'s edit page — "Publish report,"
disabled until `expert_summary` is non-empty (a required-before-publish guard, distinct from the
softer draft-save validation), behind a confirmation modal since it is irreversible: the email
goes out immediately. `send()` is reused completely unchanged — no new mail template, no new
funnel-stage logic. This is the single PDF regeneration in the flow; the PDF `create()` generated
earlier from the pre-review payload is silently superseded.

---

## 7. Report rendering

New shared partial, `resources/views/reports/partials/expert-review.blade.php`, included from both
`reports/audit.blade.php` (PDF) and `reports/audit-web.blade.php` (hosted view), gated the same
way `deep-findings.blade.php` already is:

```blade
@if (($payload['expert_review'] ?? null) !== null)
    @include('reports.partials.expert-review', ['payload' => $payload])
@endif
```

Content: a "Reviewed by a human expert" badge/banner, `expert_summary` prominently, `review_notes`
in a secondary/detail area, and a `reviewed_by`/`reviewed_at` attribution line.

Unlike `deep-findings.blade.php`, there is no "pending" or "degraded" state to design for. A
customer only ever sees this report after `publish()` sends it, at which point `expert_review` is
unconditionally present — `publish()` requires `expert_summary`. Before publishing, the report is
never sent and its signed URL never reaches the customer, so there is no route by which an
unpublished expert report becomes externally visible.

---

## 8. Error handling and edge cases

| Condition | Handling |
| --- | --- |
| Pipeline fails before `createAndDeliver()` (clone, AI error, etc.) | Unaffected by this phase — routes to `AuditNotAnalyzableException` → `needs_followup` regardless of tier, same as every other tier today |
| Admin retries an `expert_review` request | `retry`'s visibility gains `EXPERT_REVIEW`; re-dispatches `GenerateAuditReport`, which re-holds at `expert_review` again since tier is unchanged |
| Reviewer deletes every `risks`/`file_findings` entry | Legal — empty arrays are a valid payload. A clean bill of health is a designed outcome, matching the existing "healthy verdict" convention from Phase 12's deep-review rendering |
| Reviewer saves a draft without publishing, indefinitely | No timeout or auto-publish; the request sits at `expert_review` until a reviewer acts. No new alerting is added in this phase — an aging queue is visible in the admin list's age column, not paged |

No new `OperationsAlert`/health-check wiring in this phase: an unpublished expert review is a
queue-management concern for the (currently: admin) reviewer team, not an infrastructure failure.
If queue aging becomes a real operational problem, it is an additive alert, not a redesign.

---

## 9. Testing

PHPUnit, `TestCase`-based, scaffolded with `php artisan make:test --phpunit`.

### Unit level

- `ReportPayload` v4 — valid with `expert_review` present, valid without it, rejection of each
  malformed field, v1–v3 payloads still validating.
- `AuditRequestStatusMapper` — new case covered, following the existing exhaustiveness pattern.
- Stats widget bucket exhaustiveness — every `AuditRequestStatus::cases()` value lands in exactly
  one bucket, for both `AuditAdminStatsWidget` and `AuditStatsWidget`.

### Service level

- `AuditReportService::createAndDeliver()` — expert tier holds without sending and sets
  `expert_review`; every other tier sends and sets `SENT`, unchanged from today.
- `AuditReportService::publish()` — payload merged and validated, `reviewed_by`/`reviewed_at`
  stamped from the authenticated user and current time, PDF regenerated, `send()` invoked, status
  transitions to `SENT`, email actually dispatched (`Mail::fake()`).

### Resource level

- `ExpertReviewResource` — queue lists only `expert_review`-status expert-tier requests and
  nothing else; access denied without `review expert audits`; saving edits persists
  risks/file_findings changes through `ReportPayload::validate()`; publish end-to-end assertion
  (mail sent, PDF path changes, status transitions).

### Execution notes

Tests run inside Docker (`docker compose exec laravel.test php artisan test`), one command at a
time. Use targeted `--filter` runs during implementation; reserve the full suite for checkpoints.

---

## 10. Exit criteria

**Milestone M9 — first expert-reviewed report published through the workflow.**

Concretely:

1. An `expert`-tier run completes analysis and holds at `expert_review` without emailing the
   customer.
2. A user holding `review expert audits` can see the request in the queue, edit `risks` and
   `file_findings` (including removing an entry and reordering), and save a draft across sessions.
3. Publishing requires an `expert_summary`, regenerates the PDF from the reviewed payload, sends
   the email, and transitions status to `SENT`.
4. The published report renders the human-verified section, with `reviewed_by`/`reviewed_at`
   correctly attributed, in both the web report and the PDF.
5. Every status-branching site (mapper, dashboard copy, public polling copy, stats widgets, admin
   filter) accounts for `expert_review` consistently.
6. All three CI gates pass: `php artisan test`, `vendor/bin/phpstan analyse`,
   `vendor/bin/pint --test`.
