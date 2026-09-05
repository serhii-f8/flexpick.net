# Audit report: full issue list + cross-run delta tracking

Status: draft
Date: 2026-09-02

## 1. Problem

Two related complaints about the audit report, from the same underlying cause:

1. **"After fixing issues, the rating didn't change and it found the same number of new issues."** `ScoreCalculator` scores each run independently from that run's raw metrics (`ScoreCalculator.php:8-14,46`) — correct behavior, no bug there. But nothing tracks *which specific issues* were fixed between runs. `AuditDeltaService` (`AuditDeltaService.php:11-39`) only diffs the six aggregate dimension scores between the two most recent reports for the same email+repo_url — there is no issue-level comparison anywhere in the codebase.
2. **"Don't we describe all issues in the report?"** No. `FindingGrouper::group()` caps its output to `config('audit.findings.max_groups')` groups (`Findings/FindingGrouper.php:68`), and `AuditPipeline::run()` further slices that down to `narrated_groups` (hardcoded to 12 for every tier, `config/audit.php:58,67,82`) before handing groups to the AI for narration (`AuditPipeline.php:112`). The static scanners already cover the entire repo (`ScannerRunner` runs scc/jscpd/gitleaks/semgrep/osv with no file sampling) — the gap is entirely in what gets grouped, persisted, and displayed afterward, not in scan coverage.

## 2. Goals

- The report shows every issue the scanners found (grouped for readability, per existing `FindingGrouper` conventions — never one raw lint line per report item), not just the top 12.
- The report tracks, per group, whether it's new, resolved, or persisting (with a count delta) since the user's last audit of the same repo on the same branch.
- No added AI cost or latency: the AI continues to narrate only the top 12 highest-severity groups as the report's headline section. The full list and delta tracking are deterministic, computed from already-collected scanner data.
- Available on every tier (Diagnostic, Deep AI, Expert) — this has no marginal AI cost, so there's no reason to gate it.

## 3. Non-goals

- Raw finding-level (file:line) fingerprinting. Identity is at the group level: `rule_family + directory + dimension`, the same composite `FindingGrouper` already uses to bucket findings. This trades exact-line precision for stability (see §7) and requires no new hashing or storage.
- Detecting moved/renamed files as a single tracked issue. A finding that moves from `src/foo/a.php` to `src/bar/a.php` will show as one fixed group + one new group. Documented limitation, not solved here.
- Changing how the AI-narrated headline section works, or how many groups it covers.
- Tier-gating the new sections.

## 4. Architecture overview

```
FindingGrouper::group()          (cap removed — returns ALL groups, sorted by score desc)
        │
        ▼
AuditPipeline::run()
        │
        ├─► top 12 groups ──► AiAnalyzer (existing, unchanged) ──► headline narrative
        │
        ├─► ALL groups ──► persistGroups() ──► audit_finding_groups table (existing table, unchanged schema)
        │
        └─► AuditGroupDeltaService::deltasFor($auditRequest)   (new, read-time — see §6)
                     │
                     ▼
        Report payload: headline (AI) + full issue list (deterministic) + per-group delta badges
```

## 5. Data model changes

**`FindingGrouper::group()`** (`Findings/FindingGrouper.php:68`): remove (or raise to effectively unlimited via config) the `array_slice($groups, 0, $maxGroups)` truncation. All computed groups flow through to persistence. The existing `usort` by descending score (`FindingGrouper.php:65-66`) is unchanged, so ordering stays meaningful when the AI-narration step takes its own top-12 slice from the front of this same list.

**`audit_finding_groups` table**: no schema change. It already stores `rule_family, directory, severity, dimension, count, score, examples, tools` per group, keyed to `audit_request_id` (migration `2026_08_02_000003_create_audit_finding_groups_table.php`). Its existing index on `['rule_family', 'directory']` already supports the delta lookup in §6. `AuditPipeline::persistGroups` (`AuditPipeline.php:104,205-212`) is unchanged — it already deletes and re-inserts the full current group set per request; only the size of that set changes (no longer capped).

## 6. `AuditGroupDeltaService` (new)

New class alongside the existing `AuditDeltaService`, same directory (`app/Services/AuditReport/`). Kept separate because it answers a different question (per-issue-group deltas) from a different data source (`audit_finding_groups`, not `audit_reports.payload`) — merging them into one service would conflate two unrelated diff computations behind one method.

