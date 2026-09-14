<?php

namespace Tests\Feature;

use App\Enums\ProjectSignatureSlotCode;
use App\Models\ProjectSignatureSlot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\TestCase;

class ProjectSignatureSlotSchemaTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
    }

    public function test_schema_has_exact_unique_indexes_and_restrict_foreign_keys(): void
    {
        $this->assertSame([
            'id', 'project_id', 'slot_code', 'slot_no', 'assigned_user_id', 'assignment_revision',
            'assigned_by', 'assigned_at', 'created_at', 'updated_at',
        ], Schema::getColumnListing('project_signature_slots'));
        $indexes = collect(Schema::getIndexes('project_signature_slots'));
        foreach ([
            'project_signature_slots_project_code_unique' => ['project_id', 'slot_code'],
            'project_signature_slots_project_no_unique' => ['project_id', 'slot_no'],
        ] as $name => $columns) {
            // SQLite assigns autoindex names to table-level UNIQUE constraints.
            $index = DB::getDriverName() === 'sqlite'
                ? $indexes->first(fn ($index) => $index['unique'] && $index['columns'] === $columns)
                : $indexes->firstWhere('name', $name);
            $this->assertNotNull($index);
            $this->assertTrue($index['unique']);
            $this->assertSame($columns, $index['columns']);
        }

        $foreignKeys = collect(Schema::getForeignKeys('project_signature_slots'));
        foreach (['project_id' => 'projects', 'assigned_user_id' => 'users', 'assigned_by' => 'users'] as $column => $table) {
            $key = $foreignKeys->first(fn ($key) => $key['columns'] === [$column]);
            $this->assertNotNull($key);
            $this->assertSame($table, $key['foreign_table']);
            $this->assertSame(['id'], $key['foreign_columns']);
            $this->assertSame('restrict', strtolower($key['on_delete']));
            $this->assertSame('restrict', strtolower($key['on_update']));
        }

        $ddl = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'table')->where('name', 'project_signature_slots')->value('sql')
            : array_values((array) DB::selectOne('SHOW CREATE TABLE project_signature_slots'))[1];
        foreach (['project_code_unique', 'project_no_unique', 'project_fk', 'assignee_fk', 'assigner_fk',
            'mapping_check', 'revision_check', 'assignment_check'] as $constraint) {
            $this->assertStringContainsString('project_signature_slots_'.$constraint, $ddl);
        }
    }

    public function test_canonical_mapping_casts_and_eligibility_role_codes_are_exact(): void
    {
        $expected = ['project_proposer' => 1, 'related_approver' => 2, 'deputy_director' => 3, 'director' => 4];
        $this->assertSame($expected, collect(ProjectSignatureSlotCode::cases())
            ->mapWithKeys(fn ($code) => [$code->value => $code->slotNo()])->all());
        $project = $this->signatureProject($this->signatureUser());
        $this->assertCount(4, $project->signatureSlots);

        foreach ($project->signatureSlots as $slot) {
            $this->assertInstanceOf(ProjectSignatureSlotCode::class, $slot->slot_code);
            $this->assertSame($expected[$slot->slot_code->value], $slot->slot_no);
            $this->assertSame(0, $slot->assignment_revision);
            $this->assertNull($slot->assigned_user_id);
            $this->assertNull($slot->assigned_by);
            $this->assertNull($slot->assigned_at);
        }

        $roles = ['teacher', 'department_head', 'deputy_director', 'director'];
        $this->assertSame($roles, ProjectSignatureSlotCode::ProjectProposer->eligibleRoleCodes());
        $this->assertSame($roles, ProjectSignatureSlotCode::RelatedApprover->eligibleRoleCodes());
        $this->assertSame(['deputy_director'], ProjectSignatureSlotCode::DeputyDirector->eligibleRoleCodes());
        $this->assertSame(['director'], ProjectSignatureSlotCode::Director->eligibleRoleCodes());
    }

    public function test_database_rejects_noncanonical_mapping_and_invalid_assignment_state(): void
    {
        $owner = $this->signatureUser();
        $project = $this->signatureProject($owner, initialize: false);
        $valid = $this->blankRow($project->id);
        $invalidRows = [
            'unknown code' => ['slot_code' => 'proposer'],
            'uppercase code' => ['slot_code' => 'PROJECT_PROPOSER'],
            'trailing whitespace' => ['slot_code' => 'project_proposer '],
            'wrong number' => ['slot_no' => 2],
            'zero number' => ['slot_no' => 0],
            'fifth number' => ['slot_no' => 5],
            'negative revision' => ['assignment_revision' => -1],
            'overflow revision' => ['assignment_revision' => 4294967296],
            'assignee without metadata' => ['assigned_user_id' => $owner->id],
            'assigner without assignee' => ['assigned_by' => $owner->id],
            'date without assignee' => ['assigned_at' => '2026-09-01 01:02:03'],
            'assigned revision zero' => [
                'assigned_user_id' => $owner->id, 'assigned_by' => $owner->id,
                'assigned_at' => '2026-09-01 01:02:03', 'assignment_revision' => 0,
            ],
        ];
        if (DB::getDriverName() === 'sqlite') {
            $invalidRows['fractional number'] = ['slot_no' => 1.5];
            $invalidRows['fractional revision'] = ['assignment_revision' => 1.5];
        }
        foreach ($invalidRows as $label => $overrides) {
            try {
                DB::table('project_signature_slots')->insert(array_replace($valid, $overrides));
                $this->fail('Database accepted '.$label.'.');
            } catch (QueryException) {
                $this->assertDatabaseCount('project_signature_slots', 0);
            }
        }
    }

    public function test_database_rejects_duplicate_slots_but_allows_the_same_slot_codes_for_other_projects(): void
    {
        $owner = $this->signatureUser();
        $project = $this->signatureProject($owner);
        $other = $this->signatureProject($owner);
        $this->assertDatabaseCount('project_signature_slots', 8);

        foreach ([$project, $other] as $target) {
            foreach (ProjectSignatureSlotCode::cases() as $code) {
                try {
                    DB::table('project_signature_slots')->insert(array_replace($this->blankRow($target->id), [
                        'slot_code' => $code->value, 'slot_no' => $code->slotNo(),
                    ]));
                    $this->fail('Database accepted a duplicate canonical slot.');
                } catch (QueryException) {
                    $this->assertDatabaseCount('project_signature_slots', 8);
                }
            }
        }
    }

    public function test_database_rejects_orphan_project_assignee_and_assigner_references(): void
    {
        $owner = $this->signatureUser();
        $project = $this->signatureProject($owner, initialize: false);
        $valid = array_replace($this->blankRow($project->id), [
            'assigned_user_id' => $owner->id, 'assigned_by' => $owner->id,
            'assigned_at' => '2026-09-01 01:02:03', 'assignment_revision' => 1,
        ]);
        foreach (['project_id', 'assigned_user_id', 'assigned_by'] as $column) {
            try {
                DB::table('project_signature_slots')->insert(array_replace($valid, [$column => 999999]));
                $this->fail('Database accepted an orphan '.$column.'.');
            } catch (QueryException) {
                $this->assertDatabaseCount('project_signature_slots', 0);
            }
        }
    }

    public function test_slot_identity_and_deletion_are_immutable_through_the_model(): void
    {
        $project = $this->signatureProject($this->signatureUser());
        $slot = $project->signatureSlots()->where('slot_no', 1)->firstOrFail();
        $before = $slot->getRawOriginal();
        foreach ([
            'id' => 999999, 'project_id' => 999999,
            'slot_code' => ProjectSignatureSlotCode::RelatedApprover, 'slot_no' => 2,
            'created_at' => '2020-01-01 00:00:00',
        ] as $attribute => $value) {
            try {
                $slot->fresh()->forceFill([$attribute => $value])->save();
                $this->fail('Model accepted identity change: '.$attribute);
            } catch (LogicException $exception) {
                $this->assertSame('Project signature slot identity cannot be changed.', $exception->getMessage());
                $this->assertSame($before, $slot->fresh()->getRawOriginal());
            }
        }
        try {
            $slot->delete();
            $this->fail('Model accepted slot deletion.');
        } catch (LogicException $exception) {
            $this->assertSame('Project signature slots cannot be deleted.', $exception->getMessage());
            $this->assertSame($before, $slot->fresh()->getRawOriginal());
        }
    }

    public function test_project_assignee_and_assigner_hard_delete_are_restricted_and_soft_deletes_preserve_history(): void
    {
        $owner = $this->signatureUser();
        $assignee = $this->signatureUser();
        $assigner = $this->signatureUser('director');
        $project = $this->signatureProject($owner, initialize: false);
        $id = DB::table('project_signature_slots')->insertGetId(array_replace($this->blankRow($project->id), [
            'assigned_user_id' => $assignee->id, 'assigned_by' => $assigner->id,
            'assignment_revision' => 1, 'assigned_at' => '2026-09-01 01:02:03',
        ]));
        $slot = ProjectSignatureSlot::query()->findOrFail($id);
        $before = $slot->getRawOriginal();
        foreach ([$project, $assignee, $assigner] as $model) {
            $model->delete();
            $this->assertTrue($model->trashed());
            try {
                $model->forceDelete();
                $this->fail('Database accepted hard deletion of a signature reference.');
            } catch (QueryException) {
                $this->assertDatabaseHas($model->getTable(), ['id' => $model->id]);
                $this->assertSame($before, $slot->fresh()->getRawOriginal());
            }
        }
        $this->assertTrue($slot->fresh()->assignee->trashed());
        $this->assertTrue($slot->fresh()->assigner->trashed());
        $assignee->restore();
        $assigner->restore();
        $project->restore();
        $this->assertFalse($slot->fresh()->assignee->trashed());
        $this->assertFalse($slot->fresh()->assigner->trashed());
        $this->assertSame($before, $slot->fresh()->getRawOriginal());
    }

    private function blankRow(int $projectId): array
    {
        return [
            'project_id' => $projectId, 'slot_code' => 'project_proposer', 'slot_no' => 1,
            'assigned_user_id' => null, 'assignment_revision' => 0, 'assigned_by' => null,
            'assigned_at' => null, 'created_at' => '2026-09-01 01:02:03', 'updated_at' => '2026-09-01 01:02:03',
        ];
    }
}
