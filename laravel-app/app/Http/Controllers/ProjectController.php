<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EvaluationStatus;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\ProjectExecutionStatus;
use App\Models\ProjectExecutionStatusHistory;
use App\Models\ProjectStatus;
use App\Models\ProjectStatusHistory;
use App\Services\Projects\ProjectSignatureSlotService;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    private const BUDGET_SOURCES = [
        'เงินอุดหนุนรายหัว',
        'เงินกิจกรรมพัฒนาผู้เรียน',
        'เงินรายได้สถานศึกษา',
        'งบประมาณ สพฐ.',
        'เงินบริจาค/ผ้าป่าการศึกษา',
        'อื่น ๆ',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::query()
            ->visibleTo($request->user())
            ->with(['status', 'academicYear', 'department', 'owner'])
            ->latest()
            ->paginate(10);

        return view('projects.index', compact('projects'));
    }

    public function create()
    {
        $this->authorize('create', Project::class);

        $this->ensureLookups();

        return view('projects.create', [
            'departments' => DB::table('departments')->orderBy('name')->get(),
            'categories' => DB::table('project_categories')->orderBy('name')->get(),
            'years' => DB::table('academic_years')->orderByDesc('year')->get(),
            'statuses' => DB::table('project_statuses')->orderBy('sort_order')->orderBy('name')->get(),
            'budgetSources' => self::BUDGET_SOURCES,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $this->ensureLookups();

        $data = $this->validateProject($request);
        $kpis = $data['kpis'] ?? [];
        unset($data['kpis']);
        $data['user_id'] = $request->user()->id;
        $data['responsible_person'] = $data['responsible_person'] ?: $request->user()->name;
        $data['project_status_id'] = ProjectStatus::where('code', 'draft')->value('id');
        $data['project_execution_status_id'] = ProjectExecutionStatus::where('code', 'not_started')
            ->firstOrFail()
            ->id;
        $data['evaluation_status_id'] = EvaluationStatus::where('code', 'pending')
            ->firstOrFail()
            ->id;

        $project = DB::transaction(function () use ($data, $kpis, $request) {
            $project = Project::create($data);

            app(ProjectSignatureSlotService::class)->initializeInTransaction($project, $request->user(), 'legacy_create');

            $this->syncKpis($project, $kpis);

            ProjectAccess::create([
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
                'can_view' => true,
                'can_edit' => true,
                'can_delete' => true,
                'granted_by' => $request->user()->id,
            ]);

            ProjectStatusHistory::create([
                'project_id' => $project->id,
                'to_status_id' => $project->project_status_id,
                'changed_by' => $request->user()->id,
                'comment' => 'สร้างโครงการ',
            ]);

            ProjectExecutionStatusHistory::create([
                'project_id' => $project->id,
                'from_status_id' => null,
                'to_status_id' => $project->project_execution_status_id,
                'changed_by' => $request->user()->id,
                'comment' => 'กำหนดสถานะเริ่มต้นจากหน้าจอเดิม',
            ]);

            AuditLog::record('project.created', $project, [], $project->getAttributes());

            return $project;
        });

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'เพิ่มโครงการเรียบร้อยแล้ว');
    }

    public function show(Project $project)
    {
        $this->authorize('view', $project);

        $project->load([
            'documents.uploader',
            'statusHistory.fromStatus',
            'statusHistory.toStatus',
            'statusHistory.changedBy',
            'status',
            'academicYear',
            'department',
            'category',
            'owner',
            'latestDssResult.generator',
            'evaluations' => fn ($query) => $query
                ->whereNull('evaluation_framework_id')
                ->with('evaluator'),
            'kpis',
            'completionReports.reporter',
        ]);

        return view('projects.show', compact('project'));
    }

    public function edit(Project $project)
    {
        $this->authorize('update', $project);

        $this->ensureLookups();
        $project->load(['status', 'kpis']);

        return view('projects.edit', [
            'project' => $project,
            'departments' => DB::table('departments')->orderBy('name')->get(),
            'categories' => DB::table('project_categories')->orderBy('name')->get(),
            'years' => DB::table('academic_years')->orderByDesc('year')->get(),
            'statuses' => DB::table('project_statuses')->orderBy('sort_order')->orderBy('name')->get(),
            'budgetSources' => self::BUDGET_SOURCES,
        ]);
    }

    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $this->validateProject($request, $project);
        $kpis = $data['kpis'] ?? [];
        unset($data['kpis']);

        DB::transaction(function () use ($data, $kpis, $project, $request) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::forUser($request->user())->authorize('update', $project);
            $oldValues = $project->only(array_keys($data));

            $project->update($data);
            $this->syncKpis($project, $kpis);

            AuditLog::record('project.updated', $project, $oldValues, $project->only(array_keys($data)));
        });

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'แก้ไขโครงการเรียบร้อยแล้ว');
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorize('delete', $project);

        DB::transaction(function () use ($project, $request) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::forUser($request->user())->authorize('delete', $project);

            AuditLog::record('project.deleted', $project, $project->getAttributes());
            $project->delete();
        });

        return redirect()
            ->route('projects.index')
            ->with('success', 'ลบโครงการเรียบร้อยแล้ว และสามารถกู้คืนได้');
    }

    private function validateProject(Request $request, ?Project $project = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'project_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('projects', 'project_code')->ignore($project?->id),
            ],
            'objective' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'rationale' => ['nullable', 'string'],
            'target_group' => ['nullable', 'string', 'max:2000'],
            'strategy' => ['nullable', 'string', 'max:2000'],
            'budget' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'budget_source' => ['nullable', Rule::in(self::BUDGET_SOURCES)],
            'responsible_person' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'department_id' => ['required', 'exists:departments,id'],
            'project_category_id' => ['required', 'exists:project_categories,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'kpis' => ['nullable', 'array', 'max:20'],
            'kpis.*.id' => ['nullable', 'integer'],
            'kpis.*.name' => ['required_with:kpis', 'string', 'max:255'],
            'kpis.*.target_value' => ['nullable', 'numeric'],
            'kpis.*.unit' => ['nullable', 'string', 'max:50'],
        ];

        return $request->validate($rules);
    }

    private function syncKpis(Project $project, array $rows): void
    {
        $keptIds = [];

        foreach ($rows as $row) {
            $attributes = [
                'name' => $row['name'],
                'target_value' => $row['target_value'] ?? null,
                'unit' => $row['unit'] ?? null,
            ];

            $kpi = filled($row['id'] ?? null)
                ? $project->kpis()->whereKey($row['id'])->first()
                : null;

            if ($kpi) {
                $kpi->update($attributes);
            } else {
                $kpi = $project->kpis()->create($attributes);
            }

            $keptIds[] = $kpi->id;
        }

        $project->kpis()
            ->when($keptIds, fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
    }

    private function ensureLookups(): void
    {
        foreach (['ฝ่ายวิชาการ', 'ฝ่ายงบประมาณ', 'ฝ่ายบุคคล', 'ฝ่ายบริหารทั่วไป'] as $name) {
            DB::table('departments')->updateOrInsert(['name' => $name], []);
        }

        foreach (['วิชาการ', 'เทคโนโลยี', 'กีฬา', 'สิ่งแวดล้อม'] as $name) {
            DB::table('project_categories')->updateOrInsert(['name' => $name], []);
        }

        app(ProjectStatusSeeder::class)->run();

        DB::table('academic_years')->updateOrInsert(
            ['year' => 2569],
            ['is_active' => true, 'is_locked' => false]
        );
    }
}
