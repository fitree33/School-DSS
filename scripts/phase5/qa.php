<?php

declare(strict_types=1);

use App\Contracts\Imports\PdfTextExtractor;
use App\Enums\AiExtractionRunStatus;
use App\Enums\DocumentImportStatus;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\DocumentImport;
use App\Models\FiscalYear;
use App\Models\ImportPreviewRevision;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\Role;
use App\Models\SchoolPlan;
use App\Models\User;
use App\Services\Imports\ImportPreviewService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/bootstrap.php';
$workspace = dirname(__DIR__, 2);
$runtime = $workspace.'/.foundation-runtime/phase5-qa';
$mode = $argv[1] ?? 'status';

if ($mode === 'setup') {
    if (is_file($runtime.'/database.sqlite')) {
        throw new RuntimeException('Existing Phase 5 QA database is preserved. Choose a new runtime before reseeding.');
    }
    $pdfinfo = $argv[2] ?? '';
    $pdftotext = $argv[3] ?? '';
    if (! is_file($pdfinfo) || ! is_file($pdftotext)) {
        throw new RuntimeException('Provide the absolute real Poppler pdfinfo.exe and pdftotext.exe paths.');
    }
    if (! is_dir($runtime)) {
        mkdir($runtime, 0700, true);
    }
    file_put_contents($runtime.'/settings.json', json_encode([
        'app_key' => 'base64:'.base64_encode(random_bytes(32)),
        'callback_secret' => bin2hex(random_bytes(32)),
        'pdfinfo' => realpath($pdfinfo),
        'pdftotext' => realpath($pdftotext),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    touch($runtime.'/database.sqlite');
}
if (! is_file($runtime.'/database.sqlite') || ! is_file($runtime.'/settings.json')) {
    throw new RuntimeException('Run setup before starting Phase 5 QA.');
}
$app = phaseFiveBootstrap($runtime);

if ($mode === 'worker') {
    exit($app->make(Kernel::class)->handle(new ArrayInput([
        'command' => 'queue:work', '--queue' => 'document-imports', '--sleep' => 1,
        '--tries' => 1, '--timeout' => 120, '--no-interaction' => true,
    ]), new ConsoleOutput));
}

if ($mode === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    app(ProjectStatusSeeder::class)->run();
    app(AuthorizationSeeder::class)->run();
    $department = Department::create(['name' => 'Phase 5 QA Academic Affairs']);
    $otherDepartment = Department::create(['name' => 'Phase 5 QA Student Affairs']);
    ProjectCategory::create(['name' => 'Phase 5 QA Development']);
    ProjectCategory::create(['name' => 'Phase 5 QA Student Support']);
    AcademicYear::create(['year' => 2570, 'is_active' => true]);
    AcademicYear::create(['year' => 2569, 'is_active' => false, 'is_locked' => true]);
    $year = FiscalYear::create(['year' => 2570, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'is_active' => true, 'is_locked' => false]);
    SchoolPlan::create(['fiscal_year_id' => $year->id, 'code' => 'P5-QA', 'name' => 'Phase 5 QA School Plan', 'is_active' => true]);
    $lockedYear = FiscalYear::create(['year' => 2569, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'is_active' => false, 'is_locked' => true]);
    $nextYear = FiscalYear::create(['year' => 2571, 'start_date' => '2027-10-01', 'end_date' => '2028-09-30', 'is_active' => false, 'is_locked' => false]);
    SchoolPlan::create(['fiscal_year_id' => $lockedYear->id, 'code' => 'P5-LOCKED', 'name' => 'Phase 5 QA Locked Year Plan', 'is_active' => true]);
    SchoolPlan::create(['fiscal_year_id' => $nextYear->id, 'code' => 'P5-NEXT', 'name' => 'Phase 5 QA Next Year Plan', 'is_active' => true]);
    $viewer = Role::create(['name' => 'Phase 5 QA Read Only', 'code' => 'phase5_qa_viewer']);
    $viewer->permissions()->attach(Permission::where('code', 'imports.view_all')->value('id'));
    Role::create(['name' => 'Phase 5 QA No Permissions', 'code' => 'phase5_qa_no_access']);
    $password = 'P5!'.bin2hex(random_bytes(12));
    $accounts = [
        ['teacher', 'teacher', $department],
        ['department-head', 'department_head', $department],
        ['director', 'director', $department],
        ['other-teacher', 'teacher', $otherDepartment],
        ['viewer', 'phase5_qa_viewer', $otherDepartment],
        ['no-access', 'phase5_qa_no_access', $otherDepartment],
        ['inactive', 'teacher', $otherDepartment],
    ];
    $credentials = "Disposable Phase 5 QA only — http://127.0.0.1:8015/app/imports\nPassword (all accounts): ".$password."\n\n";
    foreach ($accounts as [$label, $role, $userDepartment]) {
        $email = $label.'@phase5-qa.local';
        User::create(['name' => 'Phase 5 QA '.$label, 'email' => $email, 'password' => Hash::make($password), 'role_id' => Role::where('code', $role)->value('id'), 'department_id' => $userDepartment->id, 'is_active' => $label !== 'inactive', 'email_verified_at' => now()]);
        $credentials .= $label.': '.$email."\n";
    }
    file_put_contents($runtime.'/credentials.txt', $credentials);
    mkdir($runtime.'/fixtures', 0700, true);
    foreach ([
        'text-project.pdf' => ['QA_SUCCESS', 'Phase 5 QA project', 'Objective: Verify text layer import and explicit confirmation.', 'Budget: 1500.00', 'Dates: 2026-10-01 through 2026-10-31', 'KPI: Completion 100 percent'],
        'retry-once.pdf' => ['QA_RETRY_ONCE', 'Phase 5 QA retry fixture', 'The first extraction attempt fails; retry succeeds.', 'Budget: 1500.00'],
        'slow-project.pdf' => ['QA_SLOW', 'Phase 5 QA polling fixture', 'The local provider waits 20 seconds before its signed callback.'],
        'no-text-layer.pdf' => [],
    ] as $filename => $lines) {
        file_put_contents($runtime.'/fixtures/'.$filename, phaseFiveFixturePdf($lines));
    }
    file_put_contents($runtime.'/fixtures/not-a-pdf.txt', 'This upload must be rejected.');
    phaseFivePrepareLockedFixture($runtime);
    $extraction = app(PdfTextExtractor::class)->extract($runtime.'/fixtures/text-project.pdf');
    if ($extraction->pageCount !== 1 || ! str_contains($extraction->text, 'QA_SUCCESS')) {
        throw new RuntimeException('Real Poppler could not extract the generated QA fixture.');
    }
    file_put_contents($runtime.'/setup-verification.json', json_encode([
        'real_poppler' => true, 'pages' => $extraction->pageCount,
        'text_sha256' => $extraction->textSha256, 'verified_at' => now()->toIso8601String(),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Disposable Phase 5 QA prepared. Credentials and sample PDFs are under .foundation-runtime/phase5-qa/.\n";
    exit(0);
}

if ($mode === 'fixtures') {
    phaseFivePrepareLockedFixture($runtime);
    echo "Locked-year PDF prepared; existing QA data is preserved. Use locked-preview after its extraction succeeds.\n";
    exit(0);
}

if ($mode === 'locked-preview') {
    $publicId = $argv[2] ?? '';
    if (count($argv) !== 3 || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $publicId) !== 1) {
        throw new RuntimeException('Usage: qa.php locked-preview <import-public-id>');
    }
    phaseFivePrepareLockedPreview($runtime, $publicId);
    exit(0);
}

if ($mode !== 'status') {
    throw new RuntimeException('Expected setup, fixtures, locked-preview, worker, or status.');
}
echo json_encode([
    'database' => config('database.connections.sqlite.database'),
    'storage' => storage_path(),
    'queue' => config('queue.default'),
    'cache' => config('cache.default'),
    'session_cookie' => config('session.cookie'),
    'fiscal_years' => FiscalYear::orderBy('year')->get(['year', 'is_active', 'is_locked'])->toArray(),
    'users' => User::count(),
    'imports' => DocumentImport::count(),
    'projects' => Project::count(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

/** The provider extracts allowed fields; locked master data is prepared by a local reviewer command. */
function phaseFivePrepareLockedFixture(string $runtime): void
{
    file_put_contents($runtime.'/fixtures/locked-year-project.pdf', phaseFiveFixturePdf([
        'QA_LOCKED_YEAR', 'Phase 5 QA locked fiscal year fixture',
        'After extraction, use the local locked-preview command to prepare the review scenario.',
    ]));
}

/** Append a guarded QA teacher draft through the production review service; never confirm an import. */
function phaseFivePrepareLockedPreview(string $runtime, string $publicId): void
{
    if (PHP_SAPI !== 'cli' || config('database.default') !== 'sqlite'
        || realpath((string) config('database.connections.sqlite.database')) !== realpath($runtime.'/database.sqlite')
        || realpath(storage_path()) !== realpath($runtime.'/storage')) {
        throw new RuntimeException('Locked-preview preparation is limited to the disposable Phase 5 QA SQLite runtime.');
    }
    $fixture = $runtime.'/fixtures/locked-year-project.pdf';
    if (! is_file($fixture)) {
        throw new RuntimeException('Run qa.php fixtures before uploading locked-year-project.pdf.');
    }
    $result = DB::transaction(function () use ($publicId, $fixture): array {
        $actor = User::where('email', 'teacher@phase5-qa.local')->first();
        if ($actor === null || $actor->name !== 'Phase 5 QA teacher' || ! $actor->is_active
            || $actor->role?->code !== 'teacher' || $actor->department?->name !== 'Phase 5 QA Academic Affairs') {
            throw new RuntimeException('The active disposable QA teacher and Academic Affairs department are required.');
        }
        $documentImport = DocumentImport::where('public_id', $publicId)->lockForUpdate()->first();
        if ($documentImport === null || (int) $documentImport->uploaded_by !== (int) $actor->id
            || (int) $documentImport->uploader_department_id !== (int) $actor->department_id
            || $documentImport->original_name !== 'locked-year-project.pdf'
            || ! hash_equals(hash_file('sha256', $fixture), $documentImport->sha256)) {
            throw new RuntimeException('Choose the unchanged locked-year-project.pdf fixture uploaded by the disposable QA teacher.');
        }
        if ($documentImport->status !== DocumentImportStatus::NeedsReview || $documentImport->confirmed_project_id !== null) {
            throw new RuntimeException('The locked-year fixture must finish extraction and remain in needs_review before preparation.');
        }
        $base = $documentImport->previewRevisions()->where('source', ImportPreviewRevision::SOURCE_AI)->first();
        $run = $base?->sourceExtractionRun;
        if ($base === null || $run?->status !== AiExtractionRunStatus::Succeeded
            || $run->model_name !== 'local-qa-fixture'
            || $run->attempt_no !== $documentImport->active_extraction_attempt
            || ! str_contains((string) $run->extracted_text, 'QA_LOCKED_YEAR')) {
            throw new RuntimeException('A successful local provider extraction of the locked-year fixture is required.');
        }
        $year = FiscalYear::where('year', 2569)->where('is_locked', true)->first();
        $plan = SchoolPlan::where('code', 'P5-LOCKED')->where('fiscal_year_id', $year?->id)->where('is_active', true)->first();
        $category = ProjectCategory::where('name', 'Phase 5 QA Development')->first();
        $academicYear = AcademicYear::where('year', 2570)->where('is_active', true)->first();
        if ($year === null || $plan === null || $category === null || $academicYear === null) {
            throw new RuntimeException('The original locked year, its QA plan, active academic year and QA category are required.');
        }
        $payload = array_replace($base->payload, [
            'department_id' => (int) $actor->department_id,
            'project_category_id' => (int) $category->id,
            'academic_year_id' => (int) $academicYear->id,
            'fiscal_year_id' => (int) $year->id,
            'school_plan_id' => (int) $plan->id,
        ]);
        $key = 'phase5-qa-locked-preview-v1';
        $existing = $documentImport->previewRevisions()->where('client_idempotency_key', $key)->first();
        $expectedCurrent = $existing ?? $base;
        if ($documentImport->current_preview_revision !== $expectedCurrent->revision_no
            || ($existing !== null && ($existing->source !== ImportPreviewRevision::SOURCE_USER
                || (int) $existing->edited_by !== (int) $actor->id))) {
            throw new RuntimeException('This fixture has other reviewer changes; preparation will not overwrite or supersede them.');
        }
        $projectsBefore = Project::withTrashed()->count();
        auth()->setUser($actor);
        $revision = app(ImportPreviewService::class)->appendUserRevision($actor, $documentImport, $base->id, $payload, $key);
        if (($revision->revision->validation_errors ?? []) !== [
            'fiscal_year_id' => ['The selected fiscal year is locked and read-only.'],
        ] || Project::withTrashed()->count() !== $projectsBefore) {
            throw new RuntimeException('Locked-preview preparation failed validation; the draft transaction has been rolled back.');
        }

        return [
            'url' => 'http://127.0.0.1:8015/app/imports/'.$documentImport->public_id.'/preview',
            'created' => $revision->created,
            'revision_id' => $revision->revision->id,
            'revision_no' => $revision->revision->revision_no,
            'actor' => 'teacher',
            'validation_error_fields' => array_keys($revision->revision->validation_errors),
            'canonical_projects_created' => 0,
        ];
    });
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
}

/** A small standards-based fixture with a real selectable text layer; no OCR. */
function phaseFiveFixturePdf(array $lines): string
{
    $stream = "BT /F1 13 Tf 50 750 Td 20 TL\n";
    foreach ($lines as $line) {
        $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).") Tj T*\n";
    }
    $stream .= "ET\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}
