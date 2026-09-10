# Phase 5 pre-browser verification and QA readiness — 2026-09-10

This is the historical pre-browser checkpoint, retained for dependency-upgrade and QA-readiness evidence. The user subsequently confirmed that Manual Browser QA passed completely. The authoritative closeout results and current completion status are in [FINAL-REPORT.md](FINAL-REPORT.md).

At this checkpoint, all requested security, frontend and affected backend gates had passed and the disposable QA environment was ready for manual browser review. No commit or push had been performed at that point. Runtime counts and process status below describe that earlier snapshot, not the post-QA environment.

## Security fix and dependency scope

The resumed worktree already contained the normal pnpm upgrade from `vitest@3.2.7` to the exact `4.1.11` version, with its updated lockfile and prior upgrade evidence. This verification retained that change and completed the remaining gates; it introduced no additional dependency upgrades.

- Advisory: **GHSA-82fw-gwwq-j7x9 / CVE-2026-84373**, moderate, CVSS 5.9; npm audit records `1193683` and `1193684` cover Vitest and its mocker.
- Affected: `>=2.1.0 <4.1.11`, and `>=5.0.0-beta.1 <5.0.0-rc.2`.
- Patched: **4.1.11** and **5.0.0-rc.2**. The reviewed package ranges distinguish fixed prereleases from affected ones; the older 3.x line has no planned backport.
- Previous path: `school-dss -> devDependencies.vitest@3.2.7 -> @vitest/mocker@3.2.7`.
- Verified installed path: `school-dss -> devDependencies.vitest@4.1.11 -> @vitest/mocker@4.1.11`.

