# School-DSS Foundation Baseline

This directory records the non-destructive baseline used for the School-DSS V2
foundation upgrade. It intentionally contains metadata only; database dumps,
uploaded documents, environment files, dependencies, and runtime data remain
outside Git.

Baseline observations on 2026-08-17:

- Application: Laravel 10.50.2 on the pre-upgrade lock file.
- Host runtime: PHP 8.1.25 and Composer 2.7.7.
- Legacy regression suite: 43 tests, 125 assertions, all passing.
- Test database: SQLite in-memory; the legacy database was not contacted.
- Legacy database artifacts identify MariaDB 10.4.32.
- MySQL 8.4 compatibility requires a separate isolated test environment.
- Legacy assets inventoried: 3 SQL backups and 11 project documents.
- No SQL backup, upload, `.env`, dependency tree, or runtime log is tracked.

`legacy-assets.sha256` is the integrity manifest for the ignored legacy assets.
The files themselves must be preserved separately and must not be deleted or
replaced during the foundation upgrade.
