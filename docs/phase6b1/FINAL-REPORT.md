# School-DSS Phase 6B1 Final Report

Verification and local closeout date: 2026-09-14 (Asia/Bangkok).

Status: backend verification passed and the user authorized local closeout into two commits. **Composer audit and pnpm audit are BLOCKED / NOT PASSED.** Dependency advisory status remains unverified. This report does not establish an all-gates pass or deployment readiness. No push is included.

## Delivered foundation

Phase 6B1 introduces four canonical Project signature assignment slots. It stores assignment structure, revision and provenance, and provides authorized read, candidate search, assignment and maintenance backfill operations.

| Slot number | Canonical code | Eligible source role codes |
| ---: | --- | --- |
| 1 | `project_proposer` | `teacher`, `department_head`, `deputy_director`, `director` |
| 2 | `related_approver` | `teacher`, `department_head`, `deputy_director`, `director` |
| 3 | `deputy_director` | `deputy_director` |
| 4 | `director` | `director` |

The enum fixes these mappings. The SQLite/MySQL schema enforces exact canonical code/number pairs, unique Project/code and Project/number pairs, assignment metadata consistency, and revisions from 0 through 4,294,967,295. Blank initial rows have null assignee, assigner and assignment time, with revision 0. Assigned rows require all three fields and revision at least 1. Clearing an assignment retains its incremented revision while setting all three assignment fields to null.

Project, assignee and assigner foreign keys use RESTRICT for parent DELETE and key UPDATE. The model rejects changes to slot identity (`id`, Project, code, number and creation time) and rejects slot deletion. These model guards are not database UPDATE/DELETE triggers. Migration rollback refuses any populated slot table, including blank initialized rows, before DDL; empty-table rollback is supported.

## Permission and API contract

`projects.signatures.manage` is required for assignment changes and candidate search. `ProjectSignaturePermissionSeeder` adds this permission idempotently to the existing `director` and `deputy_director` roles without detaching other grants; missing required roles fail the seeder transaction. `AuthorizationSeeder` invokes it. The dedicated seeder adds grants and does not revoke separately configured grants.

Signature access requires an active, non-deleted actor, a non-deleted Project, and existing Project visibility. Management additionally requires the explicit permission. There is **no owner, `projects.edit_all`, ordinary edit permission, or high-role bypass**. Assigning a person creates no ProjectAccess grant and does not give that person new Project visibility. A single eligible person may occupy more than one slot.

Authenticated API routes are:

```text
GET /api/v2/projects/{project}/signature-slots
GET /api/v2/projects/{project}/signature-slots/{slotCode}/candidates
PUT /api/v2/projects/{project}/signature-slots/{slotCode}/assignment
```

Responses use `Cache-Control: no-store`. Hidden/deleted Projects are concealed with 404; a visible Project without management permission returns 403 for management operations. Canonical route codes and Project-scoped lookup prevent cross-Project slot targeting. Assignment input accepts exactly `assigned_user_id` (positive JSON integer or null) and `assignment_revision` (bounded JSON integer). Unsupported fields, identity-edit fields and invalid types are rejected.

## Creation and Phase 5 Confirm/replay

Every current application Project creation path initializes all four blank slots within its enclosing creation transaction: V2 Project creation through `ProjectService`, the legacy `/projects` controller, and canonical Phase 5 Confirm through `ProjectService::createInTransaction`. Source review found the two application `Project::create` sites and confirmed both integrations. Direct factory/raw-database inserts do not have an automatic model hook; historical or externally inserted Projects need explicit backfill.

Fresh Confirm creates slots together with the Project, imported document, Phase 6A initial document version, and the existing canonical records. Confirm replay returns the existing result and preserves exact slot rows and audit history. Historical Confirm replay does not repair absent slots. Import/preview reads, preview save/replay and extraction callback/replay create no slots. Existing Phase 5 and Phase 6A behavior is covered by regression results below.

Initialization writes one `project_signature_slots.initialized` audit containing the inserted slot snapshots, actor and source (`project_create` or `legacy_create`). Initialization or later creation/Confirm audit failure rolls back the slots and enclosing canonical database work; Confirm rollback preserves the original import state and source file.

## Assignment lifecycle, no-op and stale revisions

Actual assign, reassign and clear operations increment the revision exactly once and write one corresponding `project_signature_slot.assigned`, `.reassigned` or `.unassigned` audit with before/after snapshots and the actor. The write and audit share one transaction, so audit failure rolls back assignment fields, revision and timestamps.

The service locks the Project, actor/target users in ID order, and slots. It reloads the current actor and role permissions and reauthorizes inside the transaction. It compares the client's expected revision before deciding whether the requested assignment is unchanged. Actual writes also condition their UPDATE on that revision. A stale request returns 409 `assignment_revision_conflict`, including stale same-target requests and an A-to-B-to-A assignment history. Database deadlock retries retain the original client revision.

