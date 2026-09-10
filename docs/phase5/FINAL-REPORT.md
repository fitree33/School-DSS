# School-DSS Phase 5 Final Report

Verification date: 2026-09-10 (Asia/Bangkok)

Local closeout date: 2026-09-11 (Asia/Bangkok)

Status: Manual Browser QA passed completely according to the user's confirmation. All 15 final verification gates passed on the Phase 5 implementation preserved in this closeout. The local handoff comprises four commits; no push or deployment is included.

## Delivered behavior

Authenticated users can upload a PDF with a text layer, follow queued extraction, inspect the original, review extracted fields and warnings, select authorized master data, save preview revisions, and explicitly confirm a saved revision into a Project. Failed extraction supports a guarded retry. The import list, detail, preview/history, confirmation dialog and resulting Project detail expose role-appropriate actions and imported KPI/document evidence.

Original downloads use the SPA session through an authenticated request, preserve the uploaded bytes and filename, and return attachment, nosniff and sandbox headers. Imported originals remain protected in the legacy document workflow while ordinary legacy attachments continue to work.

## Final scope and security review

The tracked diff and relevant new implementation, tests, migrations, frontend, configuration and QA scripts received a final review, including independent callback/security, Confirm/immutability and UI/artifact reviews. No blocking findings remained.

| Required boundary | Result |
| --- | --- |
| Phase 5 callback cannot canonical-write | PASS. Callback updates only import/run state and append-only preview data; it does not call the canonical Project writer. |
| Confirm is the only canonical creation path for Imports | PASS. Confirm validates the current saved revision, source run and original hash/size, reauthorizes, locks rows, and creates Project/KPIs/document/content/access/history/audits atomically. Existing manual Project creation remains supported. |
| Imported original is immutable | PASS. Source/provenance model guards, database constraints, content guards and legacy lifecycle checks prevent application replacement/deletion or reassociation. |
| No OCR | PASS. Poppler extracts existing PDF text; no-text documents fail explicitly. |
| No Activity/Sub-Activity | PASS. These fields do not create canonical records. |
| No PDF Signature | PASS. No signing workflow was introduced. |
| No unrelated Budget/Evaluation changes | PASS. Shared Project creation/options refactors preserve existing behavior; full regression suites pass. |
| QA credentials/runtime/database are not tracked | PASS. Candidate scan and ignore checks exclude operational secrets, disposable databases, runtime, dependencies and generated build output. |

Callback authentication binds the timestamp, event ID, run UUID and exact raw-body digest before run lookup. JSON-only validation, body limits, throttling, key rotation, durable replay checks and stale-attempt guards protect that boundary. Preview and Confirm share the import lock and reject stale revisions; idempotency compares the request identity and returns the existing result for an exact replay. PDF processes use argument arrays, timeout and output limits. React renders extracted fields and warnings as escaped text.

## Final verification results (2026-09-10)

These results were rerun on 2026-09-10 after the user's completed manual QA confirmation. They supersede the lower test counts in the historical [pre-browser checkpoint](final-verification-20260910.md). The 2026-09-11 continuation reused this completed verification and performed only report, artifact and Git closeout checks; application suites and browser QA were not rerun.

| # | Gate | Result |
| --- | --- | --- |
| 1 | Final diff/security review | PASS; no blocking findings, all scope boundaries above satisfied. |
| 2 | Full SQLite suite | PASS; 208 tests, 2,384 assertions. |
| 3 | Full isolated MySQL 8.4 suite | PASS; 208 tests, 2,384 assertions on MySQL 8.4.11. |
| 4 | Targeted callback/replay/idempotency/security suites | PASS on both SQLite and isolated MySQL; 91 tests, 1,380 assertions per database. |
| 5 | Confirm concurrency | PASS; two overlapping MySQL row-lock waiters, one canonical Project creation and one reuse. |
| 6 | Preview concurrency | PASS; distinct edits 201/409, identical replay 201/200 with the same revision row, preview-vs-confirm 201/409; lifecycle guards passed. |
| 7 | Vitest | PASS; 13 files, 71 tests under Vitest 4.1.11. |
| 8 | TypeScript | PASS; both app and Node tsconfig checks with `--noEmit`. |
| 9 | Vite production build | PASS; 188 modules transformed. |
| 10 | PHP syntax | PASS; 323 first-party `.php` files including Blade templates, plus `artisan` (324 files total). |
| 11 | Pint changed PHP files | PASS; all 87 changed/new PHP files, `--test` with no formatting mutation. |
| 12 | `git diff --check` | PASS; repeated for the final documentation/staged changes. |
| 13 | Composer validate/platform/audit | PASS; `validate --strict`, `check-platform-reqs`, online `audit --locked`; zero advisories or abandoned packages. |
| 14 | pnpm audit | PASS; online `audit --json`, zero vulnerabilities at every severity, no muted advisories or actions. |
| 15 | Secret/runtime/artifact scan | PASS; all 439 initial tracked/nonignored-untracked candidate files, 13 ignore checks, no operational secrets or generated artifacts; final documentation delta checked before commit. |

