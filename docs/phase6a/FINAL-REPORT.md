# School-DSS Phase 6A Final Report

Backend/security verification date: 2026-09-12 (Asia/Bangkok).
Local closeout date: 2026-09-13 (Asia/Bangkok).

Status: the user accepted the backend/security verification below and authorized Phase 6A closeout into local commits only. TypeScript typecheck, Vitest, production build, pnpm audit, and Composer audit remain BLOCKED / NOT PASSED. Local closeout does not establish successful frontend validation, clean dependency audits, or deployment readiness. No push is included.

## Delivered behavior

Phase 6A adds an immutable document-version registry and authenticated download of verified original bytes. It connects the existing Phase 5 Confirm and legacy upload paths to a shared revision-1 registration service and provides a guarded command for existing documents. It does not add a document editor, signature workflow, or frontend integration.

### Registry and immutable revision 1

The SQLite/MySQL migration creates `document_versions` with a public UUID, parent ProjectDocument, revision number, storage locator, original filename, MIME type, exact byte size, SHA-256, integrity basis, creator/source, and verification/creation timestamps. Unique constraints protect public IDs and document/revision pairs. Foreign keys restrict parent and creator deletion/key changes; CHECK constraints validate revision, size, hash format, creation source, integrity basis, and creator/source pairing.

`DocumentVersion`, its enums/DTOs, and `DocumentVersionService` register only revision 1. Row locks and the unique constraint serialize competing registration/backfill calls. An exact replay returns the winning row without changing its identity, actor, provenance, or timestamps; a conflicting baseline is rejected.

ORM guards and database triggers reject version UPDATE/DELETE, including no-op updates. SQLite also rejects implicit replacement through REPLACE. Once a document has a registered version, its primary ID and source identity fields are frozen: project, source import, filename, path, disk, MIME, size, uploader, and checksum. The legacy `ProjectDocument.version` scalar is neither interpreted as history nor changed. Migration rollback refuses a populated registry before any DDL; empty-registry rollback is supported.

### Phase 5 compatibility and legacy upload

Canonical Phase 5 Confirm registers revision 1 within the same transaction that creates the Project, document, content, and related records. Import-first/document-second locking matches backfill's lock order. Exact import provenance checks cover storage disk/path, original name, MIME, checksum, size, uploader, confirmed project, and permitted import state. The in-flight Confirm exception requires its enclosing canonical transaction. Confirm replay preserves the existing result.

The legacy upload path stores its new original, then creates the document, revision 1, and upload audit transactionally before webhook dispatch. Failed registration rolls back database work and attempts compensation only for the newly generated upload destination. Existing originals are not replaced or copied by registration.

The existing Phase 5 import-original download route, authorization contract, and Project document fields remain intact. Project detail adds authorized `initial_version` metadata containing only public ID, revision number, and the version-download URL. It does not expose version storage locators. The new version endpoint's checksum guarantee does not retroactively change the old original-download endpoint.

### Existing documents and backfill

`documents:backfill-versions` is dry-run by default. `--apply` registers verified revision 1; `--document-id`, bounded `--chunk`, and an optional JSON `--manifest` support controlled batches. Each document is handled independently, with explicit success/replay/failure counts and a failing exit status when any selected document fails.

No missing historical disk is guessed. A manifest must supply exactly storage disk/path, SHA-256, and byte size; it cannot redirect an existing locator, and its byte expectations are verified. Invalid, missing, ambiguous, public, aliased, or inconsistent sources fail explicitly. Imported documents must retain complete confirmed provenance.

The registry labels a verified pre-existing checksum as `recorded_sha256`. A document without a recorded checksum receives `observed_sha256`, which records the bytes observed now and does not prove those bytes match an unavailable historical original. Backfill neither manufactures earlier revisions nor changes source rows/files.

### Authenticated version download and byte protection

The new API route is:

```text
GET /api/v2/projects/{project}/documents/{projectDocument}/versions/{documentVersion:public_id}/download
```

The controller checks nested ownership and authorizes before reading the blob. The policy rejects inactive users and deleted/unviewable projects; imported versions additionally require the original-import ACL. Cross-project/document requests cannot use another document's version UUID.

The verifier accepts explicitly allowed private local disks, validates path segments and canonical containment, and rejects public storage, traversal, Windows junction/symlink escapes, and import-root aliases. It checks exact size and SHA-256 before constructing a successful response. Downloads stream a verified private temporary handle, so later modification of the original cannot change the already verified response bytes.

Responses use attachment disposition, exact Content-Length, CSP sandbox, nosniff, and private/no-store caching. Generic errors conceal private locators. Cleanup is covered for success, verification/setup failure, application exceptions, and abandoned responses.

## Accepted verification results

This closeout reuses the user's explicitly accepted verification and the existing final evidence. It did not rerun backend suites, database setup, concurrency, or browser QA. The preceding gate retry reran only the blocked frontend/audit commands; closeout repeats scope, staged-file, secret/runtime, whitespace, and Git checks.

