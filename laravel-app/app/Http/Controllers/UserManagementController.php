<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('manageUsers');

        $users = User::query()
            ->with(['role', 'department'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $keyword = trim($request->string('q')->toString());
                $query->where(function ($search) use ($keyword) {
                    $search->where('name', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%")
                        ->orWhere('teacher_code', 'like', "%{$keyword}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', compact('users'));
    }

    public function edit(User $managedUser)
    {
        Gate::authorize('manageUsers');

        return view('users.edit', [
            'managedUser' => $managedUser->load(['role', 'department']),
            'roles' => Role::query()->orderBy('id')->get(),
            'departments' => Department::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $managedUser)
    {
        Gate::authorize('manageUsers');

        $validated = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'teacher_code' => ['nullable', 'string', 'max:50', 'unique:users,teacher_code,'.$managedUser->id],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($managedUser->is($request->user())) {
            $validated['role_id'] = $managedUser->role_id;
            $validated['is_active'] = true;
        }

        $oldValues = $managedUser->only(array_keys($validated));
        $managedUser->update($validated);

        AuditLog::record('user.access_updated', $managedUser, $oldValues, $managedUser->only(array_keys($validated)));

        return redirect()
            ->route('users.index')
            ->with('success', 'อัปเดตสิทธิ์ผู้ใช้งานเรียบร้อยแล้ว');
    }
}