These versions and the file-disclosure impact are documented in the [reviewed GitHub advisory](https://github.com/advisories/GHSA-82fw-gwwq-j7x9) and [maintainer advisory](https://github.com/vitest-dev/vitest/security/advisories/GHSA-82fw-gwwq-j7x9). Exploitation requires a reachable development-server mock-registration path. Repository inspection shows `vitest run` in the Node environment, without browser/API mode or the public mocker/interceptor plugins in the application Vite configuration. Therefore the described remote path is not exposed by the current test setup; this is a configuration-based assessment, not an audit exemption.

Published `vitest@4.1.11` metadata accepts Node `^20.0.0 || ^22.0.0 || >=24.0.0` and Vite `^6.0.0 || ^7.0.0 || ^8.0.0`. The verified stack remains Node **22.10.0**, pnpm **10.34.5**, Vite **6.4.3**, React/React DOM **19.2.8**, TypeScript **5.9.3**, and `@types/node` **22.20.1**. No React peer migration is required.

Parsed manifest/lock review confirmed that the saved pre-upgrade baseline matches HEAD. Only `devDependencies.vitest` changes directly. All **36 production dependency snapshots** and all **219 snapshots reachable from the other direct dependencies** retain their dependency edges, versions and integrity metadata. Every added/removed record is exclusively reachable through Vitest.

| Necessary test-tree change | Packages |
| --- | --- |
| 3.2.7 → 4.1.11 | `vitest`, `@vitest/expect`, `@vitest/mocker`, `@vitest/pretty-format`, `@vitest/runner`, `@vitest/snapshot`, `@vitest/spy`, `@vitest/utils` |
| Required transitive replacements | `chai` 5.3.3→6.2.2; `es-module-lexer` 1.7.0→2.3.2; `std-env` 3.10.0→4.2.0; `tinyexec` 0.3.2→1.3.1; `tinyrainbow` 2.0.0→3.1.1 |
| Added by the new test tree | `@standard-schema/spec@1.1.0`, `obug@2.2.1` |
| No longer required | `cac`, `check-error`, `deep-eql`, `js-tokens@9.0.1`, `loupe`, `pathval`, `strip-literal`, `tinypool`, `tinyspy`, `vite-node` |

Package scripts, Vitest configuration, workspace policy, pnpm wrapper and the existing `nanoid: 3.3.18` override are unchanged. No audit ignore/mute, security override, forced dependency installation or audit-gate change was introduced.

## Pre-browser verification results

PHP checks used portable **PHP 8.4.24**, PHPUnit **12.5.33** and Composer **2.10.2**. The machine's default XAMPP PHP 8.1 is unsuitable and was not used for successful checks. The existing pinned pnpm CLI was invoked through Node because pnpm is absent from PATH.

| Gate | Result |
| --- | --- |
| `pnpm audit --json` | PASS; zero advisories at every severity; empty actions and muted arrays |
| `pnpm test` / Vitest 4.1.11 | PASS; 11 files, 59 tests |
| `pnpm typecheck` | PASS; both `tsconfig.app.json` and `tsconfig.node.json` |
| `pnpm exec vite build` | PASS; 187 modules transformed |
| SQLite full suite | PASS; 203 tests, 2,257 assertions |
| Isolated MySQL 8.4.11 full suite | PASS; 203 tests, 2,257 assertions |
| Callback/replay/idempotency targeted suites, SQLite | PASS; 86 tests, 1,253 assertions |
| Same targeted suites, isolated MySQL | PASS; 86 tests, 1,253 assertions |
| Confirm concurrency | PASS; two overlapping row-lock waiters; one Project creation and one reuse |
| Preview concurrency | PASS; distinct edits 201/409; identical replay 201/200 with the same revision; preview-vs-confirm 201/409 |
| PHP syntax | PASS; 321 first-party `.php` files including Blade files, plus `artisan` |
| Pint | PASS; all 83 changed PHP files |
| `git diff --check` | PASS |
| Composer `validate --strict`, `check-platform-reqs`, online `audit --locked` | PASS; all exit 0; no advisories or abandoned packages |
| Artifact/secret/runtime scan | PASS; tracked and nonignored untracked candidate files; no generated artifacts or operational secrets found |

All four PHPUnit JUnit reports have zero errors, failures and skipped tests. Targeted membership: `DocumentImportApiTest`, `DocumentImportConfirmationTest`, `DocumentImportProcessorTest`, `ImportedDocumentLegacyLifecycleTest`, `ImportExtractionCallbackTest`, and `PdfImportInfrastructureTest`.

The MySQL gate verified version, port **33084**, database `school_dss_foundation_test`, and the exact workspace `.foundation-runtime/phase5-mysql/data/` directory before execution. The suite and concurrency scripts ran sequentially with their existing isolated-target guards. The normal server database was not used. Concurrency also preserved canonical row counts, rejected stale/missing revision bases with 409, rejected confirmed-import writes with 403, and kept revision numbers unique through `[1, 2, 3]`.

The Vite build retains its existing warning for a minified chunk above 500 kB. It exits successfully; no unrelated bundling changes were made. Pint scope is the changed Phase 5 PHP files, matching the previous affected gate. The artifact/secret scan covers current candidate files, not Git history; ignored dependencies, private runtime files, build output and backups remain intentionally local.

## Disposable QA environment at the pre-browser checkpoint

- **URL:** <http://127.0.0.1:8015/app/imports>
- **Primary account:** `teacher@phase5-qa.local`
- **Password and all role accounts:** read `.foundation-runtime/phase5-qa/credentials.txt` locally. Credentials are not copied into this report.
- Other accounts: `department-head@phase5-qa.local`, `director@phase5-qa.local`, `other-teacher@phase5-qa.local`, `viewer@phase5-qa.local`, `no-access@phase5-qa.local`, `inactive@phase5-qa.local`.
- **Fixtures:** `.foundation-runtime/phase5-qa/fixtures/`.

The existing disposable SQLite database was preserved, with seven users and zero imports/projects. The locked-year fixture was added. The app, deterministic provider and queue worker run in hidden processes; app/provider ports are loopback-only **8015/8016**. The application's normal `.env` is excluded from QA bootstrap and remains unchanged. The production build is used even if the ordinary app has a Vite hot file.

Fresh readiness checks passed: SPA shell HTTP 200; five compiled assets HTTP 200; local provider health ready; real Poppler extraction of all four text fixtures; six active account logins; inactive login rejected with 422; fourteen role endpoint checks. Teacher/head/director/other-teacher/viewer can load Imports and its options; no-access receives 403; the rejected inactive session receives 401. No imports or projects were created by readiness checks.

| Scenario | Expected result |
| --- | --- |
| Upload `slow-project.pdf`, then inspect the original | Processing is visible during polling, then `needs_review`; authorized original download succeeds |
| Review `text-project.pdf`; choose master data and change fiscal year | Extracted fields/warnings appear; missing required fields block confirmation; selected plan must belong to the fiscal year |
| Locked-year review | Year 2569 is disabled; the prepared locked preview warns and blocks confirmation; saving an open year and valid plan allows confirmation |
| Save the same import from two browser tabs | First edit succeeds; stale second edit returns 409; newer revision remains intact and history uses distinct revision numbers/IDs |
| Cancel, then confirm the saved preview; refresh/retry confirmation | Cancel creates nothing; confirm creates one Project; replay reuses it; confirmed imports reject new revisions |
| Upload `retry-once.pdf`, `no-text-layer.pdf`, `not-a-pdf.txt` | Retry fixture fails once then succeeds; no-text PDF fails extraction; non-PDF is rejected at upload validation |
| Switch roles | Own/department/all visibility follows permissions; viewer sees labels/history without mutation controls; no-access and inactive accounts cannot use Imports |
| Open resulting Project's legacy documents | Imported original cannot be replaced/deleted; ordinary legacy document attachment/editing still works |

For the locked-year scenario, upload `locked-year-project.pdf`, wait for `needs_review`, then run `qa.php locked-preview <import-public-id>` with PHP 8.4.24. The exact command and full scenario sequence are in [manual-browser-qa.md](manual-browser-qa.md).

Readiness checks do **not** represent completed manual browser QA. The deterministic local provider exercises the HMAC callback boundary; external n8n/model integration and OCR are outside this environment. Scanned PDFs without text are expected to fail.

To inspect or stop only the recorded QA processes, use `./scripts/phase5/qa-environment.ps1 status` or `./scripts/phase5/qa-environment.ps1 stop`. Stop preserves the database, fixtures and logs.

## Evidence and worktree state

Fresh logs, JUnit reports, parsed dependency review and readiness results are in the ignored `.foundation-runtime/phase5-security-verification-20260910/` directory. Pre-upgrade chain/manifest/lock and the original normal pnpm upgrade log remain in `.foundation-runtime/phase5-final-verification-20260910/`; the original failing audit remains in the `20260909` verification folder.

At this checkpoint, the branch was `main` at `ea613ec1b61427eaa8701e431094bd2b6a39e3b7` with the Phase 5 worktree changes preserved. No reset, revert, amend, commit or push was performed during that checkpoint. Manual QA was subsequently completed according to the user's confirmation; see [FINAL-REPORT.md](FINAL-REPORT.md) for the fresh final gates.
