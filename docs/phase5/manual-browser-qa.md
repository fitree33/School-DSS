# Disposable Phase 5 browser QA

Completion record, 2026-09-10: the user confirmed that Manual Browser QA Phase 5 passed completely. This runbook preserves the scenarios and reproduction steps; the agent's subsequent automated closeout is recorded in [FINAL-REPORT.md](FINAL-REPORT.md). The manual result is user-reported, separate from automated readiness checks.

The local QA app is served at <http://127.0.0.1:8015/app/imports>. Its database,
private originals, sessions, cache, logs, generated account password and provider
callback secret live only in the ignored `.foundation-runtime/phase5-qa/` folder.
The normal application `.env` and database are not used. The router also prevents
`/index.php` from bootstrapping the normal application, and ignores the normal
Vite hot file in favor of the production build.

This environment uses real Poppler PDF inspection/text extraction, a database
queue, a separate PHP worker and a deterministic local provider on loopback port
8016. Provider callbacks use the application's HMAC signature boundary. It does
not certify an external n8n workflow, AI model output or OCR; scanned documents
without a text layer are expected to fail.

## Start, inspect and stop

Run these PowerShell commands from the repository root. Use PHP 8.3 or later;
the default XAMPP PHP 8.1 installation is unsuitable.

```powershell
# First preparation only; refuses to overwrite an existing QA database.
./scripts/phase5/qa-environment.ps1 start -Setup

# Subsequent start, including after implementation or production build changes.
& 'D:\School-DSS-Foundation\php-8.4.24\php.exe' ./scripts/phase5/qa.php fixtures
./scripts/phase5/qa-environment.ps1 start

./scripts/phase5/qa-environment.ps1 status
./scripts/phase5/qa-environment.ps1 stop
```

The default PHP is `D:\School-DSS-Foundation\php-8.4.24\php.exe`. Override it with
`-Php` when needed; `-Node` overrides Node and `-PopplerBin` supplies the directory
containing real `pdfinfo.exe` and `pdftotext.exe` during setup. The default Poppler
directory is the downloaded 26.07.0 distribution under
`.foundation-runtime/phase5-tools/`. Setup validates the generated text PDF using
the application's actual Poppler extractor and records the result in
`setup-verification.json`.

A successful frontend production build must exist before start. All three
processes launch with hidden windows. `processes.json` records their PID,
executable and creation time; stop checks that identity before terminating a
process. Occupied ports and existing managed processes cause start to stop with
an error. Failed launch stops only processes started by that invocation. Stop
preserves the disposable data and logs for review. There is no reset command.
Restart the worker after changing backend implementation code.

Read `.foundation-runtime/phase5-qa/credentials.txt` locally for credentials.
The scripts never print passwords or callback secrets. Do not add runtime files
to Git. Use a separate browser profile/session when switching between roles.

## Fixtures and roles

| Fixture in `.foundation-runtime/phase5-qa/fixtures/` | Expected behavior |
| --- | --- |
| `text-project.pdf` | One page with selectable text; signed success callback after about four seconds |
| `slow-project.pdf` | About twenty seconds in processing, allowing polling observation |
| `retry-once.pdf` | First callback fails; Retry creates another attempt and then succeeds |
| `locked-year-project.pdf` | Normal signed extraction succeeds; the local `locked-preview` command then appends a reviewer draft for locked fiscal year 2569 and its plan |
| `no-text-layer.pdf` | Real PDF with no extractable text; fails before calling the provider |
| `not-a-pdf.txt` | Upload validation rejects the file |

| Account label in the credentials file | Intended check |
| --- | --- |
| teacher | Upload and manage own imports in Academic Affairs |
| department-head | View and manage Academic Affairs imports |
| director | View and manage all imports; choose either department |
| other-teacher | Separate Student Affairs ownership/visibility |
| viewer | View all imports, with no create/review/confirm/project permissions |
| no-access | No import permissions or navigation access |
| inactive | Login/access blocked by active-user policy |

Selectors contain two departments, two categories, academic years 2570 and 2569,
and fiscal years 2570 (active/open), 2571 (open) and 2569 (locked). Each fiscal
year has its own school plan, allowing year changes and dependent plan selection
to be checked. All provider fixtures supply allowed extraction fields only;
callbacks cannot supply master-data identifiers. Reviewers make those selections
themselves. The `fixtures` command adds the locked-year PDF without reseeding or
changing existing data.

To prepare the locked-year scenario, upload `locked-year-project.pdf` as teacher
and wait for `needs_review`. Copy its public ID from the Imports detail URL, then
run the following local command (replace the placeholder with that ID):

```powershell
& 'D:\School-DSS-Foundation\php-8.4.24\php.exe' ./scripts/phase5/qa.php locked-preview 'paste-import-public-id-here'
```

This command is restricted to the disposable QA SQLite runtime and the unchanged
fixture uploaded by its active teacher account. It appends an audited user
revision through the normal review service, with valid master selections and
locked fiscal year 2569. It creates no Project. The printed preview URL is ready
for browser review. Repeating the command while that prepared revision is still
current returns the same revision; a changed or confirmed import is refused.

## Browser review sequence

1. Sign in as teacher. Open Imports, upload `slow-project.pdf`, and observe the
   list/detail processing state change through polling. Open the original PDF.
2. Review extracted fields, confidence, warnings and missing-field validation.
   Choose the correct department/category/academic year/fiscal year/plan. Change
   fiscal year and verify the selected plan remains valid for that year.
3. Verify locked fiscal year 2569 is disabled in the selector. Upload
   `locked-year-project.pdf`, wait for extraction, run `locked-preview` as above,
   and reload its saved preview to check the locked-year warning and blocked
   confirmation. Draft corrections remain available to a
   reviewer; choose an open year and valid plan, then save to allow confirmation.
   A viewer can read the same preview/history but cannot edit or confirm it.
4. Open the same import in two tabs. Save an edit in the first tab, then save
   from the older second tab. The stale request must return 409 and show the
   conflict without silently overwriting the newer revision. Reload and inspect
   revision history. Revision display numbers and revision row identifiers must
   remain distinct, especially after more than one import exists.
5. Open the confirm dialog, cancel once, then confirm the current saved preview.
   Follow the resulting Project link. Refresh/back navigation must preserve the
   confirmed state, and confirmation retries must reuse the same Project.
   A confirmed import must not accept another preview revision.
6. Upload `retry-once.pdf`; inspect failure information and Retry. Verify the
   second extraction succeeds. Check `no-text-layer.pdf` and `not-a-pdf.txt` too.
7. Switch roles to check own/department/all/read-only visibility and abilities.
   In particular, viewer must load master-data labels through the Imports options
   endpoint despite having no Project permissions, and must have no
   upload/save/retry/confirm controls.
8. Open the resulting project's legacy document workflow. Confirm the imported
   original is protected from file replacement/removal; attach and edit an
   ordinary legacy document to verify the normal workflow still works.

Browser QA is a separate manual activity. Environment setup/readiness checks
are not evidence that this sequence has been completed.

## Evidence

`app.stdout.log`, `app.stderr.log`, `worker.stdout.log`, `worker.stderr.log`,
`provider.stdout.log`, `provider.stderr.log` and `storage/logs/laravel.log` remain
under the runtime. `provider-events.jsonl` records run IDs, attempt numbers and
callback HTTP statuses without callback secrets or document bodies. `qa.php
status` reports the isolated database/storage paths and fixture counts without
credentials. Record browser findings and screenshots outside tracked source
files unless they have been reviewed for credentials and document content.
