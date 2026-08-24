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

## What you can tell a client without overclaiming

- The score is deterministic and scanner-backed, not an LLM's subjective read
  — safe to describe as "measured," not "AI opinion."
- Every finding traces back to a specific tool and a specific file; nothing
  in the report is unfalsifiable.
- The Expert tier genuinely has one of our developers in the loop before
  delivery — that's
  a real differentiator from "AI wrapper" competitors, not just copy.
- What you shouldn't claim yet: white-labeled reports (the report itself is
  FlexPick-branded, not rebrandable to your agency today), or programmatic/CI
  integration (dashboard-only submission for tiered runs, as noted above).
