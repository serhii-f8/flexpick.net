# Audit precision: acceptance run on `platform-main`

Run date: 2026-10-05. Command: `php artisan app:audit-dry-run storage/framework/testing/platform-main --tier=deep_ai --json` (a copy of the reference repository, no `.git`, `node_modules` and `Zone.Identifier` streams left out). OSV made real network calls.

Raw scanner runs: scc 0.9 s, gitleaks 1.6 s, osv 5.5 s, jscpd 141.7 s, semgrep 15.1 s. All five finished `ok`.

## Check against the customer's rebuttal

| Customer rebuttal | Expected | Actual | Holds |
|---|---|---|---|
| CI configured "no" is wrong | `has_ci` yes; `github_actions`, `bitbucket_pipelines`, `jenkins` | `has_ci` true; `bitbucket_pipelines`, `github_actions`, `jenkins` | yes |
| Test file ratio 0% is wrong | about 20% | 33.1% (1,937 of 5,848 analysed files) | **deviation, see open item 2** |
| Potential secrets 0 contradicts 131 | single digits likely, the rest fixtures, total far below 131 | `secrets_likely` 11, `secrets_fixtures` 18 (29 in total) | partly: total far below 131, but 11 is not single digits (open item 3) |
| No `postxl-lock.json` hits | no secret example path equals it | none of the secret groups lists it | yes |
| The GIF-search key is the one real key | `gif-search.service.ts` in a critical or high group | in `secrets.possible-credential` (high), `backend/libs` | yes |
| bun.lock is committed | every `package.json` manifest has a lockfile; `bun.lock` kind | 13 of 14 manifests resolve to `bun.lock`; `_concept/prototype/storybook/package.json` has its own `pnpm-lock.yaml`. `dependencies` is measured (score 92) | yes |
| Storybook output is build output | one `structure.committed-build-output` group for the storybook-static directory | one group, directory `_concept/prototype` | yes |
| Prisma client committed by design | absent from `largest_files`, counted in `excluded_summary.generated` | no Prisma path in `largest_files`; `excluded_summary` is `docs 2535, generated 190, lockfile 3, non_code 582` | yes |
| Structure 0 / security 0 | both well above 0 | security_hygiene 50. **structure 0** | **deviation, see open item 1** |
| Duplication 0% | measured and plausible, or not measured with a reason | 16.4%, score 59 (see jscpd fix below) | yes |

Scores: structure 0, duplication 59, testing 100, dependencies 92, security_hygiene 50, overall 58. Vulnerable dependencies: 90 (real OSV lookups across the bun, pnpm and composer lockfiles).

## A defect the run found, fixed in this branch

The first run reported duplication `not measured: no_report`. Cause: `resources/scanners/jscpd.json` ignores `**/storage/**`, and jscpd matched that glob against the scanned path. Production clones live in `storage/app/audit-workdirs/<uuid>`, so jscpd ignored the whole repository and wrote no report. Before this branch the pipeline read that as 0% duplication, which is the "Duplication 0%" the customer saw. jscpd now runs from the clone root on a relative path, so the glob only hits the repository's own `storage/`. Pinned by `JscpdScannerTest::test_the_real_binary_measures_a_clone_that_lives_under_a_storage_directory`.

## Open items (not patched in this task, per the plan)

1. **Structure is still 0 on this repository.** The causes are now real, not generated code: average complexity 29 per file (threshold 8), average 252 lines per file (threshold 120), and all 20 of the largest files are over 1,000 lines. Nine of those 20 are `*.test.ts` files, and `routeTree.gen.ts` (a TanStack Router generated file) is classified as source because the classifier has no `.gen.` rule. Candidate follow-ups: stop counting test files in `largest_files` and the huge/big penalties, add `*.gen.ts` to the generated basenames, and cap the structure deductions. All of these change scoring and need a spec decision.
2. **Test ratio is 33%, not about 20%.** `test_files` counts every file the classifier labels `Test`, including `fixtures/`, `__mocks__/` and `e2e/` directories. Whether fixtures belong in the ratio is a product call.
3. **`secrets_likely` is 11.** Six `possible-credential` groups: the Jenkinsfiles, three files under `backend/libs` (including the GIF-search key), a test file under `backend/apps` (a provider-format rule, which stays High in tests), a Keycloak realm JSON, `frontend/vite.config.ts` and a Java email preview. Most are generic shape matches the scanner cannot tell from keys, which is what the `possible-credential` tier is for. None are publicly known sample values, so no allowlist was added.
4. **`frontend/.vite/deps/package.json` is treated as a package root.** It is Vite's dependency cache. It resolves to the root `bun.lock` and does no harm, but `.vite` could join the build-output segments.
5. **jscpd now takes 142 s on a 1.4M-line repository** against a 180 s timeout. Larger repositories will end as `duplication: timeout`, shown as "not measured" with the timeout reason, never as a clean 0%.
