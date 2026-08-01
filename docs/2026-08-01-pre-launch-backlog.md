# Pre-launch work plan — 2026-08-01

Companion to the platform specification (`docs/2026-07-27-flexpick-platform-specification.md`,
revision 2026-08-01, decisions **D1** and **D2**). The spec is the authority on requirements;
this document is the execution order. Each phase below should become an SDD plan under
`docs/superpowers/plans/` before implementation.

Decisions locked in the spec revision:

- **D1** — Mailcoach is removed (license expired 2025-10-15, not renewing). All audit mail
  is sent directly from Laravel through the single `AuditMailer` + `AuditEmailLog`.
  Delivery/open/click tracking deferred to ESP webhooks (spec §4.2, Q31).
- **D2** — Three tiers per pitch: Automated Health Report $49, Deep AI Code Review $199,
  Expert Audit from $999; subscriptions $59/$149/$499/Enterprise. Free funnel stays as the
  free diagnostic. Spec §5.12; prices pending cost validation (Q5).
- **Pipeline composition decided** (spec F5.12.2): CLI-only scanner set in fixed order —
  scc → Gitleaks → OSV (existing) → jscpd → Semgrep CE (permissive/in-house rules only).
  Deferred: SonarQube, Trivy, import-graph/SCIP, Lizard, CodeQL excluded permanently.
  Findings normalized (SARIF), deduped, grouped into problem families; AI narrates groups.

## Execution order

### Phase 10 — Email simplification (first; small)
Owner intends to do this by hand. Scope (spec §17 Phase 10):
- Delete `mailcoach/` app, its compose services, `docker/mysql/create-mailcoach-database.sh`,
  `MAILCOACH_*` env vars, `.gitignore` entries.
- Delete `MailcoachClient`, `MailcoachUnavailableException`; simplify `AuditMailer` to
  log → `Mail::send` → record outcome.
- Remove the status-refresh header action and platform branch of resend in
  `AuditEmailLogResource`; drop/repurpose `mailcoach_uuid`.
- Update `AuditMailerTest` / `MailcoachClientTest` / `AuditEmailLogResourceTest`; remove
  the Http::fake platform contract tests.
- Update CLAUDE.md / AGENTS.md references.

### Phase 9 essentials — launch-blocking operations (spec §17.2 note)
Error tracking, worker-liveness alerting (the spec's #1 silent failure), health checks,
scheduler-missed alert, deploy smoke gate + rollback, staging. Remainder of Phase 9
(rehearsed restore, browser E2E, perf baseline) before scale, not before launch.

### Phase 11 — Scanner platform + catalog rework (before public launch)
Spec F5.12.1–F5.12.2, F5.12.5–F5.12.6, §17 Phase 11. Tier attribute; scanner harness in
fixed order with per-tool timeouts and degrade-per-tool; findings model + grouping; prompt
rework (narrate groups, not lint lines); score formulas consume scanner signal (versioned,
Q14); cost telemetry per run; new catalog (tier products + subscription grid); marketing
pricing surface synchronized. Open: Q32 (retire legacy $5 unlock — recommended), Q33
(Semgrep ruleset licensing review).

### Phase 12 — Deep AI review (right after launch)
Spec F5.12.3, §17 Phase 12. Risk-file selection from hotspots × finding density ×
sensitive-domain paths (graph centrality deferred); AI file review under token budget;
payload extension; report deep section; $199 product + plan credits.

### Phase 13 — Expert workflow (when first expert order justifies it)
Spec F5.12.4, §17 Phase 13. `expert_review` status; delivery hold; Filament review queue
(canonical-validator editing, false-positive removal, expert payload section); reviewer
permission; publish action; human-verified rendering; $999+ product. Until built, expert
orders are fulfilled manually through the existing results-override tooling.

### Deferred (recorded, not scheduled)
ESP-webhook delivery/open/click tracking (pick ESP per Q31 so this stays config-level);
SonarQube evaluation; Trivy; import-graph/SCIP; marketing drip campaigns; §18.7 defect
list (top five: worker-liveness alerting — now in Phase 9 essentials; secret-file excerpt
exclusion Q17; operator change log Q16; scoring-formula versioning Q14 — folded into
Phase 11; mail render-failure logging — cheap to fold into Phase 10).
