# Phase 6B1 isolated MySQL concurrency verification

Run after targeted SQLite passes and the isolated MySQL guard is checked. This harness runs `migrate:fresh` against the disposable schema. Every process reuses the Phase 5 opt-in and exact connection guard plus the Phase 6A actual server guard: MySQL exactly `8.4.11`, `127.0.0.1:33084`, `school_dss_foundation_test`, and this workspace's `.foundation-runtime/phase5-mysql/data` directory. It does not start or stop MySQL.

From the repository root, with the existing isolated server running:

```powershell
$phaseSixBOneRuntime = Join-Path (Get-Location) '.foundation-runtime\phase5-phase6b1-concurrency'
New-Item -ItemType Directory -Path $phaseSixBOneRuntime -Force | Out-Null
$env:ALLOW_MYSQL_FOUNDATION_TESTS = '1'
& .\scripts\foundation\php.ps1 .\scripts\phase6b1\signature-concurrency.php run $phaseSixBOneRuntime
```

Do not run alongside any other database test. Five cases use independent PHP workers and require two real `performance_schema.data_lock_waits` observations on the coordinator-held project row before releasing either writer:

- Two real assignment API requests with revision zero and different eligible targets: one HTTP 200, one HTTP 409 `assignment_revision_conflict`, one revision increment and one matching audit. A later same-target replay preserves every database row; a stale same-target request still returns 409.
- Two real `projects:backfill-signature-slots --apply` commands for each of empty/partial slot sets on active/soft-deleted projects: one initializer, one already-complete result, exactly four canonical slots, and one backfill audit. Partial fixtures retain their preexisting assigned row, revision, actor and timestamps. A later backfill preserves every row.

The harness verifies unchanged unrelated database rows and existing audits, no ProjectAccess grants and no open transaction residue. It creates no signature assets or document files. It never suppresses a database exception as an expected race; any unexpected HTTP result, nonzero backfill exit or worker error fails the run. Separate PHPUnit coverage verifies exact duplicate index matching, unknown integrity error propagation, and actual CHECK/FK enforcement.

Unique run IDs preserve prior fixture, readiness, result, worker log, case report and final report files in the ignored runtime. Treat any nonzero exit as a stop condition and inspect the retained output before further testing. A final `*-report.json` with `passed: true` is written only after all five cases pass.
