<?php

namespace Tests\Feature\Api\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\TestCase;

class ProjectSignatureCandidateTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
    }

    #[DataProvider('slotRoles')]
    public function test_candidates_include_only_active_non_deleted_users_with_the_slots_existing_roles(string $slot, array $roles): void
    {
        $manager = $this->signatureUser('director', ['name' => 'Manager']);
        $project = $this->signatureProject($manager);
        $expected = [];
        foreach (['teacher', 'department_head', 'deputy_director', 'director'] as $role) {
            $candidate = $this->signatureUser($role, ['name' => 'Candidate '.$role]);
            if (in_array($role, $roles, true)) {
                $expected[] = $candidate->id;
            }
            $this->signatureUser($role, ['name' => 'Candidate inactive '.$role, 'is_active' => false]);
            $this->signatureUser($role, ['name' => 'Candidate deleted '.$role])->delete();
        }
        $this->signatureUser(overrides: ['name' => 'Candidate without role', 'role_id' => null]);

        $response = $this->actingAs($manager)->getJson("/api/v2/projects/{$project->id}/signature-slots/{$slot}/candidates?q=Candidate")
            ->assertOk();

        $this->assertEqualsCanonicalizing($expected, array_column($response->json('data'), 'id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public static function slotRoles(): array
    {
        return [
            'proposer' => ['project_proposer', ['teacher', 'department_head', 'deputy_director', 'director']],
            'related approver' => ['related_approver', ['teacher', 'department_head', 'deputy_director', 'director']],
            'deputy director' => ['deputy_director', ['deputy_director']],
            'director' => ['director', ['director']],
        ];
    }

    public function test_candidate_fields_are_an_exact_privacy_allowlist(): void
    {
        $manager = $this->signatureUser('director', ['name' => 'Manager']);
        $project = $this->signatureProject($manager);
        $candidate = $this->signatureUser(overrides: [
            'name' => 'Private Candidate', 'email' => 'secret@example.test',
            'phone' => '0801234567', 'teacher_code' => 'PRIVATE-TEACHER-CODE',
        ]);

        $response = $this->actingAs($manager)->getJson("/api/v2/projects/{$project->id}/signature-slots/project_proposer/candidates?q=Private")
            ->assertOk()->assertJsonCount(1, 'data');
        $row = $response->json('data.0');

        $this->assertEqualsCanonicalizing(['id', 'name', 'role', 'department'], array_keys($row));
        $this->assertEqualsCanonicalizing(['code', 'name'], array_keys($row['role']));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($row['department']));
        $this->assertSame($candidate->id, $row['id']);
        $this->assertSame($candidate->name, $row['name']);
        $this->assertSame(['code' => $candidate->role->code, 'name' => $candidate->role->name], $row['role']);
        $this->assertSame(['id' => $candidate->department->id, 'name' => $candidate->department->name], $row['department']);
        foreach (['secret@example.test', '0801234567', 'PRIVATE-TEACHER-CODE', 'email_verified_at', 'permissions', 'remember_token', 'password', 'is_active', 'deleted_at'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $response->getContent());
        }
    }

    public function test_candidate_search_escapes_wildcards_and_uses_name_only(): void
    {
        $manager = $this->signatureUser('director', ['name' => 'Manager']);
        $project = $this->signatureProject($manager);
        $literal = $this->signatureUser(overrides: ['name' => 'Candidate A_% literal']);
        $this->signatureUser(overrides: ['name' => 'Candidate ABC expansion']);
        $this->signatureUser(overrides: ['name' => 'Unmatched account', 'email' => 'hidden-search@example.test', 'teacher_code' => 'HIDDEN-SEARCH']);
        $url = "/api/v2/projects/{$project->id}/signature-slots/project_proposer/candidates";

        $this->actingAs($manager)->getJson($url.'?'.http_build_query(['q' => ' A_% ']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);
        $this->getJson($url.'?q=hidden-search')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_candidate_pagination_is_bounded_and_rejects_unsupported_filters(): void
    {
        $manager = $this->signatureUser('director', ['name' => 'Manager']);
        $project = $this->signatureProject($manager);
        $first = $this->signatureUser(overrides: ['name' => 'Candidate same']);
        $second = $this->signatureUser(overrides: ['name' => 'Candidate same']);
        $url = "/api/v2/projects/{$project->id}/signature-slots/project_proposer/candidates";

        $this->actingAs($manager)->getJson($url.'?q=Candidate&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson($url.'?q=Candidate&per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        foreach (['', '?q=A', '?q=Candidate&per_page=21', '?q=Candidate&page=0', '?q=Candidate&email=secret@example.test'] as $query) {
            $this->getJson($url.$query)->assertUnprocessable();
        }
    }

    public function test_soft_deleted_candidate_is_excluded_and_restoration_restores_eligibility(): void
    {
        $manager = $this->signatureUser('director', ['name' => 'Manager']);
        $project = $this->signatureProject($manager);
        $candidate = $this->signatureUser('deputy_director', ['name' => 'Restorable Candidate']);
        $url = "/api/v2/projects/{$project->id}/signature-slots/deputy_director/candidates?q=Restorable";
        $this->actingAs($manager)->getJson($url)->assertOk()->assertJsonPath('data.0.id', $candidate->id);

        $candidate->delete();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $candidate->restore();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $candidate->id);
    }
}
