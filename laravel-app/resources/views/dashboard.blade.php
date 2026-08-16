<x-app-layout>
    @php
        $statusLabels = [
            'draft' => 'ร่างโครงการ',
            'returned' => 'ส่งกลับแก้ไข',
            'pending_deputy' => 'รอรอง ผอ. กลั่นกรอง',
            'pending_director' => 'รอ ผอ. อนุมัติ',
            'approved' => 'อนุมัติแล้ว',
            'rejected' => 'ไม่อนุมัติ',
            'in_progress' => 'กำลังดำเนินการ',
            'completed' => 'รายงานผลแล้ว',
            'archived' => 'จัดเก็บแล้ว',
        ];
        $statusColors = [
            'draft' => 'bg-slate-400',
            'returned' => 'bg-rose-400',
            'pending_deputy' => 'bg-amber-400',
            'pending_director' => 'bg-orange-500',
            'approved' => 'bg-emerald-500',
            'rejected' => 'bg-rose-600',
            'in_progress' => 'bg-blue-500',
            'completed' => 'bg-violet-500',
            'archived' => 'bg-slate-600',
        ];
        $maxStatus = max(1, (int) $statusDistribution->max('total'));
        $maxBudgetSource = max(1, (float) $budgetBySource->max('total'));
    @endphp

    <div class="border-t-4 border-amber-400 px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl space-y-6">
            <section class="overflow-hidden rounded-2xl bg-[#172235] text-white shadow-xl">
                <div class="grid gap-6 px-6 py-7 sm:px-8 lg:grid-cols-[1fr_auto] lg:items-center">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-300">School Project Decision Support System</p>
                        <h1 class="mt-2 text-2xl font-bold sm:text-3xl">แดชบอร์ดภาพรวมสำหรับผู้บริหาร</h1>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">ติดตามสถานะการเสนอ คัดกรอง อนุมัติ งบประมาณ ผลประเมิน และข้อมูลประกอบการตัดสินใจจากจุดเดียว</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('projects.index') }}" class="rounded-xl border border-white/20 bg-white/10 px-4 py-3 text-sm font-semibold text-white hover:bg-white/20">ทะเบียนโครงการ</a>
                        @can('create', App\Models\Project::class)
                            <a href="{{ route('projects.create') }}" class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-bold text-slate-900 hover:bg-amber-300">+ ร่างโครงการใหม่</a>
                        @endcan
                    </div>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([
                    ['โครงการทั้งหมด', $stats['total'], 'อยู่ในขอบเขตสิทธิ์', 'bg-blue-50 text-blue-700'],
                    ['รอพิจารณา', $stats['waiting'], 'รอง ผอ. / ผอ.', 'bg-amber-50 text-amber-700'],
                    ['อนุมัติ/ดำเนินการ', $stats['approved'], 'พร้อมดำเนินงาน', 'bg-emerald-50 text-emerald-700'],
                    ['รายงานผลแล้ว', $stats['completed'], 'มีข้อมูลผลจริง', 'bg-violet-50 text-violet-700'],
                    ['ส่งกลับ/ไม่อนุมัติ', $stats['returned'], 'ต้องติดตาม', 'bg-rose-50 text-rose-700'],
                ] as [$label, $value, $caption, $tone])
                    <article class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $tone }}">{{ $caption }}</span>
                        <p class="mt-4 text-sm font-medium text-slate-500">{{ $label }}</p>
                        <p class="mt-1 text-3xl font-bold text-slate-900">{{ number_format($value) }}</p>
                    </article>
                @endforeach
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">งบประมาณที่เสนอ</p>
                    <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['total_budget'], 2) }}</p>
                    <p class="text-xs text-slate-400">บาท</p>
                </article>
                <article class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">งบโครงการที่อนุมัติ</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-700">{{ number_format($stats['approved_budget'], 2) }}</p>
                    <p class="text-xs text-slate-400">บาท</p>
                </article>
                <article class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm text-slate-500">งบใช้จริง</p>
                        <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">{{ number_format($stats['budget_utilization'], 1) }}%</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-blue-700">{{ number_format($stats['actual_spent'], 2) }}</p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-600" style="width: {{ min(100, $stats['budget_utilization']) }}%"></div></div>
                </article>
                <article class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">ความสำเร็จเฉลี่ย</p>
                    <p class="mt-2 text-2xl font-bold text-violet-700">{{ number_format($stats['average_success'], 1) }}%</p>
                    <p class="text-xs text-slate-400">จากรายงานผลที่บันทึกแล้ว</p>
                </article>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">สัดส่วนสถานะโครงการ</h2>
                        <p class="mt-1 text-sm text-slate-500">แสดงตามขั้นตอน Workflow ที่บังคับสิทธิ์แล้ว</p>
                    </div>
                    <div class="mt-6 space-y-4">
                        @forelse ($statusDistribution as $row)
                            <div>
                                <div class="flex items-center justify-between gap-4 text-sm">
                                    <span class="font-medium text-slate-700">{{ $statusLabels[$row->code] ?? $row->name }}</span>
                                    <span class="font-bold text-slate-900">{{ number_format($row->total) }}</span>
                                </div>
                                <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $statusColors[$row->code] ?? 'bg-slate-400' }}" style="width: {{ ((int) $row->total / $maxStatus) * 100 }}%"></div></div>
                            </div>
                        @empty
                            <p class="py-10 text-center text-sm text-slate-400">ยังไม่มีข้อมูลโครงการ</p>
                        @endforelse
                    </div>
                </article>

                <article class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">งบประมาณตามแหล่งเงิน</h2>
                        <p class="mt-1 text-sm text-slate-500">ช่วยผู้บริหารตรวจสัดส่วนแหล่งงบประมาณ</p>
                    </div>
                    <div class="mt-6 space-y-4">
                        @forelse ($budgetBySource as $row)
                            <div>
                                <div class="flex items-center justify-between gap-4 text-sm">
                                    <span class="truncate font-medium text-slate-700">{{ $row->budget_source ?: 'ไม่ระบุแหล่งงบประมาณ' }}</span>
                                    <span class="shrink-0 font-bold text-slate-900">{{ number_format($row->total, 0) }} บาท</span>
                                </div>
                                <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-[#172235]" style="width: {{ ((float) $row->total / $maxBudgetSource) * 100 }}%"></div></div>
                            </div>
                        @empty
                            <p class="py-10 text-center text-sm text-slate-400">ยังไม่มีข้อมูลงบประมาณ</p>
                        @endforelse
                    </div>
                </article>
            </section>

            <section class="grid gap-6 xl:grid-cols-3">
                <article class="overflow-hidden rounded-2xl border border-amber-100 bg-white shadow-sm xl:col-span-2">
                    <div class="flex items-center justify-between gap-4 border-b border-amber-100 bg-amber-50 px-6 py-5">
                        <div>
                            <h2 class="text-lg font-bold text-amber-950">งานที่รอการพิจารณา</h2>
                            <p class="mt-1 text-sm text-amber-800">เรียงตามรายการที่รอนานที่สุด</p>
                        </div>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-amber-700">{{ $waitingProjects->count() }} รายการ</span>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @forelse ($waitingProjects as $project)
                            <a href="{{ route('projects.show', $project) }}" class="flex items-center gap-4 px-6 py-4 hover:bg-slate-50">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 font-bold text-amber-700">{{ mb_substr($project->name, 0, 1) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold text-slate-800">{{ $project->name }}</span>
                                    <span class="mt-1 block text-xs text-slate-400">{{ $project->owner?->name ?? '-' }} · {{ $project->department?->name ?? '-' }}</span>
                                </span>
                                <span class="hidden rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 sm:inline">{{ $project->status?->display_name }}</span>
                                <span class="text-slate-300">›</span>
                            </a>
                        @empty
                            <p class="px-6 py-14 text-center text-sm text-slate-400">ไม่มีโครงการรอการพิจารณา</p>
                        @endforelse
                    </div>
                </article>

                <article class="rounded-2xl bg-[#172235] p-6 text-white shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold">อันดับคะแนน DSS</h2>
                            <p class="mt-1 text-sm text-slate-300">ผลประเมินล่าสุด</p>
                        </div>
                        <span class="rounded-xl bg-amber-400 px-2.5 py-1 text-xs font-bold text-slate-900">TOP 5</span>
                    </div>
                    <div class="mt-5 space-y-3">
                        @forelse ($topProjects as $project)
                            <a href="{{ route('projects.show', $project) }}" class="flex items-center gap-3 rounded-xl bg-white/5 p-3 hover:bg-white/10">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-400 text-sm font-bold text-slate-900">{{ $loop->iteration }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $project->name }}</span>
                                <span class="font-bold text-amber-300">{{ number_format($project->latestDssResult->total_score, 1) }}</span>
                            </a>
                        @empty
                            <p class="py-8 text-center text-sm text-slate-400">ยังไม่มีผลประเมิน DSS</p>
                        @endforelse
                    </div>
                </article>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">โครงการที่ปรับปรุงล่าสุด</h2>
                        <p class="mt-1 text-sm text-slate-500">แสดงเฉพาะโครงการที่คุณมีสิทธิ์เข้าถึง</p>
                    </div>
                    <a href="{{ route('projects.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-800">ดูทั้งหมด</a>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse ($recentProjects as $project)
                        <a href="{{ route('projects.show', $project) }}" class="grid gap-2 px-6 py-4 hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_160px_180px_auto] sm:items-center">
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-slate-800">{{ $project->name }}</span>
                                <span class="mt-1 block text-xs text-slate-400">{{ $project->project_code ?: 'ยังไม่มีรหัส' }} · {{ $project->owner?->name ?? '-' }}</span>
                            </span>
                            <span class="text-sm text-slate-500">{{ $project->department?->name ?? '-' }}</span>
                            <span class="text-sm font-medium text-slate-600">{{ $project->status?->display_name ?? '-' }}</span>
                            <span class="text-slate-300">›</span>
                        </a>
                    @empty
                        <p class="px-6 py-14 text-center text-sm text-slate-400">ยังไม่มีโครงการในระบบ</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
