<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">จัดการสิทธิ์โครงการ</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $project->name }}</p>
            </div>
            <a href="{{ route('projects.show', $project) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">กลับหน้ารายละเอียด</a>
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-6xl">
            @if (session('success'))
                <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ route('projects.access.update', $project) }}" class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                @csrf
                @method('PUT')

                <div class="border-b border-slate-100 px-6 py-5">
                    <h2 class="font-bold text-slate-900">ผู้ใช้งานในระบบ</h2>
                    <p class="mt-1 text-sm text-slate-500">สิทธิ์แก้ไขหรือลบจะได้รับสิทธิ์ดูโดยอัตโนมัติ</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-6 py-4">ผู้ใช้งาน</th>
                                <th class="px-4 py-4 text-center">ดู</th>
                                <th class="px-4 py-4 text-center">แก้ไข</th>
                                <th class="px-4 py-4 text-center">ลบ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($users as $user)
                                @php($entry = $accessEntries->get($user->id))
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-slate-800">{{ $user->name }}</div>
                                        <div class="mt-1 text-sm text-slate-400">{{ $user->email }} · {{ $user->role_name ?? 'ยังไม่กำหนดบทบาท' }}{{ $user->department_name ? ' · '.$user->department_name : '' }}</div>
                                    </td>
                                    @foreach (['can_view', 'can_edit', 'can_delete'] as $ability)
                                        <td class="px-4 py-4 text-center">
                                            <input type="hidden" name="access[{{ $user->id }}][{{ $ability }}]" value="0">
                                            <input
                                                type="checkbox"
                                                name="access[{{ $user->id }}][{{ $ability }}]"
                                                value="1"
                                                @checked(old("access.{$user->id}.{$ability}", $entry?->{$ability}))
                                                class="h-5 w-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                            >
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-12 text-center text-slate-500">ยังไม่มีผู้ใช้อื่นในระบบ</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end border-t border-slate-100 bg-slate-50 px-6 py-4">
                    <button class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700" type="submit">บันทึกสิทธิ์</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
