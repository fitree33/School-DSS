<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">กำหนดสิทธิ์ผู้ใช้งาน</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $managedUser->name }} · {{ $managedUser->email }}</p>
            </div>
            <a href="{{ route('users.index') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-center text-sm font-semibold text-slate-600 hover:bg-slate-50">กลับรายการผู้ใช้</a>
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl">
            <form method="POST" action="{{ route('users.update', $managedUser) }}" class="space-y-6 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm sm:p-8">
                @csrf
                @method('PUT')

                @if ($managedUser->is(auth()->user()))
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">เพื่อป้องกันการล็อกตัวเองออกจากระบบ คุณไม่สามารถเปลี่ยนบทบาทหรือระงับบัญชีของตัวเองได้</div>
                @endif

                <div>
                    <label for="teacher_code" class="text-sm font-semibold text-slate-700">รหัสครู</label>
                    <input id="teacher_code" name="teacher_code" value="{{ old('teacher_code', $managedUser->teacher_code) }}" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                    @error('teacher_code')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="role_id" class="text-sm font-semibold text-slate-700">บทบาท <span class="text-rose-500">*</span></label>
                    <select id="role_id" name="role_id" required @disabled($managedUser->is(auth()->user())) class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500 disabled:bg-slate-100">
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected(old('role_id', $managedUser->role_id) == $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    @if ($managedUser->is(auth()->user()))
                        <input type="hidden" name="role_id" value="{{ $managedUser->role_id }}">
                    @endif
                </div>

                <div>
                    <label for="department_id" class="text-sm font-semibold text-slate-700">ฝ่าย</label>
                    <select id="department_id" name="department_id" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                        <option value="">ไม่ระบุฝ่าย</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected(old('department_id', $managedUser->department_id) == $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="is_active" class="text-sm font-semibold text-slate-700">สถานะบัญชี</label>
                    <select id="is_active" name="is_active" @disabled($managedUser->is(auth()->user())) class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500 disabled:bg-slate-100">
                        <option value="1" @selected(old('is_active', (int) $managedUser->is_active) == 1)>ใช้งาน</option>
                        <option value="0" @selected(old('is_active', (int) $managedUser->is_active) == 0)>ระงับใช้งาน</option>
                    </select>
                    @if ($managedUser->is(auth()->user()))
                        <input type="hidden" name="is_active" value="1">
                    @endif
                </div>

                <div class="flex justify-end border-t border-slate-100 pt-6">
                    <button type="submit" class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-700">บันทึกสิทธิ์ผู้ใช้</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
