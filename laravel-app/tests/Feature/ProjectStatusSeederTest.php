<?php

namespace Tests\Feature;

use App\Models\ProjectStatus;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProjectStatusSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_preserves_customized_statuses_and_only_fills_a_missing_code(): void
    {
        $customDraft = ProjectStatus::create([
            'name' => 'Local Draft Label',
            'code' => 'draft',
            'color' => 'indigo',
            'sort_order' => 91,
            'is_terminal' => true,
        ]);
        $customApproved = ProjectStatus::create([
            'name' => 'Approved',
            'code' => null,
            'color' => 'teal',
            'sort_order' => 92,
            'is_terminal' => true,
        ]);

        $this->seed(ProjectStatusSeeder::class);

        $customDraft->refresh();
        $customApproved->refresh();

        $this->assertSame('Local Draft Label', $customDraft->name);
        $this->assertSame('indigo', $customDraft->color);
        $this->assertSame(91, $customDraft->sort_order);
        $this->assertTrue($customDraft->is_terminal);
        $this->assertSame('approved', $customApproved->code);
        $this->assertSame('teal', $customApproved->color);
        $this->assertSame(92, $customApproved->sort_order);
        $this->assertTrue($customApproved->is_terminal);
        $this->assertDatabaseHas('project_statuses', ['code' => 'completed']);
    }

    public function test_seeder_fails_explicitly_on_a_conflicting_non_null_code_for_the_same_name(): void
    {
        ProjectStatus::create([
            'name' => 'Draft',
            'code' => 'custom_draft',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project status name [Draft] is already assigned to code [custom_draft].'
        );

        $this->seed(ProjectStatusSeeder::class);
    }
}
