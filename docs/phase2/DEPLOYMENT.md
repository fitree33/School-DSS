# Phase 2 deployment prerequisites

Phase 2 keeps the legacy approval workflow and introduces separate V2 execution and evaluation statuses.

Run deployment against a verified backup in a maintenance window:

1. `php artisan down`
2. `php artisan migrate --force`
3. `php artisan db:seed --force`
4. Verify that `draft`, `not_started`, and `pending` lookup codes exist.
5. Verify that no migrated project has a null execution or evaluation status.
6. Provision departments, categories, academic years, fiscal years, and school plans from approved master data.
7. `php artisan up`

Do not run the backfill while legacy project writes are active. SQL backups and stored project documents must remain outside Git and must not be deleted or reconciled during this deployment.
