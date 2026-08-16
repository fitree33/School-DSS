# School-DSS Phase 1 Foundation Results

Date: 2026-08-17

## Scope

Phase 1 upgraded the existing Laravel application without adding React or any
School-DSS V2 business feature, table, route, or migration. Legacy Blade views,
SQL backups, uploaded documents, and application data were preserved.

## Upgrade gates

| Gate | Locked framework | PHPUnit | Regression result |
| --- | --- | --- | --- |
| Baseline | Laravel 10.50.2 | 10.5.64 | 43 tests / 125 assertions passed |
| Laravel 10 to 11 | Laravel 11.55.1 | 10.5.64 | 43 tests / 125 assertions passed |
| Laravel 11 to 12 | Laravel 12.66.0 | 11.5.56 | 43 tests / 125 assertions passed |
| Laravel 12 to 13 | Laravel 13.25.0 | 12.5.33 | 43 tests / 125 assertions passed |

The final lock file passes strict Composer validation, platform requirement
checks, and `composer audit --locked` with no known advisories.

## Isolated Foundation runtime

- PHP 8.4.24 x64 is isolated under `D:\School-DSS-Foundation` and does not
  replace the XAMPP PHP installation or modify the system `PATH`.
- Composer 2.10.2 is invoked with the isolated PHP runtime and isolated Composer
  home/cache directories.
- MySQL 8.4.11 is an official portable ZIP deployment bound to
  `127.0.0.1:33084`, with a dedicated data directory and database named
  `school_dss_foundation_test`.
- The MySQL instance is not installed as a Windows service and was shut down
  after verification. The legacy MariaDB listener on port 3306 was not used for
  migration compatibility testing.

## MySQL 8.4 verification

- All 25 existing application migrations ran successfully in one batch.
- The resulting test schema contained 28 tables using
  `utf8mb4_unicode_ci`.
- The full suite passed against MySQL: 43 tests / 125 assertions.
- The full suite also passed against its safe default, in-memory SQLite target:
  43 tests / 125 assertions.
- Test bootstrap rejects every other database target, including connection URL,
  read/write host, and Unix socket overrides. MySQL testing additionally needs
  an explicit opt-in and the exact Foundation host, port, and database name.

No SQL dump or legacy data was restored into the compatibility database.

## Legacy and Blade verification

- All 14 entries in `legacy-assets.sha256` still match: three SQL backups and
  eleven uploaded project documents.
- No SQL backup, upload, `.env`, dependency directory, runtime cache, or log is
  tracked by Git.
- Blade source files were unchanged from the protected baseline.
- Blade caching completed successfully (101 compiled files).
- The application still exposes the same 45 non-vendor routes, plus Sanctum's
  vendor CSRF-cookie route.
- All 175 tracked PHP files pass syntax linting on PHP 8.4.24.

## Deferred operational work

- XAMPP Apache and the global CLI still use PHP 8.1.25. They must be moved to a
  supported PHP 8.3+ runtime before the upgraded application is served outside
  the isolated Foundation CLI.
- The temporary loopback-only MySQL compatibility instance was initialized for
  this test run. Create a least-privilege user with secret-managed credentials
  before using it as a persistent shared staging service.
- Laravel 13 session JSON serialization is intentionally deferred because
  enabling it would invalidate existing sessions and requires a maintenance
  window.
- The existing pnpm workspace placeholder and missing pnpm CLI remain untouched;
  resolve them before frontend dependency work in Phase 2.