A same-target request with the current revision, including clearing an already empty slot, is a true no-op: no revision increment, timestamp/provenance change, audit or other row write. No-op handling precedes eligibility validation, so an existing assignment remains unchanged if that assignee has since become inactive, soft-deleted or ineligible after a role change. An actual change to an ineligible target returns 422. A changed assignment at the maximum revision returns 409 `assignment_revision_exhausted` instead of overflowing.

## Candidate eligibility, privacy and user lifecycle

New assignees must be active, non-deleted users with an exact eligible role from the canonical table. Eligibility does not require department equality or existing candidate Project access. The same rules apply to candidate lookup and an actual assignment change.

Candidate search requires management authorization, a trimmed name query of 2-100 characters, and pagination bounded to 20 results per page. It searches names with bound parameters and escaped LIKE wildcards. Results have stable name/ID ordering and use simple pagination without a total user count. Unsupported search fields are rejected.

Candidate and assignee summaries expose only ID, name, role code/name and department ID/name. Email, phone, teacher code, permissions and storage locators are excluded. Slot responses expose assignment revision/time and `assignee_status`; soft-deleted or missing users have no assignee summary. Inactive/ineligible existing assignees remain identifiable to authorized Project viewers through the limited summary.

User deactivation, soft deletion, restoration and role changes preserve stored assignments and provenance; status reflects current eligibility. Referenced assignees and assigners cannot be hard-deleted or have their keys changed while referenced. Project soft deletion preserves slots and hides the API; hard deletion/key changes are restricted by the Project FK. Phase 6B1 does not automatically unassign or reassign users during lifecycle changes.

## Backfill and transactional audit

`projects:backfill-signature-slots` defaults to dry-run. `--apply` inserts only missing canonical blank slots; repeated `--project-id` selection and bounded `--chunk=1..1000` support controlled batches. Active and soft-deleted Projects are included. In apply mode, each selected Project is handled independently under a Project lock and transaction; dry-run only inspects.

Partial sets preserve every existing assignment, revision, actor and timestamp. Complete sets are a no-op with no audit. Malformed rows fail explicitly as `signature_slots_inconsistent`; backfill never silently repairs them. Missing selected IDs and failed Projects are counted and cause a failing command exit while successful Project transactions remain committed. Errors are sanitized to avoid leaking database or exception details.

Each changed Project gets one `project_signature_slots.backfilled` audit with inserted snapshots, source `backfill`, a run UUID and null actor. Audit failure rolls back only the affected Project's inserts. Expected canonical duplicate-key races roll back the entire attempt before rereading, with at most three attempts. Unrelated integrity errors propagate from the retry boundary unchanged and become a sanitized command failure.

Reads and assignment operations do not initialize missing slots: incomplete sets return 409 `signature_slots_not_initialized`. Backfill adds no assignees, ProjectAccess, signature assets or document files. No production migration or backfill is performed during this closeout.

## Accepted verification results

This closeout uses the user's accepted verification and retained local evidence. Final JUnit totals, concurrency records and blocked audit logs were inspected again. Backend suites, schema setup, concurrency, syntax, Pint and dependency commands were not rerun during closeout; source behavior was not changed. Closeout repeats scope, artifact/secret, dependency hash, staged whitespace and Git checks.

Passing test counts below exclude intentional skips. All final JUnit reports have zero failures and zero errors.

| Gate | Result |
| --- | --- |
| Targeted SQLite | PASS: 84 tests / 733 assertions |
| Targeted MySQL | PASS: 97 tests / 985 assertions; 1 intentional SQLite-only skip |
| Full SQLite | PASS: 388 tests / 3,832 assertions; 14 MySQL-only skips |
| Full MySQL | PASS: 400 tests / 4,080 assertions; 2 SQLite-only skips |
| Assignment/backfill concurrency | PASS: all 5 cases below |
| PHP syntax | PASS: 30 changed/new PHP files, including rechecks after formatting |
| Pint | PASS: `--test` on all 30 PHP files |
| Whitespace | PASS: accepted diff/new-file checks; staged checks repeated before each local commit |
| Composer validation/platform | PASS: Composer 2.10.2 `validate --strict`; 18 platform requirements |
| Secret/runtime/artifact scan | PASS: accepted 31-path source scan; closeout candidate/staged checks include this final report |
| Authorization/security review | PASS: no blocking application/security finding in reviewed scope |

