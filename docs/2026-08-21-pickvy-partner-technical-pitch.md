# Pickvy — Technical Briefing for [Partner Name]

*Prepared for the technical side of the partnership conversation. The commercial
and relationship context is being covered separately — this document is about
what the product actually does, how you'll use it day to day, and what you can
credibly tell your own clients about it.*

## What Pickvy is, in one paragraph

Pickvy is FlexPick's audit engine, positioned for agencies and product
companies that need an objective, fast read on a codebase they didn't
necessarily write themselves — outsourced work, an AI-generated codebase, a
project you're taking over, or your own team's output before a release. It
runs a fixed pipeline of static analyzers plus an AI narration layer against a
repository and returns a scored, evidence-backed report: what's wrong, how
bad it is, and what to fix first.

## How an audit actually runs

Every audit goes through the same pipeline, regardless of tier:

1. **Clone** — the repository is cloned into a temporary workdir. Public repos
   work immediately; private repos are reached by inviting a dedicated
   `flexpick-audit` GitHub account as a **read-only collaborator** on the repo
   (no OAuth flow, no broad org-wide access grant — one repo, one invite,
   read-only). The clone is shallow (depth-limited, single branch) and capped
   at a configured size ceiling.
2. **Scan** — a fixed set of static analyzers run against the clone, sized to
   the purchased tier (see below).
3. **Deduplicate and group** — raw findings get deduplicated and grouped so
   the report reads as a prioritized list of real problems, not a raw linter
   dump.
4. **Score** — a deterministic Health Score is computed *before* any AI
   involvement (detail below). This matters: the score is not something the
   AI can hallucinate or drift on run to run.
5. **Narrate** — an AI pass (Claude) turns the scanner output and the
   deterministic scores into a plain-language summary, a prioritized
   fix-first plan, and per-group explanations. The AI is instructed to treat
   the computed scores as authoritative and echo them verbatim — it explains
   the findings, it doesn't re-score them.
6. **Deep review** *(Deep AI and Expert tiers only)* — the riskiest 20–40
   files are selected and reviewed individually by the AI for issues static
   analysis alone won't catch (logic bugs, security-relevant code shape,
   missing error handling).
7. **Human sign-off** *(Expert tier only)* — see below.
8. **Deliver** — the report is stored and sent. The temporary clone is
   deleted unconditionally once the run finishes, success or failure.

## The actual scanning stack

Four real static-analysis tools, plus one dependency-vulnerability database,
all invoked directly (not just cited as marketing language):

| Tool | What it checks | Tier availability |
|---|---|---|
| **scc** | File inventory, size, complexity | All tiers |
| **gitleaks** | Committed secrets | All tiers |
| **OSV** (osv.dev API) | Known-vulnerable dependencies | All tiers |
| **jscpd** | Code duplication | All tiers |
| **Semgrep** | Static application security testing (in-house rule set) | All tiers |

If you've seen SonarQube named anywhere in Pickvy's own marketing materials —
it isn't actually part of the stack. Worth knowing before you repeat it to a
client who might ask a follow-up technical question.

## The Health Score, precisely

Five weighted dimensions, each tied to a specific scanner so a dimension is
only scored when it can actually be measured (a run where Semgrep fails
doesn't get a fabricated security score — that dimension is excluded and the
others are renormalized):

- **Structure** (25%) — file size, complexity, oversized files
- **Duplication** (20%) — `100 − 2.5 × duplication%`
- **Testing** (25%) — test coverage ratio and CI presence
- **Dependencies** (15%) — lockfile presence, known-vulnerable packages
- **Security hygiene** (15%) — committed secrets weighted far higher than
  other findings

Every dimension and the overall score clamp to a 0–100 scale. This is the
number a client can watch move between releases, and it's cheap to defend in
a conversation because it's arithmetic, not a model's opinion.

## The three tiers — what technically changes

| Tier | Price | Scanners | AI involvement |
|---|---|---|---|
| **Diagnostic Report** | $49 | All 5 | AI interpretation of every result, prioritized fix-first plan, PDF export |
| **Deep AI Code Review** | $119 | Same 5 | Everything above, **plus individual AI review of the 20–40 riskiest files** |
| **Expert Audit** | $999 | Same 5 | Same deep review, **plus a manual review by one of our developers before delivery** |

Every tier runs the same scanner set and the same narration budget. What you
pay more for is depth of review, not breadth of scanning: Deep AI adds a
per-file pass, Expert adds a person.

The Expert tier's "human review" is a real gate, not a label: the request is
held at an `expert_review` status, an internal reviewer edits the AI-drafted
findings in an admin screen, writes a required summary, and only then does a
publish action unlock. Nothing at that tier reaches a client without a person
having looked at it first.

## What each report actually contains

The tier table above says what changes. This section says what lands in the
client's hands, section by section, so you can set expectations before you
sell rather than after.

### Diagnostic Report — $49

The base report, and the one you will run most. Every section below is present
in every Diagnostic:

- **Overall Health Score** — the 0–100 number from the rubric above. Where
  enough comparable reports exist, it carries a **percentile** — "healthier
  than *n*% of the repositories we've scored" — and, on a repeat run of the
  same repository, a **delta against the previous run** on every dimension.
