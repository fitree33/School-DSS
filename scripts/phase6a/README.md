# Phase 6A isolated MySQL concurrency verification

Run only after targeted SQLite verification succeeds and the disposable MySQL target has been checked. This harness runs `migrate:fresh` against the disposable test schema. It requires the existing Phase 5 opt-in/connection guard and independently verifies the server is exactly MySQL `8.4.11`, bound to `127.0.0.1:33084`, using `school_dss_foundation_test` and this workspace's `.foundation-runtime/phase5-mysql/data` directory. It does not start or stop MySQL.

From the repository root, with the isolated server already running:

```powershell
$phaseSixRuntime = Join-Path (Get-Location) '.foundation-runtime\phase5-phase6a-concurrency'
New-Item -ItemType Directory -Path $phaseSixRuntime -Force | Out-Null
$env:ALLOW_MYSQL_FOUNDATION_TESTS = '1'
& .\scripts\foundation\php.ps1 .\scripts\phase6a\version-concurrency.php run $phaseSixRuntime
```

Do not run this concurrently with other database tests. The process creates fixtures only under its ignored runtime and the exact guarded database. Each run has unique coordinator/result files; prior runtime files are retained.

The eight cases cover registration/registration, real `documents:backfill-versions --apply`/registration, backfill/backfill, and forced outer-transaction rollback/backfill for both legacy and imported originals. Ordinary races require evidence that both independent PHP processes are waiting on the same coordinator-held MySQL source row lock. Rollback races require a backfill waiter blocked behind a registration that has inserted a version but not committed, then deliberately abort that registration before releasing backfill.

Assertions cover a single committed revision 1, matching returned identity, unchanged winner actor/provenance/timestamps after replay, no source row or file changes/copies/deletions, and no unexpected row count changes or failed-transaction residue. Reports include observed lock waiter counts and are saved as `*-report.json` in the runtime. Stop and investigate any nonzero exit before running broader verification.