Targeted SQLite combines accepted component runs: schema/creation 18 tests / 274 assertions, no-op 9 / 95, backfill 15 / 96, and authorization/candidate/assignment 42 / 268. Targeted MySQL combines 83 passing tests / 727 assertions plus one SQLite-only skip with 14 matcher/constraint tests / 258 assertions. Each full suite collected 402 tests. SQLite skips the 14 MySQL-only matcher/constraint tests; MySQL skips the SQLite integer-affinity test and SQLite PRAGMA anomaly fixture. A MySQL temporary-fixture test separately verifies anomaly rejection. Skips are not passes.

Existing Phase 5 regressions extracted from each full suite passed 78 tests / 1,369 assertions. Phase 6A regressions passed 96 / 671 on SQLite and 95 / 667 with one SQLite-only skip on MySQL. These subsets use `DocumentImport|ImportExtractionCallback|Imported` and `DocumentVersion|DocumentBlobVerifier`. New Phase 6B1 creation tests add Confirm/replay and rollback coverage; no existing Phase 5/6A test files were modified.

Verification used PHP 8.4.24, in-memory SQLite, and guarded disposable MySQL 8.4.11 at `127.0.0.1:33084`, database `school_dss_foundation_test`, with the workspace's ignored `.foundation-runtime/phase5-mysql/data` directory. The normal application database and legacy/XAMPP port 3306 were not used. Existing guards verify opt-in, exact connection and actual server version/bind address/datadir for each MySQL invocation and concurrency process.

### Actual MySQL constraints and duplicate matcher

All three named CHECK constraints were verified as `ENFORCED=YES`. Tests cover exact canonical mapping (including case/trailing-space rejection), assignment metadata invariants, revision bounds and uniqueness. Actual invalid mapping/assignment writes raise MySQL 3819 with the exact CHECK names. Actual parent DELETE/UPDATE attempts raise 1451 for the Project, assignee and assigner FK names; schema tests also verify orphan rejection and soft-delete/restore preservation.

Both migration unique index names are verified in `information_schema` and exercised independently with connection-local temporary tables. Real 1062 errors are recognized only for the exact allowed terminal key field, optionally qualified by the exact `project_signature_slots` table. Similar/old names, the same index name on another table, PRIMARY, unrelated unique errors and misleading duplicate values are rejected. Unknown unique/FK/CHECK errors are not retried or swallowed; tests verify no partial slot/audit residue. Expected duplicate retries are bounded and start after rollback.

The anomaly fixture shadows the table only in its current connection and is dropped in `finally`; migrated constraints are not disabled or altered. An earlier metadata test failed because MySQL returned uppercase column names; explicit SELECT aliases fixed that evidence query. The final complete 14-test run passed, and the failed attempt remains in ignored evidence as `12-mysql-matcher-constraints.log`.

### Concurrency: five passing cases

| Case | Verified outcome |
| --- | --- |
| Assignment: same revision, different eligible targets | One real HTTP-kernel 200 and one 409 conflict; one revision increment and one matching audit |
| Backfill: empty active Project | One initializer, one already-complete result; four inserted canonical slots |
| Backfill: partial active Project | One initializer, one already-complete result; three inserted slots; historical assigned revision 7 preserved |
| Backfill: empty soft-deleted Project | One initializer, one already-complete result; four inserted canonical slots |
| Backfill: partial soft-deleted Project | One initializer, one already-complete result; three inserted slots; historical assigned revision 7 preserved |

Every case observed two independently identified MySQL worker connections waiting on the coordinator-held Project row before release. Final checks established four canonical slots, one new audit, unchanged unrelated rows/prior audits, no new ProjectAccess and no open transaction residue. Same-target/current-revision replay, stale same-target conflict and backfill rerun checks preserve the appropriate committed rows. All ten worker stderr logs were empty. See the [concurrency runbook](../../scripts/phase6b1/README.md).

## Blocked audits: BLOCKED / NOT PASSED

| Audit | Status and retained failure |
| --- | --- |
| Composer audit | **BLOCKED / NOT PASSED**: `curl error 7` connecting to `repo.packagist.org:443`; Packagist could not be reached. Retained `audit --locked --format=json` exit code: 100. The log also reports an unwritable cache location. |
| pnpm audit | **BLOCKED / NOT PASSED**: `EPERM: operation not permitted, lstat 'C:\Users\User'` during pnpm initialization. Retained `audit --json` exit code: 1. |

Neither command produced a successful advisory result. Composer validate/platform passes do not establish an audit pass. No clean dependency audit, absence of vulnerabilities, or substitute historical audit pass is claimed. Dependencies, manifests/locks, TLS settings and security settings were not changed to work around these failures. These two audits need a permitted execution/network environment.

## Strict scope and artifact closeout

Review covers the complete Phase 6B1 delta from `origin/main`, including new files. No blocking application/security finding was identified in authorization, candidate privacy/eligibility, lifecycle preservation, stale/no-op handling, transactional audits, exact retry matching or guarded harnesses.

