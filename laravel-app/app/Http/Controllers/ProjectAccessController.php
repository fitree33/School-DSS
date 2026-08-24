<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectAccessController extends Controller
{
    public function edit(Project $project)
    {
        $this->authorize('manageAccess', $project);

        $users = DB::table('users')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->leftJoin('departments', 'users.department_id', '=', 'departments.id')
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->where('users.id', '!=', $project->user_id)
            ->select('users.id', 'users.name', 'users.email', 'roles.name as role_name', 'departments.name as department_name')
            ->orderBy('users.name')
            ->get();

        $accessEntries = ProjectAccess::where('project_id', $project->id)
            ->get()
            ->keyBy('user_id');

        return view('projects.access', compact('project', 'users', 'accessEntries'));
    }

    public function update(Request $request, Project $project)
    {
        $this->authorize('manageAccess', $project);

        $validated = $request->validate([
            'access' => ['nullable', 'array'],
            'access.*.can_view' => ['nullable', 'boolean'],
            'access.*.can_edit' => ['nullable', 'boolean'],
            'access.*.can_delete' => ['nullable', 'boolean'],
        ]);

        $activeUserIds = DB::table('users')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        DB::transaction(function () use ($validated, $activeUserIds, $project, $request) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::forUser($request->user())->authorize('manageAccess', $project);
            $oldAccess = ProjectAccess::where('project_id', $project->id)
                ->where('user_id', '!=', $project->user_id)
                ->get()
                ->toArray();

            ProjectAccess::where('project_id', $project->id)
                ->where('user_id', '!=', $project->user_id)
                ->delete();

            foreach ($validated['access'] ?? [] as $userId => $abilities) {
                $userId = (int) $userId;
                if (! $activeUserIds->contains($userId) || $userId === $project->user_id) {
                    continue;
                }

                $canEdit = (bool) ($abilities['can_edit'] ?? false);
                $canDelete = (bool) ($abilities['can_delete'] ?? false);
                $canView = (bool) ($abilities['can_view'] ?? false) || $canEdit || $canDelete;

                if (! $canView) {
                    continue;
                }

                ProjectAccess::create([
                    'project_id' => $project->id,
                    'user_id' => $userId,
                    'can_view' => $canView,
                    'can_edit' => $canEdit,
                    'can_delete' => $canDelete,
                    'granted_by' => $request->user()->id,
                ]);
            }

            AuditLog::record('project.access_updated', $project, [
                'access' => $oldAccess,
            ], [
                'access' => ProjectAccess::where('project_id', $project->id)->get()->toArray(),
            ]);
        });

        return redirect()
            ->route('projects.access.edit', $project)
            ->with('success', 'บันทึกสิทธิ์การเข้าถึงเรียบร้อยแล้ว');
    }
}