All four JUnit reports have zero errors, failures and skipped tests. Targeted membership: `DocumentImportApiTest`, `DocumentImportConfirmationTest`, `DocumentImportProcessorTest`, `ImportExtractionCallbackTest`, `ImportedDocumentLegacyLifecycleTest`, `ImportedOriginalSessionDownloadTest`, `ImportedProjectDetailTest`, and `PdfImportInfrastructureTest`.

The MySQL gate checked server version, loopback binding, port `33084`, database `school_dss_foundation_test`, and the exact workspace `.foundation-runtime/phase5-mysql/data/` directory before execution. Full/targeted MySQL suites and concurrency harnesses ran sequentially against that disposable target. The normal application database and manual QA SQLite database were not used by these suites.

Confirm concurrency retained exactly one Project, two KPIs, one ProjectDocument, one DocumentContent, one access row, one initial status history, one initial execution history and four audit rows. Preview races produced no canonical rows when preview won. Revision lifecycle checks rejected stale/missing bases and missing current revisions with 409, rejected post-confirm preview writes with 403, preserved revision numbers `[1, 2, 3]` and parent numbers `[null, 1, 2]`, and found no duplicate revision numbers.

## Runtime and dependency verification

The final checks used portable PHP 8.4.24, PHPUnit 12.5.33, Composer 2.10.2, MySQL 8.4.11, Node 22.10.0 and pinned pnpm 10.34.5. The default XAMPP PHP 8.1 was not used. Windows sandbox restrictions prevented pnpm CLI startup during preparation; the authorized frontend checks and online audits ran successfully outside that restriction, without changing verification criteria or dependencies.

The existing Vitest update from 3.2.7 to 4.1.11 and its lockfile are retained. Vitest is the sole direct dependency change; its test dependency tree contains the necessary transitive updates. The earlier dependency/advisory investigation remains in the pre-browser checkpoint. Fresh Composer and pnpm audits have no ignores, muted findings or security exceptions.

Vite still emits the existing warning for a minified chunk above 500 kB (the main bundle is approximately 543.81 kB, 150.06 kB gzip). The production build exits successfully. No unrelated bundling changes were made.

## Manual QA and evidence

Manual Browser QA is accepted from the user's explicit confirmation on 2026-09-10 that all Phase 5 scenarios passed. The agent did not repeat the browser sequence in this closeout. The [manual QA runbook](manual-browser-qa.md) records upload/processing, original download, preview/master data/locked fiscal year, stale multi-tab edits, confirmation cancel/replay, retries/invalid files, role boundaries and legacy document compatibility.

Verification logs, JUnit XML, concurrency JSON, sanitized review and scan results are local only under `.foundation-runtime/phase5-closeout-20260910-194315/`. QA passwords, callback secrets, application keys, runtime logs, generated fixtures, screenshots and database contents are not copied into this report or included in commits. Known operational-secret values were compared privately against candidates; broad assignment matches were triaged as test fixtures, examples or references. The scan covers current candidates, not Git history.

All 439 candidate file hashes matched the start snapshot after executable verification. Only final documentation was then edited; it was reviewed and scanned before staging. At the start of the 2026-09-11 continuation, all 440 candidates matched the final documentation scan, including this newly added report. This continuation then changed only this report. The normal `.env` and protected QA settings/credentials remained unchanged. Existing QA runtime is preserved; the isolated MySQL process started for verification was shut down cleanly on 2026-09-10.

The deterministic QA provider verifies the application callback boundary and real Poppler extraction. External n8n/model integration is outside this verification; OCR, Activity/Sub-Activity and PDF Signature remain outside Phase 5 scope.

## Local commit handoff

Phase 5 starts from `ea613ec1b61427eaa8701e431094bd2b6a39e3b7` on `main`. The verified changes are grouped into exactly four local commits:

| Group | Contents |
| --- | --- |
| Dependency/security fix | Vitest 4.1.11 and its pnpm lockfile updates. |
| Backend/import workflow + tests | Import APIs, extraction/callback processing, preview/confirmation, immutable originals, configuration, migrations and PHP tests. |
| Frontend/import UI + tests | Import screens, API contracts/client, navigation, original downloads, imported Project detail and frontend tests. |
| QA harness/docs/report | Disposable QA/provider/concurrency harnesses, manual QA runbook, historical checkpoint and this final report. |

Closeout checks include `git diff --cached --check` before and after each commit, followed by `git status --short --branch`, `git log --oneline -10`, a comparison against `origin/main`, and a final tracked-runtime/secret check. Exact commit hashes, the ahead count and final Git status are supplied in the handoff message and ignored closeout evidence.

No reset, revert, amend or push was performed. The existing worktree implementation was preserved; this closeout only added the final evidence/report updates before committing the reviewed Phase 5 changes.