- **Five dimension scores** — structure, duplication, testing, dependencies,
  security hygiene, each with its own movement when there is a previous run
  to compare against. A dimension whose scanner didn't run is omitted rather
  than guessed.
- **What we found** — every scanner finding, deduplicated across tools and
  grouped into themes, ranked by severity (critical → info). Five tools
  reporting the same problem produce one entry, not five.
- **Repository facts** — the measured inventory: language breakdown, largest
  files, change hotspots, contributor count, last commit date, and the
  dependency audit. This is raw fact, not interpretation, and it is often the
  section a skeptical client trusts first.
- **Risks, ranked by impact** — each risk with its **evidence** and a
  **recommendation**. Every entry points at something real in the repository.
- **What to fix first** — an ordered plan, each step carrying a *why* and an
  **effort size (S / M / L)** so it can be dropped into a backlog without
  re-estimation.

**Sell it when** the question is *"what shape is this codebase in, and what
should we do first?"* — vendor screening, a re-audit on a schedule, a quick
read before you quote.

**Its honest limit:** it is scanner-derived. It sees what static analysis and
repository structure can prove. It does not read your business logic.

### Deep AI Code Review — $119

Everything in the Diagnostic, plus a **Deep file review** section:

- **20–40 files, chosen deterministically, not sampled.** Selection is driven
  by risk signals and is logged, so the same repository yields the same
  selection — and we can tell a client exactly why a file was picked.
- **Each selected file is read individually by the AI**, under a per-run token
  budget, rather than skimmed as an excerpt.
- **Findings are bound to a file and, where determinable, a line number.**
  Each carries a severity, a **category** — business logic, authorization,
  architecture, or security — plus evidence, a recommendation, and an effort
  size.
- **A clean result is a real result.** If the riskiest files hold up to close
  reading, the report says so explicitly. It does not pad the section to look
  thorough.
- If the deep pass cannot complete, the report says that too, and the
  automated analysis is still delivered — a degraded run is never presented as
  a complete one.

**Sell it when** someone has to *own* the code: taking over a project, due
diligence, gating a milestone payment, or onboarding a team onto an inherited
codebase.

**Its honest limit:** it is still a model reading code, without your product
context. It finds the class of problem a reviewer would flag on a careful
read-through — not everything a domain expert who knows the business would.

### Expert Audit — $999

Everything in the Deep AI Code Review, plus a **Human expert review** section
and a delivery gate:

- The run **stops before delivery**. It is held at `expert_review` and cannot
  be emailed by the automated path.
- One of our developers works through the AI-drafted findings: **removing
  false positives, adjusting priorities**, and writing a **required summary**
  in their own words. The publish action does not unlock until that summary
  exists.
- The delivered report carries the **reviewer's name, the review date**, and
  their notes, alongside a visible human-verified marker. Publishing sends the
  report and regenerates the PDF so the document a client keeps matches what
  the reviewer approved.

**Sell it when** the report itself is the deliverable and someone's
reputation is attached to it: pre-launch sign-off, an acquisition, a dispute,
or anything going in front of a board.

**Its honest limit:** it is a code review with a human gate, not a penetration
test, not a formal security certification, and not a performance audit.

## Delivery format

Every finished report is a hosted, signed-link web page (time-limited access,
not a permanent public URL) plus a downloadable PDF. Locked/free-tier reports
show a teaser; unlocking (via purchase, subscription allowance, or your
Partner allowance) reveals the full report and generates the PDF.

## Your Partner account, mechanically

This was built specifically for this conversation. Your tenant is on a hidden,
non-self-serve plan (`audit-partner-monthly`, $0, never listed publicly) that
grants, per month: **100 Diagnostic Reports, 50 Deep AI Code Reviews, and 10
Expert Audits**. Billing for what you actually use is handled manually,
directly between FlexPick and you, outside the app's own payment flow.

Nothing about how the product works changes for you: you log into your own
Dashboard tenant, submit a repo URL, pick a tier from the same picker any
customer uses, and get the same report format back. The only difference is
that every tier shows as available rather than requiring a purchase, because
your subscription's allowance covers it. You can also set a repo to
re-audit automatically on a weekly or monthly schedule from the same screen,
which is the concrete mechanism behind "watch the score move between
releases."

**One current limitation worth knowing:** submitting a *tiered* audit
(picking Diagnostic vs. Expert, etc.) is a dashboard action today, not yet a
documented public API — the only API endpoint that exists is an
unauthenticated lead-capture form with no tier parameter. If you're picturing
wiring this into your own CI pipeline or internal tooling rather than using
the dashboard by hand, that's a real gap to raise now rather than discover
later.

## How this fits into your delivery pipeline

The moments this is built for, mechanically mapped to what you'd actually do:

- **Screening a vendor's progress** — run Diagnostic at intervals, watch the
  Health Score trend, no manual code reading required.
- **Taking over a project** — run Deep AI on day one; the riskiest-files
  review and fix-first plan double as onboarding material for whoever
  inherits the code.
