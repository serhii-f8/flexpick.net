# Split audit report: business report + developer report

Date: 2026-10-09
Status: approved in conversation, awaiting written-spec review

## Goal

Today one report (web page `/reports/{uuid}` + one PDF) mixes a small plain-language box
(`client_summary`, "In plain terms") with all technical content, and has no charts.

Split it into two reports produced from the **same audit run**:

1. **Business report** — for the business owner/founder. Everything the current report
   communicates, plus more explanation, in plain language (no tool names, file paths, rule
   ids, jargon or code), with polished visualizations.
2. **Developer report** — for the engineer. All findings with full technical detail.

Both are delivered as a web page **and** a PDF.

### Success criteria

- A customer opening their emailed link lands on the business report; one click switches to
  the developer report and back.
- The unlocked report email attaches both PDFs.
- A business report contains no file paths, rule families, tool names or code.
- Reports stored before this change (payload v1–v5) still render on both pages.
- No extra Claude API call per audit.

### Non-goals

- No change to scoring, metrics collection, finding grouping, or the deep review.
- No new paid tier, no new pricing, no change to which content each tier unlocks.
- No headless-browser PDF rendering; dompdf stays.
- No JS chart library.

## 1. Data: payload v6

`ReportPayload::VERSION` becomes 6. `validateV6()` calls `validateV5()` and validates the
expanded `client_summary` when present. Every new field is optional at the validator level
(stored v5 reports must keep validating on view), but **required in the Claude JSON schema**
so every new run produces them.

`client_summary` (all text plain language):

| Field | Type | Notes |
|---|---|---|
| `overview` | string | unchanged |
| `verdict` | string | one headline sentence, e.g. "Solid foundation, but two risks need attention before you scale." |
| `areas[]` | `{area, meaning, status}` | one per **measured** score dimension. `area` ∈ score keys (`structure`, `duplication`, `testing`, `dependencies`, `security_hygiene`); `meaning` = what this area is, in plain words; `status` = how this codebase is doing on it |
| `findings[]` | `{what, consequence, gain, urgency, business_area}` | existing three fields + `urgency` ∈ `now`/`soon`/`later`, `business_area` ∈ `customers`/`costs`/`security`/`speed` |
| `roadmap[]` | `{step, outcome, effort}` | the fix-first plan rewritten for an owner; `effort` ∈ `S`/`M`/`L` (rendered as "days" / "a couple of weeks" / "a month or more" style labels in the view, not by the model) |
| `questions[]` | string[] | 3–5 questions the owner can ask their team |

Validation rules: `areas[].area` must be a known score key and is dropped (not fatal) when
that dimension is absent from `scores` — the model must not describe an unmeasured area.
Enum violations throw `AiAnalysisException`, matching existing behaviour.

### Prompt / schema (`ClaudeAnalyzer`)

- Extend `SCHEMA['properties']['client_summary']` with the fields above, all required.
- Extend the `client_summary` paragraph of `SYSTEM_PROMPT`: the verdict, per-area
  meaning/status only for dimensions present in `computed_scores`, urgency and business-area
  classification rules, a roadmap that mirrors `fix_first_plan` order, and the question list.
  Same "no tool names, file paths, rule ids, jargon or code" rule applies to every field.
- Output grows by ~1.5–2.5k tokens; existing `ai_max_tokens` (16000) is sufficient — no
  config change.
- `PromptComposer` is unchanged (it builds the user message, not the output contract).
- `tests/Support/FakeAiAnalyzer.php` returns v6-shaped data.

### Content split

| Business report | Developer report |
|---|---|
| `client_summary` (all fields) | `summary` |
| `scores` (as charts) | `scores` (tiles, as today) |
| benchmark percentile | benchmark percentile |
| severity counts from `audit_finding_groups` (aggregated, no rule names/paths) | groups + narratives, full issue list with deltas |
| business-area / urgency counts from `client_summary.findings` | repository facts (languages, largest files, hotspots, git, tooling, not measured) |
| overall delta vs previous audit | risks, `fix_first_plan`, `file_findings` (deep review) |
| `expert_review.expert_summary` (expert tier) | full `expert_review` (summary + notes) |