| Gate | Result |
| --- | --- |
| Targeted SQLite | PASS: 149 tests, 1,589 assertions |
| Targeted isolated MySQL | PASS: 148 tests, 1,585 assertions; 1 intentional skip (149 collected) |
| Full SQLite | PASS: 304 tests, 3,099 assertions |
| Full isolated MySQL | PASS: 303 tests, 3,095 assertions; 1 intentional skip (304 collected) |
| MySQL concurrency | PASS: all 11 cases described below |
| Windows junction escape | PASS: 1 test, 8 assertions; rejected before source reading, target bytes preserved |
| PHP syntax | PASS: all 35 changed/new PHP files on PHP 8.4.24 |
| Pint | PASS: all 35 changed/new PHP files with `--test` |
| Whitespace | PASS: `git diff --check`; new files checked; staged checks repeated before each local commit |
| Composer validation | PASS: Composer 2.10.2 `validate --strict` |
| Composer platform requirements | PASS: all 18 requirements |
| Secret/runtime/artifact scan | PASS: prior 465-candidate scan; closeout candidates/staged content checked again |
| Immutability/provenance/version-download security review | PASS: no blocking application/security finding in reviewed scope |

The sole MySQL skip is `DocumentVersionSchemaTest::test_sqlite_revisions_and_sizes_require_stored_integers`: "SQLite affinity needs explicit stored-type checks." It is intentional SQLite-specific coverage. The four final JUnit reports have zero errors and failures.

The targeted filter covers `DocumentVersion|DocumentBlobVerifier|DocumentImportConfirmation|ImportExtractionCallback|ImportedOriginalSessionDownload|ImportedDocumentLegacyLifecycle|ImportedProjectDetail`. Full runs include existing Phase 5 upload/callback/preview/Confirm/download/idempotency regressions.

MySQL verification used the guarded disposable MySQL 8.4.11 server at loopback port 33084 and `school_dss_foundation_test`, with the expected workspace runtime datadir. SQLite used in-memory test databases. The normal application database was outside these suites.

### Concurrency: 11 passing cases

| Group | Cases | Evidence and invariant |
| --- | ---: | --- |
| Version registration/backfill | 8 | Registration/registration, backfill/registration, backfill/backfill, and outer-transaction rollback/backfill, each for legacy and imported originals |
| Fresh canonical Confirm | 1 | Two independent real Confirm calls; one creation and one replay, one Project/document/content/version |
| Confirm replay/backfill | 2 | Real Confirm replay races with backfill for an already versioned Confirm fixture and a historical confirmed fixture missing revision 1 |

The eight version races preserved one committed revision 1, source rows/files, winner identity/provenance/timestamps, and replay state without failed-transaction residue. Six ordinary cases observed two scoped worker lock waiters; each forced rollback case observed the required backfill waiter. The two Confirm/backfill cases observed both workers waiting on the same import row and inserted only a missing initial version.

The fresh Confirm harness observed two overlapping waiters and verified canonical calls/final row counts; its lock-wait count is global, so it ran in isolation. The Phase 6A harnesses scope lock evidence to worker connections and guard the live server in every worker. See [the concurrency runbook](../../scripts/phase6a/README.md) and the two Phase 6A harnesses.

An earlier concurrency launch overlapped a targeted MySQL run and deadlocked during schema setup. That attempt is invalid evidence. The final targeted MySQL suite and all concurrency harnesses ran serially and passed; the serial JUnit/report files below are the accepted results.

## Blocked frontend and audit gates

These gates are NOT PASSED. A launcher/network failure is not a test result or a clean advisory result.

| Gate | Status and cause |
| --- | --- |
| TypeScript typecheck | BLOCKED / NOT PASSED: pnpm startup exits 1 with EPERM |
| Vitest | BLOCKED / NOT PASSED: pnpm startup exits 1 with EPERM |
| Production build | BLOCKED / NOT PASSED: pnpm startup exits 1 with EPERM |
| pnpm audit | BLOCKED / NOT PASSED: pnpm startup exits 1; dependency advisory status unverified |
| Composer audit | BLOCKED / NOT PASSED: curl error 7 connecting to repo.packagist.org:443; dependency advisory status unverified |

The final retry confirmed the correct `laravel-app` working directory and existing Node 22.10.0 / pnpm 10.34.5. Node starts and the installed pnpm entrypoint on D: resolves, but Node realpath resolution of the project/installed shims/default temp path crosses the restricted user directory. Each existing command (`pnpm run typecheck`, `pnpm run test`, `pnpm run build`, `pnpm audit --json`) fails before script/audit execution:

```text
EPERM: operation not permitted, lstat 'C:\Users\User'
    at Object.realpathSync (node:fs:2705:29)
```

The full stack enters pnpm's bundled temp-dir initialization at `dist/pnpm.cjs:107762:19`, then tempy and fetcher/client/store initialization. This is an environment/tool restriction. No manifest, lockfile, Node/project configuration, dependency, or environment workaround was made.