| Excluded scope | Closeout finding |
| --- | --- |
| Signature assets or image upload | None added; no embedded image payloads or upload implementation |
| GD/Imagick | No use or dependency added |
| PDF stamping, PDF.js or coordinates | No implementation, assets, routes or packages added |
| Signing events or other signing concurrency | None added; new concurrency is limited to assignment/backfill |
| React | No frontend implementation changed |
| Budget/Evaluation | No behavior/schema/frontend changes; existing router context and Project test payload fields are unchanged scope |
| Activity/Sub-Activity | No implementation/schema/routes/UI changes |
| Dependency manifests/lockfiles | No changes; five tracked manifest/lock/workspace files match `origin/main` and closeout SHA-256 baselines |
| Runtime/database/temp/secrets | No foundation runtime, MySQL datadir/logs, database exports, temp files, operational credentials or secrets included in the closeout files |

The callback HMAC string in the new Confirm/callback regression test is locally defined test data, not an operational secret or signing-event implementation. Concurrency scripts are source harnesses; generated fixtures, database data, logs and reports remain under ignored runtime directories.

`.foundation-runtime` remains ignored by `.gitignore`, exists locally, and has no tracked/staged entries. Before each commit, exact staged paths/content are inspected, `git diff --cached --check` must pass, and secret/runtime/database/temp/dependency exclusions are checked. The same boundaries and dependency hashes are checked after both commits.

## Evidence and remaining limitations

Detailed runtime evidence stays local and ignored. Primary records under `.foundation-runtime/phase6b1-policy-20260914/` are `06-sqlite-schema-creation.log`, the no-op/backfill logs, `07-sqlite-authorization-candidate-assignment.xml`, `11-targeted-mysql.xml`, `13-mysql-matcher-constraints.xml`, `15-full-sqlite.xml`, `16-full-mysql.xml`, `17-php-syntax.json`, `24-pint-final.log`, `19-composer-validate.log`, `20-composer-platform.log`, `22-composer-audit.*`, `23-pnpm-audit.*`, `27-regression-totals.json`, and `security-review.md`. The five-case final concurrency record is `.foundation-runtime/phase5-phase6b1-concurrency/d66f5dcb5eb832e4-report.json`, with associated worker/case files.

Remaining limitations:

- Both dependency audits remain **BLOCKED / NOT PASSED**; advisory status is unverified.
- This is a backend assignment foundation. It does not provide signature assets, rendering, signing events, PDF stamping or React integration; no new frontend/browser validation is claimed.
- Application creation paths guarantee initialization. The database constraints do not force four rows after arbitrary raw inserts or make slot identity/deletion immutable against raw SQL; model/API guards and explicit backfill define that boundary.
- Existing incomplete Projects require backfill; reads and Confirm replay do not perform repair. Malformed historical rows require explicit investigation.
- User lifecycle changes do not automatically replace assignments. Historical assignee status may become inactive, soft-deleted or ineligible.
- Concurrency evidence is one controlled worker pair per scenario, not sustained-load testing. Assignment workers use the real HTTP kernel with an authenticated web guard, not browser/network login. Concurrent changes to role/permission tables are not a separate tested race scenario.
- Dedicated maximum-revision-exhaustion and signature-endpoint-specific unauthenticated HTTP cases were not identified in this test set; boundary handling and shared authentication middleware were reviewed without claiming extra executed tests.
- Database verification is scoped to SQLite and isolated MySQL 8.4.11 on the recorded local environment. A populated migration cannot be rolled back by its `down()` method. No production migration, backfill, deployment or push is performed.

## Local commit handoff

Closeout starts from `944bf79def6be3b194e04db6c432ecb8ce87f5b1`, the local `main` and `origin/main` reference at the start. The reviewed grouping is:

1. `feat: add Phase 6B1 signature slot foundation`: migration/schema, enum/model, services, policies, permission seeder, Project creation integrations, assignment/candidate APIs and backfill command (19 files).
2. `test: verify Phase 6B1 signature slot lifecycle`: nine test/fixture files, three concurrency harness/runbook files and this final report (13 files).

The Phase 5/6A regression addition is in the new creation test, with existing regression suites represented by their accepted results; there are no artificial edits to existing tests for grouping. This closeout adds only this final report to the pre-existing implementation/tests before committing them.

Final handoff checks `git status --short --branch`, `git log --oneline origin/main..HEAD`, `git diff --stat origin/main..HEAD`, local/reference HEADs, ahead/behind, all five dependency SHA-256 hashes and ignored/untracked runtime. Exact resulting commit IDs and final worktree state are supplied in the handoff message. `origin/main` is the existing local remote-tracking reference; no fetch or push is part of closeout.

No reset, revert, amend or dependency change is performed. The commits remain local.