The "In plain terms" box is removed from the developer report.

## 2. Charts: `ReportChartBuilder`

New `app/Services/AuditReport/ReportChartBuilder.php`, following `ScoreChartBuilder`:
pure data in → SVG string out, no I/O, no framework calls.

| Method | Chart |
|---|---|
| `scoreGauge(int $score)` | semicircle gauge 0–100, colour by score band (reuse the existing ScoreBand thresholds) |
| `percentileBar(int $percentile)` | horizontal track with marker, "healthier than N%" |
| `areaBars(array $scores)` | one horizontal bar per dimension; unmeasured dimensions drawn as a greyed empty track |
| `severityStack(array $countsBySeverity)` | single stacked bar: critical+high → "urgent", medium → "important", low → "worth fixing", info → "minor" |
| `businessAreaBars(array $countsByArea)` | bars for customers / costs / security / speed of change |

Rules: fixed palette defined once in the class (light-on-white, works in PDF and both web
themes because the SVG carries its own background-neutral colours), text labels inside the
SVG for accessibility plus `role="img"` and `<title>`, empty input renders a neutral
"nothing to show" state rather than throwing.

Embedding: web views output the SVG inline; PDF views embed it as
`<img src="data:image/svg+xml;base64,…">` (dompdf's supported SVG path). A small
`ReportCharts` view helper/partial does the choice so views don't base64 by hand.

A `BusinessReportPresenter` (new, `app/Services/AuditReport/`) assembles the business view
model from report + request: severity counts from `findingGroups`, business-area/urgency
counts from `client_summary.findings`, percentile, delta, and the v5 fallback flags. Both web
and PDF business views consume it, so web and PDF cannot drift.

## 3. Pages and routes

| Route | Name | Middleware | Purpose |
|---|---|---|---|
| `GET /reports/{uuid}` | `reports.view` | signed | **business report** (default — existing email links land here) |
| `GET /reports/{uuid}/technical` | `reports.view.technical` | signed | developer report |
| `GET /reports/{uuid}/download/{variant?}` | `reports.download` | auth | `variant` ∈ `business` (default) / `technical`; old `/download` URL keeps working |
| `GET /reports/sample` | `reports.sample` | — | sample business report |
| `GET /reports/sample/technical` | `reports.sample.technical` | — | sample developer report |

`/reports/sample/technical` is declared before `/reports/{uuid}` routes to avoid binding
`sample` as a uuid.

Controller (`AuditReportController`):
- `show()` → business view; new `showTechnical()` → developer view. Shared guard (expert
  hold → 403) and shared data assembly extracted to a private method.
- Each page receives a signed URL to the other (`AuditReportService::signedUrl($report,
  $variant)`), rendered as a two-tab switch: "Business overview" / "Developer report".
- Funnel: `STAGE_REPORT_VIEWED` context gains `'variant' => 'business'|'technical'`.
- `resources/data/sample-audit-report.json` gains a complete v6 `client_summary`.

Views:
- `reports/business-web.blade.php`, `reports/business-pdf.blade.php` — new.
- `reports/technical-web.blade.php`, `reports/technical-pdf.blade.php` — today's
  `audit-web` / `audit` renamed, "In plain terms" removed.
- `reports/partials/web/report-tabs.blade.php` — shared tab switch.
- `client-summary.blade.php` partial is superseded by business-report partials and removed.

### Business report layout

1. **Verdict hero** — score gauge, `verdict` (fallback: `overview`), delta ▲/▼ vs previous
   audit, percentile bar.
2. **Your codebase at a glance** — area bars, each with `areas[].meaning` + `status`;
   unmeasured areas greyed "not checked this time".
3. **How serious are the problems** — severity stack with plain labels.
4. **What it affects in your business** — business-area bars.
5. **Key findings** — cards: what / consequence / gain + urgency chip (now / soon / later).
6. **Your roadmap** — numbered timeline: step, outcome, effort label.
7. **Expert's note** (expert tier, `expert_review.expert_summary` only).
8. **Questions to ask your team**.
9. CTA → developer report ("Forward this to your engineer").

Brand: follows the existing report styling (Tailwind/daisyUI via `@vite`) and the dashboard
brand decisions; both light and dark themes on web, light-only in PDF.

## 4. Locking

Same unlock rule for both reports (one unlock opens both).

- **Locked business report** — visible: verdict, overview, all charts, area bars (without
  `meaning`/`status` text), finding headlines (`what`) with urgency chips. Blurred with the
  existing placeholder technique + "Unlock to read" badge: consequence/gain, area text,
  roadmap, questions. Unlock CTA placed after the findings.
- **Locked developer report** — identical to today's locked page.

## 5. PDFs

- Migration: add nullable `technical_pdf_path` to `audit_reports`. `pdf_path` now holds the
  business PDF (existing files are the old combined report — acceptable; see backfill).
- `AuditReportService::generatePdf()` renders both (`reports.business-pdf`,
  `reports.technical-pdf`) wherever it runs today (unlock / create-unlocked / publish /
  `regeneratePdf`).
- `download()` for `technical` with null `technical_pdf_path` on an unlocked report generates
  it on demand, then streams. Locked → 404 as today.
- Backfill: `regeneratePdf` already exists; an optional one-off
  `php artisan app:regenerate-report-pdfs` command regenerates both PDFs for unlocked reports
  so old combined `pdf_path` files are replaced. Run once after deploy.
- `AuditRequestService::delete()` deletes both files.
- Download filenames: `codebase-health-business.pdf`, `codebase-health-developer.pdf`.
- Dashboard (`audit-reports.blade.php`, `Dashboard/.../ViewAuditRequest`) and admin
  (`Admin/.../ViewAuditRequest`) get two download actions: "Business PDF" / "Developer PDF".

## 6. Email

- `AuditReportReady::attachments()` attaches both PDFs when present (each independently
  null-checked).
- `report-ready` and `AuditReportUnlocked` templates: primary button → business report; a
  secondary line "Send the developer report to your engineer" → signed technical URL.
- Sending still goes through `AuditMailer` (logged to `AuditEmailLog`).

## 7. Backward compatibility (v1–v5)

- Developer page: renders exactly as today's page minus the plain-terms box.
- Business page on a v5 report: hero uses `overview`; findings show what/consequence/gain
  without chips; gauge, percentile, area bars (no text), severity stack render from computed
  data; business-area chart, roadmap and questions hidden.
- Business page on v1–v4 (no `client_summary`): hero shows score + percentile + the
  technical `summary` is **not** shown; instead a short neutral line ("Your full findings are
  in the developer report") plus charts from computed data and the CTA.

## 8. Testing (PHPUnit)

- `ReportPayloadTest`: v6 valid; v5 still valid; bad enums rejected; unmeasured `areas[]`
  entries dropped.
- `ClaudeAnalyzerContractTest`: schema requires new fields; prompt mentions each.
- `ReportChartBuilderTest` (unit): each chart for normal, zero/empty and boundary input;
  output is well-formed SVG with `<title>`.
- `BusinessReportPresenterTest`: aggregation and v5/v1 fallback flags.
- Rendering (feature): business + technical, locked + unlocked, sample pages, v5 report on
  business page; business page contains no file paths / rule families from the fixture;
  tab links are signed; expert hold → 403 on both.
- `PdfRenderTest`: both PDFs render, technical generated on demand.
- `AuditMailablesTest`: two attachments; links to both pages.
- Update existing tests that target `reports.audit-web` / `reports.audit` / the
  "In plain terms" box.

## Risks

- **dompdf SVG support** is partial — keep SVGs to rects, paths, arcs and text, no filters,
  no CSS in SVG; verified by `PdfRenderTest` plus one manual visual check.
- **Model drift into jargon** in business fields — mitigated by the prompt rule; a rendering
  test cannot catch model output, so spot-check the first real runs after deploy.
- **Old combined PDFs** linger until the backfill command runs.
