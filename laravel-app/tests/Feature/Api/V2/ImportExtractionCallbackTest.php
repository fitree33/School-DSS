<?php

namespace Tests\Feature\Api\V2;

use App\Enums\AiExtractionRunStatus;
use App\Enums\DocumentImportProcessingStage;
use App\Enums\DocumentImportStatus;
use App\Http\Middleware\VerifyImportCallbackSignature;
use App\Jobs\ProcessDocumentImport;
use App\Models\AiExtractionRun;
use App\Models\Department;
use App\Models\DocumentImport;
use App\Models\ImportPreviewRevision;
use App\Models\User;
use App\Services\Imports\DocumentImportProcessor;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ImportExtractionCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const URI_PREFIX = '/api/v2/import-extraction-runs';

    private const CURRENT_SECRET = 'phase-five-current-callback-secret';

    private const PREVIOUS_SECRET = 'phase-five-previous-callback-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('project_imports.callback.current_secret', self::CURRENT_SECRET);
        config()->set('project_imports.callback.previous_secret', self::PREVIOUS_SECRET);
        config()->set('project_imports.callback.max_clock_skew_seconds', 300);
        config()->set('project_imports.callback.max_body_bytes', 1024 * 1024);
        config()->set('project_imports.callback.max_requests_per_minute', 60);

        $this->app->bind(ProjectService::class, function (): never {
            throw new \LogicException('Callbacks must not resolve ProjectService.');
        });
        DB::listen(function ($query): void {
            if (preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+["`\[]?(projects|project_kpis|project_documents|document_contents)["`\]\s]/i', $query->sql)) {
                throw new \LogicException('Callbacks must not write canonical project tables.');
            }
        });

        $this->travelTo(now()->startOfSecond());

    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_signed_success_appends_an_ai_preview_without_canonical_mutation(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $callback = $this->successCallback([
            'activities' => [['name' => 'Must not become canonical']],
            'sub_activities' => [['name' => 'Must also be ignored']],
        ]);
        $body = $this->encodeJson($callback);

        $response = $this->signedCallback($run, $body, 'evt-success-1');

        $response
            ->assertOk()
            ->assertJsonPath('data.run.status', 'succeeded')
            ->assertJsonPath('data.document_import.status', 'needs_review')
            ->assertJsonPath('data.preview_revision.revision_no', 1)
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.stale', false);

        $documentImport->refresh();
        $run->refresh();
        $revision = ImportPreviewRevision::query()->sole();

        $this->assertSame(DocumentImportStatus::NeedsReview, $documentImport->status);
        $this->assertSame(1, $documentImport->current_preview_revision);
        $this->assertSame(AiExtractionRunStatus::Succeeded, $run->status);
        $this->assertSame('evt-success-1', $run->provider_event_id);
        $this->assertSame(hash('sha256', $body), $run->callback_digest);
        $this->assertSame(ImportPreviewRevision::SOURCE_AI, $revision->source);
        $this->assertNull($revision->payload['department_id']);
        $this->assertNull($revision->payload['fiscal_year_id']);
        $this->assertArrayNotHasKey('activities', $revision->payload);
        $this->assertArrayNotHasKey('sub_activities', $revision->payload);
        $this->assertSame(
            ['activities', 'sub_activities'],
            collect($revision->warnings)
                ->where('code', 'unsupported_scope_ignored')
                ->pluck('field')
                ->values()
                ->all(),
        );
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_kpis', 0);
        $this->assertDatabaseCount('project_documents', 0);
        $this->assertDatabaseCount('document_contents', 0);

        foreach (['activities', 'sub_activities', 'project_activities', 'project_sub_activities'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_provider_raw_result_is_opaque_and_cannot_replace_verified_text_or_create_documents(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $verifiedText = 'Original PDF text verified by the local extractor.';
        $run->update([
            'extracted_text' => $verifiedText,
            'extracted_text_sha256' => hash('sha256', $verifiedText),
        ]);
        $callback = $this->successCallback();
        $callback['raw_result'] = [
            'project' => ['id' => 999, 'name' => 'Untrusted canonical project'],
            'project_kpis' => [['id' => 999, 'name' => 'Untrusted canonical KPI']],
            'project_document' => ['source_import_id' => $documentImport->id, 'path' => 'replacement.pdf'],
            'document_content' => ['document_id' => 999, 'extracted_text' => 'Untrusted provider text'],
            'extracted_text' => 'Untrusted provider replacement text',
        ];
        $body = $this->encodeJson($callback);

        $this->signedCallback($run, $body, 'evt-opaque-raw-result')->assertOk();
        $this->signedCallback($run, $body, 'evt-opaque-raw-result')
            ->assertOk()->assertJsonPath('data.replayed', true);

        $run->refresh();
        // Opaque JSON retains its values and list order, independent of object key order.
        $this->assertJsonStringEqualsJsonString(
            json_encode($callback['raw_result'], JSON_THROW_ON_ERROR),
            json_encode($run->raw_result, JSON_THROW_ON_ERROR),
        );
        $this->assertSame($verifiedText, $run->extracted_text);
        $this->assertSame(hash('sha256', $verifiedText), $run->extracted_text_sha256);
        $revision = ImportPreviewRevision::query()->sole();
        $this->assertSame('AI imported project', $revision->payload['name']);
        $this->assertArrayNotHasKey('document_content', $revision->payload);

        foreach (['projects', 'project_kpis', 'project_documents', 'document_contents', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_nested_canonical_kpi_identifiers_are_rejected_without_consuming_the_event(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $callback = $this->successCallback();
        $callback['payload']['indicators'][0]['id'] = 999;
        $callback['payload']['indicators'][0]['project_id'] = 123;

        $this->signedCallback($run, $this->encodeJson($callback), 'evt-nested-authority')
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_callback_payload');
        $this->assertUnchangedProcessingState($documentImport, $run);

        $this->signedCallback($run, $this->encodeJson($this->successCallback()), 'evt-nested-authority')
            ->assertOk()->assertJsonPath('data.replayed', false);
        $this->assertDatabaseCount('import_preview_revisions', 1);
        $this->assertDatabaseCount('project_kpis', 0);
    }

    public function test_signature_is_bound_to_the_exact_raw_body(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $originalBody = $this->encodeJson($this->successCallback());
        $tamperedBody = str_replace('1500.00', '1500.01', $originalBody);
        $timestamp = (string) now()->timestamp;
        $signature = $this->callbackSignature($run, $originalBody, $timestamp, 'evt-tampered');

        $response = $this->rawCallback(
            $run,
            $tamperedBody,
            'evt-tampered',
            $timestamp,
            $signature,
        );

        $response
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_callback_signature');
        $this->assertUnchangedProcessingState($documentImport, $run);
    }

    public function test_missing_or_malformed_signature_headers_are_rejected(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback());

        $response = $this->call(
            'POST',
            $this->uri($run),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $response
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_callback_signature');
        $this->assertUnchangedProcessingState($documentImport, $run);
    }

    public function test_expired_timestamp_is_rejected_before_state_changes(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback());
        $timestamp = (string) now()->subSeconds(301)->timestamp;

        $response = $this->rawCallback(
            $run,
            $body,
            'evt-expired',
            $timestamp,
            $this->callbackSignature($run, $body, $timestamp, 'evt-expired'),
        );

        $response
            ->assertUnauthorized()
            ->assertJsonPath('code', 'expired_callback');
        $this->assertUnchangedProcessingState($documentImport, $run);
    }

    public function test_callback_body_size_is_bounded_before_processing(): void
    {
        config()->set('project_imports.callback.max_body_bytes', 64);
        [$documentImport, $run] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback(['key_points' => str_repeat('x', 128)]));

        $response = $this->signedCallback($run, $body, 'evt-too-large');

        $response
            ->assertStatus(Response::HTTP_REQUEST_ENTITY_TOO_LARGE)
            ->assertJsonPath('code', 'callback_too_large');
        $this->assertUnchangedProcessingState($documentImport, $run);
    }

    public function test_previous_rotation_secret_is_accepted(): void
    {
        [, $run] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback());

        $this->signedCallback($run, $body, 'evt-previous-secret', self::PREVIOUS_SECRET)
            ->assertOk()
            ->assertJsonPath('data.run.status', 'succeeded');
    }

    public function test_identical_event_and_digest_are_idempotent_but_changed_content_conflicts(): void
    {
        [, $run] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback());

        $this->signedCallback($run, $body, 'evt-replay')->assertOk();
        $this->signedCallback($run, $body, 'evt-replay')
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.preview_revision.revision_no', 1);

        $changedBody = $this->encodeJson($this->successCallback(['budget' => '1600.00']));
        $this->signedCallback($run, $changedBody, 'evt-replay')
            ->assertConflict()
            ->assertJsonPath('code', 'callback_replay_conflict');

        $this->assertDatabaseCount('import_preview_revisions', 1);
        $this->assertSame('1500.00', ImportPreviewRevision::query()->sole()->payload['budget']);
    }

    public function test_event_identifier_cannot_be_reused_for_another_run(): void
    {
        [, $firstRun] = $this->processingRun();
        [$secondImport, $secondRun] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback());

        $this->signedCallback($firstRun, $body, 'evt-one-owner')->assertOk();
        $this->signedCallback($secondRun, $body, 'evt-one-owner')
            ->assertConflict()
            ->assertJsonPath('code', 'callback_event_reused');

        $this->assertUnchangedProcessingState($secondImport, $secondRun, 1);
        $this->assertDatabaseCount('import_preview_revisions', 1);
    }

    public function test_production_route_has_callback_middleware_and_no_model_binding(): void
    {
        $route = Route::getRoutes()->getByName('api.v2.import-extraction-runs.callback');
        $this->assertNotNull($route);
        $this->assertSame('api/v2/import-extraction-runs/{run}/callback', $route->uri());
        $this->assertContains(VerifyImportCallbackSignature::class, $route->gatherMiddleware());
        $this->assertContains('throttle:import-callback', $route->gatherMiddleware());
        $this->assertContains(SubstituteBindings::class, $route->excludedMiddleware());
    }

    public function test_callback_throttle_counts_rejected_signatures(): void
    {
        config()->set('project_imports.callback.max_requests_per_minute', 2);
        [$documentImport, $run] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback());
        foreach ([1, 2] as $attempt) {
            $this->rawCallback($run, $body, 'evt-rate-'.$attempt, (string) now()->timestamp, str_repeat('0', 64))
                ->assertUnauthorized();
        }
        $this->signedCallback($run, $body, 'evt-rate-3')
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
        $this->assertUnchangedProcessingState($documentImport, $run);
    }

    public function test_signature_cannot_be_moved_to_another_run_or_event(): void
    {
        [, $firstRun] = $this->processingRun();
        [$documentImport, $run] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback());
        $timestamp = (string) now()->timestamp;
        $this->rawCallback($run, $body, 'evt-bound', $timestamp,
            $this->callbackSignature($firstRun, $body, $timestamp, 'evt-bound'))
            ->assertUnauthorized();
        $this->rawCallback($run, $body, 'evt-changed', $timestamp,
            $this->callbackSignature($run, $body, $timestamp, 'evt-bound'))
            ->assertUnauthorized();
        $this->assertUnchangedProcessingState($documentImport, $run);
    }

    public function test_unknown_run_is_hidden_until_signature_is_verified(): void
    {
        $run = new AiExtractionRun(['public_id' => (string) Str::uuid()]);
        $body = $this->encodeJson($this->successCallback());
        $this->postJson($this->uri($run), [])->assertUnauthorized();
        $this->signedCallback($run, $body, 'evt-unknown')->assertNotFound();
    }

    public function test_malformed_future_and_unconfigured_signatures_fail_closed(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback());
        $timestamp = (string) now()->timestamp;
        foreach (['', 'invalid', str_repeat('z', 64)] as $signature) {
            $this->rawCallback($run, $body, 'evt-malformed', $timestamp, $signature)->assertUnauthorized();
        }
        $future = (string) now()->addSeconds(301)->timestamp;
        $this->rawCallback($run, $body, 'evt-future', $future,
            $this->callbackSignature($run, $body, $future, 'evt-future'))
            ->assertUnauthorized()->assertJsonPath('code', 'expired_callback');
        config()->set('project_imports.callback.current_secret', null);
        config()->set('project_imports.callback.previous_secret', null);
        $this->signedCallback($run, $body, 'evt-no-secret')->assertStatus(503);
        $this->assertUnchangedProcessingState($documentImport, $run);
    }

    public function test_replay_conflict_precedes_validation_and_same_digest_does_not_write(): void
    {
        [, $run] = $this->processingRun();
        $body = $this->encodeJson($this->successCallback());
        $this->signedCallback($run, $body, 'evt-replay-raw')->assertOk();
        $snapshots = DB::table('ai_extraction_runs')->get()->toJson();
        $this->travel(1)->seconds();
        $this->signedCallback($run, $body, 'evt-replay-raw')->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($snapshots, DB::table('ai_extraction_runs')->get()->toJson());
        $this->signedCallback($run, '{"invalid":', 'evt-replay-raw')
            ->assertConflict()->assertJsonPath('code', 'callback_replay_conflict');
        $this->assertDatabaseCount('import_preview_revisions', 1);
    }

    public function test_failed_run_after_retry_accepts_only_stale_receipt_and_preserves_active_state(): void
    {
        [$documentImport, $oldRun] = $this->processingRun();
        $oldRun->update(['status' => AiExtractionRunStatus::Failed, 'finished_at' => now(),
            'failure_code' => 'PROVIDER_TIMEOUT', 'failure_message' => 'Provider timed out.']);
        $newRun = AiExtractionRun::create(['document_import_id' => $documentImport->id, 'attempt_no' => 2,
            'provider' => 'n8n', 'schema_version' => 'project-import.v1', 'status' => AiExtractionRunStatus::Queued]);
        $documentImport->update(['active_extraction_attempt' => 2,
            'processing_stage' => DocumentImportProcessingStage::Validating]);
        $importSnapshot = $documentImport->fresh()->getRawOriginal();
        $newSnapshot = $newRun->fresh()->getRawOriginal();
        $this->travel(1)->seconds();
        $body = $this->encodeJson($this->successCallback());
        $this->signedCallback($oldRun, $body, 'evt-after-retry')->assertAccepted();
        $this->signedCallback($oldRun, $body, 'evt-after-retry')->assertAccepted()->assertJsonPath('data.replayed', true);
        $this->assertSame($importSnapshot, $documentImport->fresh()->getRawOriginal());
        $this->assertSame($newSnapshot, $newRun->fresh()->getRawOriginal());
        $this->assertSame('PROVIDER_TIMEOUT', $oldRun->fresh()->failure_code);
        $this->assertDatabaseCount('import_preview_revisions', 0);
    }

    public function test_callback_before_dispatch_cannot_complete_or_supersede_active_run(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $documentImport->update(['processing_stage' => DocumentImportProcessingStage::ExtractingText]);
        $this->signedCallback($run, $this->encodeJson($this->successCallback()), 'evt-premature')
            ->assertConflict()->assertJsonPath('code', 'callback_not_ready');
        $this->assertSame(AiExtractionRunStatus::Processing, $run->fresh()->status);
        $this->assertNull($run->fresh()->provider_event_id);
        $this->assertDatabaseCount('import_preview_revisions', 0);
    }

    public function test_unsigned_query_fields_cannot_complete_a_signed_empty_body(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $body = '{}';
        $timestamp = (string) now()->timestamp;
        $this->call('POST', $this->uri($run).'?status=failed&failure_code=INJECTED&failure_message=Injected',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_IMPORT_TIMESTAMP' => $timestamp,
                'HTTP_X_IMPORT_EVENT_ID' => 'evt-query',
                'HTTP_X_IMPORT_SIGNATURE' => $this->callbackSignature($run, $body, $timestamp, 'evt-query')],
            content: $body)->assertUnprocessable();
        $this->assertUnchangedProcessingState($documentImport, $run);
    }

    public function test_body_limit_checks_declared_and_actual_sizes_before_json_parsing(): void
    {
        [$documentImport, $run] = $this->processingRun();
        config()->set('project_imports.callback.max_body_bytes', 64);
        foreach ([[100, '{}'], [1, str_repeat('{', 65)]] as [$length, $body]) {
            $this->call('POST', $this->uri($run),
                server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => $length], content: $body)
                ->assertStatus(413)->assertJsonPath('code', 'callback_too_large');
        }
        $this->assertUnchangedProcessingState($documentImport, $run);
    }

    public function test_processing_run_cannot_regress_or_replace_verified_extraction_text(): void
    {
        [, $run] = $this->processingRun();
        $run->update(['extracted_text' => 'Verified PDF text', 'extracted_text_sha256' => hash('sha256', 'Verified PDF text')]);
        foreach ([['status' => AiExtractionRunStatus::Queued], ['extracted_text' => 'Replacement'],
            ['extracted_text_sha256' => hash('sha256', 'Replacement')]] as $change) {
            try {
                $run->fresh()->update($change);
                $this->fail('Invalid extraction mutation was accepted.');
            } catch (\LogicException) {
                $this->assertSame(AiExtractionRunStatus::Processing, $run->fresh()->status);
                $this->assertSame('Verified PDF text', $run->fresh()->extracted_text);
            }
        }
    }

    public function test_run_status_enum_round_trips_through_schema_and_terminal_statuses_are_locked(): void
    {
        [$documentImport] = $this->processingRun();
        foreach (AiExtractionRunStatus::cases() as $index => $status) {
            $run = AiExtractionRun::create(['document_import_id' => $documentImport->id, 'attempt_no' => $index + 2,
                'provider' => 'n8n', 'schema_version' => 'project-import.v1', 'status' => $status]);
            $this->assertSame($status, $run->fresh()->status);
            if ($status->isTerminal()) {
                try {
                    $run->update(['model_name' => 'Overwritten']);
                    $this->fail('Terminal extraction history was modified.');
                } catch (\LogicException) {
                    $this->assertNull($run->fresh()->model_name);
                }
            }
        }
        $this->assertFalse(AiExtractionRunStatus::Queued->canTransitionTo(AiExtractionRunStatus::Succeeded));
        $this->assertTrue(AiExtractionRunStatus::Failed->canTransitionTo(AiExtractionRunStatus::Superseded));
        $this->assertFalse(AiExtractionRunStatus::Succeeded->canTransitionTo(AiExtractionRunStatus::Superseded));
    }

    public function test_succeeded_extraction_text_hash_and_provenance_survive_callback_and_job_replays(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $text = 'Verified original text before the provider callback.';
        $run->update(['extracted_text' => $text, 'extracted_text_sha256' => hash('sha256', $text)]);
        $body = $this->encodeJson($this->successCallback());
        $this->signedCallback($run, $body, 'evt-immutable')->assertOk();
        $snapshot = $run->fresh()->getRawOriginal();
        $this->assertSame($text, $snapshot['extracted_text']);
        $this->assertSame(hash('sha256', $text), $snapshot['extracted_text_sha256']);

        foreach ([
            ['extracted_text' => 'Replacement'],
            ['extracted_text_sha256' => hash('sha256', 'Replacement')],
            ['extracted_text' => null, 'extracted_text_sha256' => null],
            ['normalized_result' => ['name' => 'Replacement']],
            ['model_name' => 'Replacement'],
            ['prompt_version' => 'Replacement'],
        ] as $change) {
            try {
                $run->fresh()->update($change);
                $this->fail('Succeeded extraction provenance was changed.');
            } catch (\LogicException) {
                $this->assertSame($snapshot, $run->fresh()->getRawOriginal());
            }
        }

        $this->travel(1)->seconds();
        $this->signedCallback($run, $body, 'evt-immutable')->assertOk()->assertJsonPath('data.replayed', true);
        $this->signedCallback($run, $body, 'evt-replacement')->assertConflict();
        (new ProcessDocumentImport($documentImport->id))->handle(app(DocumentImportProcessor::class));
        (new ProcessDocumentImport($documentImport->id))->failed(new \RuntimeException('Late failure'));
        $this->assertSame($snapshot, $run->fresh()->getRawOriginal());
        $this->assertSame(DocumentImportStatus::NeedsReview, $documentImport->fresh()->status);
        $this->assertDatabaseCount('import_preview_revisions', 1);
        foreach (['projects', 'project_kpis', 'project_documents', 'document_contents', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_old_attempt_callback_is_durably_superseded_without_mutating_the_import(): void
    {
        [$documentImport, $oldRun] = $this->processingRun();
        $newRun = AiExtractionRun::query()->create([
            'document_import_id' => $documentImport->id,
            'attempt_no' => 2,
            'provider' => 'n8n',
            'schema_version' => 'project-import.v1',
            'status' => AiExtractionRunStatus::Processing,
            'started_at' => now(),
        ]);
        $documentImport->update(['active_extraction_attempt' => 2]);
        $body = $this->encodeJson($this->successCallback());

        $this->signedCallback($oldRun, $body, 'evt-stale-attempt')
            ->assertAccepted()
            ->assertJsonPath('data.run.status', 'superseded')
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.stale', true);
        $this->signedCallback($oldRun, $body, 'evt-stale-attempt')
            ->assertAccepted()
            ->assertJsonPath('data.replayed', true);

        $documentImport->refresh();
        $oldRun->refresh();
        $newRun->refresh();

        $this->assertSame(DocumentImportStatus::Processing, $documentImport->status);
        $this->assertSame(2, $documentImport->active_extraction_attempt);
        $this->assertNull($documentImport->current_preview_revision);
        $this->assertSame(AiExtractionRunStatus::Superseded, $oldRun->status);
        $this->assertSame(AiExtractionRunStatus::Processing, $newRun->status);
        $this->assertDatabaseCount('import_preview_revisions', 0);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_callback_for_non_processing_import_is_superseded_without_status_regression(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $documentImport->update([
            'status' => DocumentImportStatus::Failed,
            'processing_stage' => null,
            'failure_stage' => 'processing',
            'failure_code' => 'LOCAL_FAILURE',
            'failure_message' => 'The local run already failed.',
        ]);

        $response = $this->signedCallback(
            $run,
            $this->encodeJson($this->successCallback()),
            'evt-stale-status',
        );

        $response
            ->assertAccepted()
            ->assertJsonPath('data.run.status', 'superseded')
            ->assertJsonPath('data.document_import.status', 'failed');
        $this->assertSame('LOCAL_FAILURE', $documentImport->fresh()->failure_code);
        $this->assertDatabaseCount('import_preview_revisions', 0);
    }

    public function test_fresh_failure_marks_only_the_run_and_import_failed(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $callback = [
            'status' => 'failed',
            'failure' => [
                'code' => 'AI_SCHEMA_INVALID',
                'message' => 'Provider output did not match the extraction schema.',
            ],
            'warnings' => ['No preview was produced.'],
            'raw_result' => ['provider_status' => 'failed'],
        ];

        $this->signedCallback($run, $this->encodeJson($callback), 'evt-failure')
            ->assertOk()
            ->assertJsonPath('data.run.status', 'failed')
            ->assertJsonPath('data.document_import.status', 'failed');

        $documentImport->refresh();
        $run->refresh();
        $this->assertSame(DocumentImportStatus::Failed, $documentImport->status);
        $this->assertSame(DocumentImportProcessingStage::WaitingForAi->value, $documentImport->failure_stage);
        $this->assertSame('AI_SCHEMA_INVALID', $documentImport->failure_code);
        $this->assertSame(AiExtractionRunStatus::Failed, $run->status);
        $this->assertDatabaseCount('import_preview_revisions', 0);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_callback_cannot_supply_canonical_ids_or_envelope_authority(): void
    {
        [$documentImport, $run] = $this->processingRun();
        $withPayloadAuthority = $this->successCallback(['department_id' => 999, 'project_id' => 123]);

        $this->signedCallback($run, $this->encodeJson($withPayloadAuthority), 'evt-forbidden-payload')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'callback_payload_forbidden_field');

        $withEnvelopeAuthority = $this->successCallback();
        $withEnvelopeAuthority['extraction_run_id'] = 'provider-controlled-run';

        $this->signedCallback($run, $this->encodeJson($withEnvelopeAuthority), 'evt-forbidden-envelope')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'callback_payload_forbidden_field');

        $this->assertUnchangedProcessingState($documentImport, $run);
        $this->assertDatabaseCount('projects', 0);
    }

    /**
     * @return array{DocumentImport, AiExtractionRun}
     */
    private function processingRun(): array
    {
        $department = Department::query()->create([
            'name' => 'Callback department '.fake()->unique()->numerify('########'),
        ]);
        $uploader = User::factory()->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $documentImport = DocumentImport::query()->create([
            'uploaded_by' => $uploader->id,
            'uploader_department_id' => $department->id,
            'status' => DocumentImportStatus::Processing,
            'processing_stage' => DocumentImportProcessingStage::WaitingForAi,
            'original_name' => 'project.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'imports/'.fake()->uuid().'/project.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'sha256' => hash('sha256', fake()->uuid()),
            'active_extraction_attempt' => 1,
        ]);
        $run = AiExtractionRun::query()->create([
            'document_import_id' => $documentImport->id,
            'attempt_no' => 1,
            'provider' => 'n8n',
            'schema_version' => 'project-import.v1',
            'status' => AiExtractionRunStatus::Processing,
            'started_at' => now(),
            'dispatched_at' => now(),
        ]);

        return [$documentImport, $run];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function successCallback(array $overrides = []): array
    {
        return [
            'status' => 'succeeded',
            'payload' => array_replace([
                'name' => 'AI imported project',
                'objective' => 'Validate the Phase 5 callback boundary.',
                'key_points' => null,
                'budget' => '1500.00',
                'responsible_person' => 'Project owner',
                'monitor_person' => null,
                'evaluation_method' => null,
                'evaluation_tools' => null,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-31',
                'indicators' => [
                    ['name' => 'Completion', 'target_value' => '100', 'unit' => 'percent'],
                ],
            ], $overrides),
            'confidence' => ['name' => 0.98, 'budget' => 0.75],
            'warnings' => [],
            'raw_result' => ['provider' => ['opaque' => true]],
            'provider_job_id' => 'provider-job-1',
            'model_name' => 'test-model',
        ];
    }

    private function signedCallback(
        AiExtractionRun $run,
        string $body,
        string $eventId,
        string $secret = self::CURRENT_SECRET,
    ) {
        $timestamp = (string) now()->timestamp;

        return $this->rawCallback(
            $run,
            $body,
            $eventId,
            $timestamp,
            $this->callbackSignature($run, $body, $timestamp, $eventId, $secret),
        );
    }

    private function rawCallback(
        AiExtractionRun $run,
        string $body,
        string $eventId,
        string $timestamp,
        string $signature,
    ) {
        return $this->call(
            'POST',
            $this->uri($run),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_IMPORT_TIMESTAMP' => $timestamp,
                'HTTP_X_IMPORT_EVENT_ID' => $eventId,
                'HTTP_X_IMPORT_SIGNATURE' => 'sha256='.$signature,
            ],
            content: $body,
        );
    }

    private function callbackSignature(
        AiExtractionRun $run,
        string $body,
        string $timestamp,
        string $eventId,
        string $secret = self::CURRENT_SECRET,
    ): string {
        $digest = hash('sha256', $body);

        return hash_hmac(
            'sha256',
            VerifyImportCallbackSignature::canonicalMessage($timestamp, $eventId, $run->public_id, $digest),
            $secret,
        );
    }

    private function uri(AiExtractionRun $run): string
    {
        return self::URI_PREFIX.'/'.$run->public_id.'/callback';
    }

    /** @param array<string, mixed> $payload */
    private function encodeJson(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function assertUnchangedProcessingState(
        DocumentImport $documentImport,
        AiExtractionRun $run,
        int $expectedPreviewRevisionCount = 0,
    ): void {
        $documentImport->refresh();
        $run->refresh();

        $this->assertSame(DocumentImportStatus::Processing, $documentImport->status);
        $this->assertSame(DocumentImportProcessingStage::WaitingForAi, $documentImport->processing_stage);
        $this->assertSame(AiExtractionRunStatus::Processing, $run->status);
        $this->assertNull($run->provider_event_id);
        $this->assertNull($run->callback_digest);
        $this->assertDatabaseCount('import_preview_revisions', $expectedPreviewRevisionCount);
    }
}
