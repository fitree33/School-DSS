# School-DSS Phase 4 Final Report

Date: 2026-09-01

Status: Automated verification and the focused manual browser retest are complete. All Phase 4 release gates have passed. Nothing has been pushed.

## Manual browser QA

The previously completed Manual Browser QA baseline passed with these final dashboard totals for fiscal year 2570:

| Status | Count |
| --- | ---: |
| `pending` | 3 |
| `passed` | 2 |
| `failed` | 2 |
| Total | 7 |

The baseline covered:

- Evaluation list and filters
- Score validation above each criterion's maximum
- Save draft without an automatic status change
- Explicit finalize to `pending`, `passed`, and `failed`
- Required decision note
- Finalized evaluation is read-only
- Evaluation history and detail rendering
- Locked fiscal year is read-only
- Dashboard status integration
- Responsive and mobile layouts

The focused manual browser retest passed after the final review fixes:

1. `QA-LATEST-DRAFT` continued to show the finalized `passed` result at `16 / 20 (80%)` on both the evaluation list and project detail, ignoring its newer `4 / 20 (20%)` draft.
2. `QA-MULTI-FINAL` showed the result finalized last: `failed` at `6 / 20 (30%)`. Its other result was `passed` at `18 / 20 (90%)`, and both finalize timestamps were identical, confirming deterministic result-ID/finalize ordering and consistent status/score pairing.
3. The locked active and inactive framework cards exposed none of edit, create-version, activate, or deactivate. Direct edit and create-version URLs returned to the framework list without rendering a form, while the authorized unlocked framework remained editable, versionable, and activatable.

The final service fix also tightened partial effective-date validation for non-ISO, validator-supported API input. It does not change locked-framework behavior or any browser path: the framework form uses date inputs that submit ISO values. No additional browser retest is required because the affected direct-API edge has a dedicated regression test that passed against both SQLite and MySQL 8.4. A one-case direct API manual check is optional only if release policy requires manual evidence for every backend-only validation edge.

The disposable QA database, credentials, HTTP captures, cookies, scripts, and server files remain under ignored `.foundation-runtime/` paths. The focused manual commit gate is open, and the QA server was stopped after the retest.

## Final diff and security review

- Phase 4 APIs are behind authenticated, active-user middleware and policy checks.
- Evaluation visibility remains scoped by project visibility; evaluator, finalize, and framework-management permissions are separate.
- Evaluator ID, round, finalizer ID and timestamp, result status ID, and project evaluation-status ID are server-owned. Requests may supply the evaluation date, scores and comments, plus the explicit finalize status code and decision note.
- Scores must include every active criterion exactly once and remain between zero and that criterion's configured `max_score`.
- Finalize locks and writes the evaluation, immutable result snapshot, audit record, and project evaluation status in one transaction.
- Finalized records and records in locked fiscal years reject mutation while history remains viewable.
- Project detail and the evaluation queue derive their latest finalized evaluation from the latest `project_evaluation_results.id`. Result IDs follow creation/finalize order, so selection remains deterministic when finalize timestamps are equal and ignores newer drafts.
- Framework mutation controls come from backend-provided abilities. The backend policy remains authoritative, and the service re-fetches locked rows, re-authorizes, and checks fiscal-year locks inside the write transaction.
- Framework effective dates are parsed chronologically, including partial updates with non-ISO but validator-supported input, before any write can create an inverted period.
- Active frameworks reject partial weight configurations both during activation and subsequent edits.
- Legacy DSS evaluation queries are isolated to records without a Phase 4 framework.

No hard-coded Phase 4 passing threshold exists. Score calculation produces totals and percentages only. A project remains `pending`, including with a perfect score, until an authorized user explicitly finalizes it as `pending`, `passed`, or `failed`. The numeric 80/65/50 recommendation bands in the pre-existing legacy DSS service are unchanged, operate only on legacy evaluations without a framework, and do not write the V2 evaluation status.

The generic Project API cannot transition evaluation status directly. Both `evaluation_status` and `evaluation_status_id` are prohibited at request boundaries, and the project service rejects either field as defense in depth. New projects are initialized to `pending` server-side; after creation, only the explicit finalize workflow changes that status.

## Excluded scope confirmation

The Phase 4 diff does not implement AI Import, PDF Signature, Activity, or Sub-Activity features. Guard tests assert that the corresponding tables and API routes do not exist. Existing legacy AI search and generic document upload code are outside Phase 4 and were not extended into either AI Import or PDF Signature behavior.

## Local artifacts and credentials

- Manual QA databases, credentials, HTTP captures, cookies, scripts, logs, and process files remain under ignored `.foundation-runtime/` paths; the disposable QA server was shut down after the retest.
- The isolated MySQL 8.4 binaries, data directory, and runtime logs are outside the repository under `D:\School-DSS-Foundation`.
- SQL backups remain under ignored `database-backups/` paths.
- Laravel `.env`, storage runtime data, dependency directories, build output, test output, private-key formats, and credential file patterns are ignored.
- No runtime database, QA output, log, screenshot, credential, private key, or secret-shaped token is tracked or pending for commit.
- PHPUnit forces the default suite to in-memory SQLite and clears database URL/socket overrides. MySQL tests require an explicit opt-in and the exact isolated host, port, and database name.

## Verification

- Focused manual browser QA passed all final latest-result and locked-framework scenarios listed above.
- Full SQLite suite: 117 tests passed, 1,004 assertions in 8.25 seconds.
- Full isolated MySQL 8.4.11 suite: 117 tests passed, 1,004 assertions in 23.219 seconds on `127.0.0.1:33084`; the test instance shut down cleanly and the existing MySQL listener on port 3306 was unchanged.
- Frontend unit tests: 7 files passed, 25 tests passed in 3.34 seconds.
- TypeScript app and Node configuration checks passed.
- Vite production build passed with 179 modules transformed in 6.04 seconds; pre-build and post-build SHA-256 inventories confirmed that the ignored output was byte-for-byte unchanged.
- PHP syntax passed for all 256 PHP files in the final Phase 4 tree.
- Laravel Pint passed for all 53 Phase-4-changed PHP files relative to `origin/main`.
- `git diff --check`, the equivalent pre-stage whitespace check for every intended untracked file, each commit's `git diff --cached --check`, and the final `git diff origin/main --check` passed across the complete Phase 4 change set.
- `composer validate --strict` passed.
- Composer platform requirement checks passed on PHP 8.4.24.
- Composer locked dependency audit found no known security advisories.
- Full pnpm dependency audit found no known vulnerabilities.

Repository-wide Pint still reports formatting differences in 13 unchanged baseline files. No Phase 4 changed PHP file is among them.

## Non-blocking follow-up

- The per-project evaluation history endpoint currently returns all rounds without pagination.
- High-contention framework and evaluation writes can transiently deadlock because they acquire related locks in different orders; the transactions retry deadlocks up to three times and preserve atomicity.