- **Gating a milestone payment** — a Diagnostic or Deep AI report is
  objective, timestamped evidence of what was actually delivered, independent
  of what the vendor claims.
- **Pre-launch sign-off** — Expert tier, when you want a human, not just a
  model, to have looked at it before it ships under your name.

## Where the pipeline goes next

Worth being explicit about this, because it is the part that is easiest to
underestimate from the outside: **the reports are the visible surface, not the
product.** The product is the pipeline underneath them — and that is what we
intend to keep compounding.

### What is already behind it

The audit pipeline has been in continuous development since **July 2026**,
inside a platform under development since **March 2026** — 87 commits on the
pipeline itself, within 355 across the platform. It has been through four
sequenced engineering phases, each with a written design document before any
code: the intake and reporting pipeline, the scanner platform and findings
model, the deep review, and the expert review workflow. There are 23 design
and implementation documents behind the audit surface alone.

Some concrete evidence that this is refinement rather than a first draft:

- **The scoring formula is at version 2, and the report contract at version
  4.** Both are versioned deliberately, and reports written under older
  versions still render and are still compared only against their own
  generation. That is a decision you make when you expect the formula to keep
  improving and you refuse to break historical comparability to do it.
- **Scores are computed before the AI is invoked**, and the model is
  instructed to echo them verbatim. Making an LLM-based product reproducible
  and defensible is the genuinely hard part, and it is architectural — not
  something you retrofit.
- **Every AI call is metered.** Token counts and cost per run are recorded
  against a model price table, so we know the unit economics of each tier and
  can tune budgets against measured cost instead of guesswork.
- **Failure behavior is designed, not incidental.** A scanner that fails
  excludes its dimension and renormalizes rather than fabricating a score; a
  deep review that cannot finish is disclosed; files that look like secrets
  are excluded from what is sent to the model. Each of those exists because a
  real run surfaced the need.

### Why this is hard to copy

The visible features — five scanners, a score, an AI summary — are the easy
half. A competitor can assemble those in a couple of weeks. What they cannot
assemble quickly:

1. **The normalization layer.** Five tools emit five incompatible finding
   formats. Turning them into one deduplicated, grouped, severity-ranked list
   that reads like an engineer wrote it is most of the work, and none of it is
   visible in the output.
2. **The benchmark corpus.** Percentiles are computed from the distribution
   of every repository we have scored, partitioned by scoring version so
   comparisons stay honest. The mechanism is built and every audit feeds it,
   which makes it the one asset here that **compounds on its own and cannot be
   copied from the interface** — a competitor can clone the feature on day one
   and still have nothing to say when a client asks "compared to what?"
3. **Calibration.** Weights, thresholds, prompt composition, risk-file
   selection, and token budgets have been tuned against real repositories.
   Each is a number someone had to be wrong about first.
4. **The operational envelope.** Clone limits, scanner timeouts, degradation
   paths, cost ceilings — the accumulated knowledge of what actually happens
   when you point this at a stranger's repository.

The moat is the accumulated pipeline, the tuning, and the data — not the
feature list.

### What we are actively building

Direction, not delivery dates. Nothing below is shipped, and it should be
presented as roadmap rather than capability:

- **Programmatic submission.** The API gap flagged above is a priority —
  submitting a tiered audit from your CI or internal tooling rather than the
  dashboard by hand.
- **Broader and deeper rule coverage.** Our in-house Semgrep rule set is
  deliberately small and high-signal today, spanning shared, JavaScript, and
  PHP rules. Expanding it — and the language coverage behind it — is ongoing
  work, and it is where partner feedback lands most directly.
- **Model and cost optimization.** A standing review of the underlying model
  against a fixed repository corpus for quality, latency, and cost per audit.
  The model identifier is configuration, never hardcoded, precisely so this
  stays a routine tuning exercise.
- **Scoring evolution under versioning.** The rubric will keep changing as the
  corpus grows. The versioning is already in place so it can change without
  invalidating a client's history.
- **Performance baselines and reporting depth**, and — relevant to your
  commercial conversation — **white-labeling**, which is not available today.

If there is something on that list your clients would pay for sooner, that is
useful input to raise now: this roadmap is not fixed, and a partner with real
demand is the best argument for reordering it.

## What you can tell a client without overclaiming

- The score is deterministic and scanner-backed, not an LLM's subjective read
  — safe to describe as "measured," not "AI opinion."
- Every finding traces back to a specific tool and a specific file; nothing
  in the report is unfalsifiable.
- The Expert tier genuinely has one of our developers in the loop before
  delivery — a real differentiator from "AI wrapper" competitors, not just
  copy.
- The percentile benchmark is real data from repositories we have actually
  scored, not a marketing number — but it only appears once enough comparable
  reports exist, so don't promise it on a specific report before you see it.
- What you shouldn't claim yet: white-labeled reports (the report itself is
  FlexPick-branded, not rebrandable to your agency today), or programmatic/CI
  integration (dashboard-only submission for tiered runs, as noted above).
- Everything under "What we are actively building" is roadmap. Describe it as
  direction if it helps a client picture where this is going — never as
  something they can use this quarter.
