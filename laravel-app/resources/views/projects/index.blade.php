<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">โครงการทั้งหมด</h1>
                <p class="mt-1 text-sm text-slate-500">แสดงเฉพาะโครงการที่คุณมีสิทธิ์เข้าถึง</p>
            </div>
            @can('create', App\Models\Project::class)
                <a href="{{ route('projects.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-200 hover:bg-blue-700">
                    <span class="text-lg leading-none">+</span> เพิ่มโครงการ
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            @if (session('success'))
                <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">{{ session('success') }}</div>
            @endif

            <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-6 py-4">โครงการ</th>
                                <th class="px-4 py-4">ปีการศึกษา</th>
                                <th class="px-4 py-4">ฝ่าย</th>
                                <th class="px-4 py-4">งบประมาณ</th>
                                <th class="px-4 py-4">สถานะ</th>
                                <th class="px-6 py-4 text-right">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($projects as $project)
                                <tr class="transition hover:bg-slate-50/70">
                                    <td class="px-6 py-4">
                                        <a href="{{ route('projects.show', $project) }}" class="font-semibold text-slate-800 hover:text-blue-600">{{ $project->name }}</a>
                                        <p class="mt-1 max-w-sm truncate text-sm text-slate-400">{{ $project->project_code ? $project->project_code.' · ' : '' }}สร้างโดย {{ $project->owner?->name ?? '-' }}</p>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-600">{{ $project->academicYear?->year ?? '-' }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-600">{{ $project->department?->name ?? '-' }}</td>
                                    <td class="px-4 py-4 text-sm font-semibold text-slate-700">{{ number_format($project->budget, 2) }} บาท</td>
                                    <td class="px-4 py-4">
                                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $project->status?->display_name ?? '-' }}</span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <a href="{{ route('projects.show', $project) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-700">ดูรายละเอียด</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-16 text-center">
                                        <p class="font-semibold text-slate-700">ยังไม่มีโครงการที่คุณเข้าถึงได้</p>
                                        <p class="mt-1 text-sm text-slate-400">สร้างโครงการใหม่หรือขอสิทธิ์จากผู้ดูแลระบบ</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">{{ $projects->links() }}</div>
        </div>
    </div>
</x-app-layout>
