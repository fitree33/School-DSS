<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectStatusHistory;
use App\Models\ProjectNotification;
use App\Models\ProjectCompletionReport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectWorkflowController extends Controller
{
    public function submit(Request $request, Project $project)
    {
        $this->authorize('submit', $project);

        $this->transition($project, 'pending_deputy', $request->user(), 'ส่งโครงการให้รองผู้อำนวยการกลั่นกรอง');
        $this->notifyRole('deputy_director', $project, 'มีโครงการรอกลั่นกรอง', "โครงการ {$project->name} ถูกส่งเข้ามาเพื่อกลั่นกรอง", 'review');

        return back()->with('success', 'ส่งโครงการให้รองผู้อำนวยการกลั่นกรองแล้ว');
    }

    public function screen(Request $request, Project $project)
    {
        $this->authorize('screen', $project);

        $data = $request->validate([
            'decision' => ['required', 'in:forward,return'],
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $next = $data['decision'] === 'forward' ? 'pending_director' : 'returned';
        $message = $data['decision'] === 'forward'
            ? 'รองผู้อำนวยการกลั่นกรองและส่งต่อให้ผู้อำนวยการแล้ว'
            : 'รองผู้อำนวยการส่งโครงการกลับให้แก้ไขแล้ว';

        $this->transition($project, $next, $request->user(), $data['comment']);

        if ($data['decision'] === 'forward') {
            $this->notifyRole('director', $project, 'มีโครงการรออนุมัติ', "โครงการ {$project->name} ผ่านการกลั่นกรองและรอการอนุมัติ", 'approval');
        } else {
            $this->notifyUser($project->owner, $project, 'โครงการถูกส่งกลับแก้ไข', "โครงการ {$project->name} มีข้อเสนอแนะจากรองผู้อำนวยการ", 'revision');
        }

        return back()->with('success', $message);
    }

    public function decide(Request $request, Project $project)
    {
        $this->authorize('decide', $project);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $next = $data['decision'] === 'approve' ? 'approved' : 'rejected';
        $message = $data['decision'] === 'approve'
            ? 'อนุมัติโครงการเรียบร้อยแล้ว'
            : 'ไม่อนุมัติโครงการแล้ว';

        $this->transition($project, $next, $request->user(), $data['comment']);
        $this->notifyUser($project->owner, $project, 'ผลการพิจารณาโครงการ', "โครงการ {$project->name}: {$message}", $data['decision']);

        return back()->with('success', $message);
    }

    public function complete(Request $request, Project $project)
    {
        $this->authorize('complete', $project);

        $data = $request->validate([
            'success_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'quality_score' => ['required', 'numeric', 'min:0', 'max:5'],
            'actual_spent' => ['required', 'numeric', 'min:0'],
            'summary' => ['required', 'string', 'max:5000'],
            'problems' => ['nullable', 'string', 'max:3000'],
            'suggestions' => ['nullable', 'string', 'max:3000'],
            'kpi_actuals' => ['nullable', 'array'],
            'kpi_actuals.*' => ['nullable', 'numeric'],
        ]);

        DB::transaction(function () use ($data, $project, $request) {
            foreach ($project->kpis as $kpi) {
                if (array_key_exists($kpi->id, $data['kpi_actuals'] ?? [])) {
                    $kpi->update(['actual_value' => $data['kpi_actuals'][$kpi->id]]);
                }
            }

            ProjectCompletionReport::create([
                'project_id' => $project->id,
                'reported_by' => $request->user()->id,
                'success_percent' => $data['success_percent'],
                'quality_score' => $data['quality_score'],
                'actual_spent' => $data['actual_spent'],
                'summary' => $data['summary'],
                'problems' => $data['problems'] ?? null,
                'suggestions' => $data['suggestions'] ?? null,
                'reported_at' => now(),
            ]);

            $project->update(['actual_spent' => $data['actual_spent']]);
            $this->transition($project, 'completed', $request->user(), 'บันทึกรายงานผลและปิดโครงการ');
        });

        $this->notifyRole('director', $project, 'โครงการรายงานผลแล้ว', "โครงการ {$project->name} บันทึกผลการดำเนินงานเรียบร้อยแล้ว", 'completed');

        return back()->with('success', 'บันทึกผลการดำเนินงานและปิดโครงการแล้ว');
    }

    private function transition(Project $project, string $nextCode, $user, string $comment): void
    {
        $nextStatus = ProjectStatus::where('code', $nextCode)->firstOrFail();
        $oldStatusId = $project->project_status_id;

        DB::transaction(function () use ($project, $nextStatus, $oldStatusId, $user, $comment, $nextCode) {
            $timestamps = match ($nextCode) {
                'pending_deputy' => ['submitted_at' => now()],
                'pending_director' => ['screened_at' => now()],
                'approved' => ['approved_at' => now()],
                'completed' => ['completed_at' => now()],
                default => [],
            };

            $project->update(['project_status_id' => $nextStatus->id] + $timestamps);

            ProjectStatusHistory::create([
                'project_id' => $project->id,
                'from_status_id' => $oldStatusId,
                'to_status_id' => $nextStatus->id,
                'changed_by' => $user->id,
                'comment' => $comment,
            ]);

            AuditLog::record('project.workflow_changed', $project, [
                'project_status_id' => $oldStatusId,
            ], [
                'project_status_id' => $nextStatus->id,
                'comment' => $comment,
            ]);
        });
    }

    private function notifyRole(string $roleCode, Project $project, string $title, string $message, string $type): void
    {
        User::query()
            ->whereHas('role', fn ($query) => $query->where('code', $roleCode))
            ->where('is_active', true)
            ->each(fn (User $user) => $this->notifyUser($user, $project, $title, $message, $type));
    }

    private function notifyUser(?User $user, Project $project, string $title, string $message, string $type): void
    {
        if (! $user) {
            return;
        }

        ProjectNotification::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
        ]);
    }
}