```php
public function deltasFor(AuditRequest $auditRequest): ?GroupDeltaSet
```

- Looks up the most recent **prior** `AuditRequest` with persisted groups, for the same `email` + `repo_url` + `branch` (adds the branch filter on top of the pattern `AuditDeltaService::deltasFor` already uses at `AuditDeltaService.php:19-26`) and the same `scoring_version` as the current request (a version bump invalidates the comparison, same convention as the existing score-delta guard).
- Returns `null` when there's no eligible prior run — same convention `AuditDeltaService` already uses, so callers already know this pattern.
- Otherwise builds a `[rule_family|directory|dimension => count]` map for both the current and prior group sets, and diffs by key:
  - key present now, absent before → **new** group (report current `count`)
  - key present before, absent now → **fixed** group (report prior `count` as "N resolved")
  - key present in both → **persisting**, with `count_delta = current.count - previous.count`
- Returns a small value object (`GroupDeltaSet`) carrying the per-group status/delta plus repo-wide totals (`fixed_count`, `new_count`, `resolved_findings_count`, `new_findings_count`) for the summary line in §7.

## 7. Report display

Two additions, both read-time (no change to what's stored beyond §5):

1. **Full Issues section** — appended after the existing AI-narrated headline section in `reports/audit-web.blade.php`, the equivalent PDF template, and the report email. Lists every persisted group for the current request: rule family, directory, dimension, severity, count, example locations (`examples` already stored). Plain data rendering — no AI involved, so no added generation cost.
2. **Delta badges + summary line** — when `AuditGroupDeltaService::deltasFor()` returns non-null: each group in the Full Issues list gets a badge ("new" / "N resolved" / "unchanged" / "+N" / "−N"), and a summary line near the existing per-dimension score delta display (`audit-web.blade.php:44-47`) reads e.g. "6 issues resolved, 2 new since your last audit of this branch." When it returns `null` (first audit of this repo+branch, or a scoring-version change), the Full Issues list renders with no badges and no summary line — same as today's behavior for score deltas in that case.

`AuditReportController::show` (`AuditReportController.php:45`) gains one more service call (`AuditGroupDeltaService::deltasFor`) alongside its existing `AuditDeltaService::deltasFor` call, passed to the view as a sibling variable (e.g. `groupDeltas`).

## 8. Edge cases

- **First audit of a repo+branch**: no prior request exists → `deltasFor()` returns `null` → full list shown, no badges. Not an error state.
- **`scoring_version` bump**: breaks the comparison chain, same as it already does for `AuditDeltaService`. Reuse that exact guard condition rather than inventing a second one.
- **Different branch**: a prior run on a different branch is never used as the comparison baseline (confirmed requirement — comparing across branches would attribute unrelated code differences to "fixed/new").
- **Renamed/moved files**: shows as one fixed group + one new group. Explicitly out of scope (§3); worth a one-line note in report UI copy if it comes up, not solved in code.
- **Very large repos**: removing the `max_groups` cap could in principle persist a large number of groups per request. `FindingGrouper` already deduplicates and buckets by rule_family+directory before this point, so group count is bounded by distinct (rule × directory) combinations, not raw finding count — expected to stay in the tens-to-low-hundreds range even for large repos, not thousands. No pagination is planned for v1; revisit if real data shows otherwise.

## 9. Testing

- `AuditGroupDeltaServiceTest`: fixed group, new group, persisting group with count delta (both increase and decrease), no prior run, prior run on a different branch (excluded), prior run with a different `scoring_version` (excluded).
- `AuditPipelineTest` (extend existing): confirm all computed groups persist to `audit_finding_groups`, not just the AI-narrated top 12 — i.e. `persistGroups` receives the full, uncapped set.
- `AuditReportControllerTest` / feature test: Full Issues section renders every persisted group; delta badges render correctly when a prior same-branch run exists; no badges/summary line when it doesn't.

## 10. Out of scope for this spec (possible follow-ups)

- Raw finding-level (file:line) fingerprinting, if group-level precision proves insufficient in practice.
- Detecting file moves/renames as a single tracked issue rather than fixed+new.
- Any UI for browsing delta history across more than two runs (this spec only compares current vs. immediately-prior).