Composer audit was retried once with existing PHP 8.4.24 and Composer 2.10.2, using `--no-cache --no-interaction --no-plugins audit --format=json`. The retry exited 1 at CurlDownloader.php line 407:

```text
curl error 7 while downloading https://repo.packagist.org/packages.json: Failed to connect to repo.packagist.org:443 after 0 ms: Could not connect to server
```

This is an external network restriction. Composer sources and TLS/security settings were not changed. Neither audit established an absence of vulnerabilities. Historical Phase 5 frontend/audit passes are not substituted for these blocked Phase 6A gates.

## Final scope and security review

| Boundary | Closeout finding |
| --- | --- |
| PDF.js | No files, imports, packages, or integration added |
| Signature slots or signature assets | None added; the CLI `$signature` declaration is ordinary Laravel command metadata |
| PDF stamping/signing | No schema, services, routes, assets, or UI added |
| Budget/Evaluation | No behavior/schema/frontend changes; existing symbols in touched router/provider are unchanged context |
| Activity/Sub-Activity | No implementation, schema, routes, or UI added |
| Dependencies/manifests/locks | No changes, installations, or upgrades; package/composer manifests, locks, and pnpm workspace configuration match the closeout base |
| Runtime/secrets | No runtime logs, databases, temporary files, private uploads, environment files, dependency trees, or operational secrets included in local commits |

Review covered ORM and raw-SQL immutability, source identity/provenance, exact replay, transaction failure/rollback, guarded backfill, nested authorization, import ACL, locator confidentiality, checksum-before-response, and temporary-file cleanup. No blocking defect was found in that reviewed scope. Scope review distinguishes Phase 6A additions from existing unrelated router/provider code.

## Evidence and remaining limitations

Detailed evidence remains local and ignored under `.foundation-runtime`; runtime logs, databases, temporary manifests, and credentials are excluded from commits. Primary records are:

- `phase6a-final-20260912/`: `targeted-sqlite.xml`, `targeted-mysql-serial.xml`, `full-sqlite.xml`, `full-mysql.xml`, `verification-summary.md`, `security-review.md`, and syntax/format/Composer/scan records.
- `phase6a-continue-20260912/02-junction-escape-final.xml`: junction-only evidence.
- `phase5-phase6a-final-versions/b90b51b62ee02263-report.json`: eight version races.
- `phase5-phase6a-final-confirm/`: fresh Confirm worker records.
- `phase5-phase6a-final-confirm-backfill/427c538cfd1374b3-report.json`: two Confirm/backfill cases.
- `phase6a-gate-retry-2026-09-12T19-08-02-095Z/`: exact final retry commands, full EPERM stacks/network error, exit codes, Git outputs, and matching dependency hashes.

Remaining limitations:

- Frontend validation and both dependency audits need a permitted execution/network environment; they remain incomplete at local closeout.
- Registration supports revision 1 only. No edit/revision-2/history UI or signature functionality is delivered.
- Supported databases are SQLite and MySQL; filesystem verification is restricted to explicitly allowed private local disks (`local` and `project-imports`), not remote object storage.
- The registry preserves metadata and references the source; it does not archive/copy originals. External deletion/tampering can make a registered version unavailable, and version download rejects a size/hash mismatch.
- `observed_sha256` establishes an observation-time baseline, not proof of unavailable historical bytes. Missing/ambiguous legacy storage requires explicit verified mapping.
- Existing Phase 5 original downloads retain their previous contract; clients must use the new version endpoint for its checksum-before-response guarantee.
- A populated registry prevents migration rollback. No production migration or backfill is performed during closeout.
- Cleanup assertions do not cover forced operating-system termination or actual filesystem unlink failure. Verification is scoped to Windows/PHP 8.4.24, in-memory SQLite, and isolated MySQL 8.4.11; no new browser or deployment verification is claimed.

## Local commit handoff

Closeout starts from `5bec8ac375fc3073a4286d4ed2f5377623b4421f`, the local `main` and `origin/main` reference at the start. The handoff is split into:

1. `feat: add Phase 6A document version foundation`: migration/schema, model, enums/DTOs, verifier/services, Confirm/legacy upload integration, backfill, authenticated version download, and authorized API metadata.
2. `test: verify Phase 6A document version lifecycle`: lifecycle/security/regression tests, guarded MySQL/concurrency harnesses, runbook, and this report.

Before each commit, the exact staged paths/content are reviewed, `git diff --cached --check` must pass, and secrets/runtime/dependency exclusions are checked. Final handoff checks status, commit log/stat against `origin/main`, ahead/behind, manifests/locks, and tracked runtime. Exact resulting commit IDs and final worktree status are supplied in the handoff message.

No reset, revert, amend, dependency change, or push is part of this closeout. The application implementation is preserved; this continuation adds only the final report before creating the authorized local commits.
