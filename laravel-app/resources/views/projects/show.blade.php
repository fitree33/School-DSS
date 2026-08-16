<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                    <a href="{{ route('dashboard') }}" class="font-semibold text-blue-600 hover:text-blue-700">กลับ Dashboard</a>
                    <span>/</span>
                    <a href="{{ route('projects.index') }}" class="hover:text-slate-700">โครงการทั้งหมด</a>
                </div>
                <h1 class="mt-2 truncate text-2xl font-bold text-slate-900">{{ $project->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    @if ($project->project_code) รหัส {{ $project->project_code }} · @endif
                    ปีการศึกษา {{ $project->academicYear?->year ?? '-' }} · {{ $project->department?->name ?? 'ไม่ระบุฝ่าย' }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @can('evaluate', $project)
                    <a href="{{ route('projects.evaluations.edit', $project) }}" class="inline-flex items-center rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-semibold text-slate-900 hover:bg-amber-300">ประเมิน DSS</a>
                @endcan
                @can('manageAccess', $project)
                    <a href="{{ route('projects.access.edit', $project) }}" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">จัดการสิทธิ์</a>
                @endcan
                @can('update', $project)
                    <a href="{{ route('projects.edit', $project) }}" class="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">แก้ไขโครงการ</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl space-y-6">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">{{ session('success') }}</div>
            @endif
            @if (session('warning'))
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800">{{ session('warning') }}</div>
            @endif

            @can('submit', $project)
                <section class="rounded-2xl border border-blue-100 bg-blue-50 p-5 shadow-sm">
                    <h2 class="font-bold text-blue-950">ส่งโครงการเพื่อกลั่นกรอง</h2>
                    <p class="mt-1 text-sm text-blue-800">เมื่อส่งแล้ว รองผู้อำนวยการจะตรวจสอบก่อนส่งต่อให้ผู้อำนวยการอนุมัติ</p>
                    <form method="POST" action="{{ route('projects.workflow.submit', $project) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">ส่งให้รองผู้อำนวยการ</button>
                    </form>
                </section>
            @endcan

            @can('screen', $project)
                <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                    <h2 class="font-bold text-amber-950">กลั่นกรองโครงการ</h2>
                    <p class="mt-1 text-sm text-amber-800">ตรวจสอบความครบถ้วน แล้วส่งต่อหรือส่งกลับให้ครูแก้ไข</p>
                    <form method="POST" action="{{ route('projects.workflow.screen', $project) }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                        @csrf
                        <textarea name="comment" required maxlength="2000" rows="2" class="w-full rounded-xl border-amber-200 bg-white text-sm" placeholder="บันทึกความเห็นสำหรับครูหรือผู้อำนวยการ"></textarea>
                        <button name="decision" value="return" class="rounded-xl border border-rose-300 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">ส่งกลับแก้ไข</button>
                        <button name="decision" value="forward" class="rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-semibold text-slate-900 hover:bg-amber-300">ส่งต่อ ผอ.</button>
                    </form>
                </section>
            @endcan

            @can('decide', $project)
                <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                    <h2 class="font-bold text-emerald-950">พิจารณาอนุมัติโครงการ</h2>
                    <p class="mt-1 text-sm text-emerald-800">ตัดสินใจอนุมัติหรือไม่อนุมัติ พร้อมระบุเหตุผล</p>
                    <form method="POST" action="{{ route('projects.workflow.decide', $project) }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                        @csrf
                        <textarea name="comment" required maxlength="2000" rows="2" class="w-full rounded-xl border-emerald-200 bg-white text-sm" placeholder="บันทึกผลการพิจารณา"></textarea>
                        <button name="decision" value="reject" class="rounded-xl border border-rose-300 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">ไม่อนุมัติ</button>
                        <button name="decision" value="approve" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">อนุมัติ</button>
                    </form>
                </section>
            @endcan

            @can('complete', $project)
                <section x-data="{ open: false }" class="rounded-2xl border border-violet-200 bg-violet-50 p-5 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="font-bold text-violet-950">บันทึกผลและปิดโครงการ</h2>
                            <p class="mt-1 text-sm text-violet-800">กรอกผลจริงของ KPI งบที่ใช้ และสรุปผล เพื่อใช้เป็นข้อมูลตัดสินใจในอนาคต</p>
                        </div>
                        <button type="button" @click="open = ! open" class="rounded-xl bg-violet-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-800" x-text="open ? 'ปิดแบบฟอร์ม' : 'เปิดแบบรายงานผล'"></button>
                    </div>

                    <form x-show="open" x-transition method="POST" action="{{ route('projects.workflow.complete', $project) }}" class="mt-5 space-y-4 rounded-xl bg-white p-5" style="display: none;">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="text-sm font-semibold text-slate-700">ความสำเร็จ (%)</label>
                                <input name="success_percent" type="number" min="0" max="100" step="0.01" required class="mt-2 w-full rounded-xl border-slate-200">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700">คุณภาพ (0–5)</label>
                                <input name="quality_score" type="number" min="0" max="5" step="0.01" required class="mt-2 w-full rounded-xl border-slate-200">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700">งบที่ใช้จริง (บาท)</label>
                                <input name="actual_spent" type="number" min="0" step="0.01" value="{{ $project->budget }}" required class="mt-2 w-full rounded-xl border-slate-200">
                            </div>
                        </div>

                        @if ($project->kpis->isNotEmpty())
                            <div>
                                <p class="text-sm font-semibold text-slate-700">ผลจริงตามตัวชี้วัด</p>
                                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                    @foreach ($project->kpis as $kpi)
                                        <label class="rounded-xl border border-slate-200 p-3 text-sm">
                                            <span class="block font-medium text-slate-700">{{ $kpi->name }}</span>
                                            <span class="mt-1 block text-xs text-slate-400">เป้าหมาย {{ $kpi->target_value ?? '-' }} {{ $kpi->unit }}</span>
                                            <input name="kpi_actuals[{{ $kpi->id }}]" type="number" step="0.01" class="mt-2 w-full rounded-lg border-slate-200" placeholder="ผลจริง">
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div>
                            <label class="text-sm font-semibold text-slate-700">สรุปผลการดำเนินงาน</label>
                            <textarea name="summary" rows="4" required maxlength="5000" class="mt-2 w-full rounded-xl border-slate-200"></textarea>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="text-sm font-semibold text-slate-700">ปัญหาและอุปสรรค</label>
                                <textarea name="problems" rows="3" maxlength="3000" class="mt-2 w-full rounded-xl border-slate-200"></textarea>
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700">ข้อเสนอแนะ</label>
                                <textarea name="suggestions" rows="3" maxlength="3000" class="mt-2 w-full rounded-xl border-slate-200"></textarea>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="rounded-xl bg-violet-700 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-800" onclick="return confirm('ยืนยันการบันทึกผลและปิดโครงการหรือไม่?')">บันทึกผลและปิดโครงการ</button>
                        </div>
                    </form>
                </section>
            @endcan

            <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(300px,1fr)]">
                <div class="space-y-6">
                    <section class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 class="text-lg font-bold text-slate-900">ข้อมูลโครงการ</h2>
                            <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">{{ $project->status?->display_name ?? 'ไม่ระบุสถานะ' }}</span>
                        </div>

                        <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                            @if ($project->rationale)
                                <div class="sm:col-span-2">
                                    <dt class="text-sm font-semibold text-slate-500">หลักการและเหตุผล</dt>
                                    <dd class="mt-2 whitespace-pre-line leading-7 text-slate-700">{{ $project->rationale }}</dd>
                                </div>
                            @endif
                            <div class="sm:col-span-2">
                                <dt class="text-sm font-semibold text-slate-500">วัตถุประสงค์</dt>
                                <dd class="mt-2 whitespace-pre-line leading-7 text-slate-800">{{ $project->objective }}</dd>
                            </div>
                            @if ($project->description)
                                <div class="sm:col-span-2">
                                    <dt class="text-sm font-semibold text-slate-500">รายละเอียดเพิ่มเติม</dt>
                                    <dd class="mt-2 whitespace-pre-line leading-7 text-slate-700">{{ $project->description }}</dd>
                                </div>
                            @endif
                            <div class="rounded-xl bg-slate-50 p-4">
                                <dt class="text-sm text-slate-500">งบประมาณ</dt>
                                <dd class="mt-1 text-xl font-bold text-slate-900">{{ number_format($project->budget ?? 0, 2) }} บาท</dd>
                                <p class="mt-1 text-xs text-slate-400">{{ $project->budget_source ?? 'ไม่ระบุแหล่งงบประมาณ' }}</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-4">
                                <dt class="text-sm text-slate-500">ระยะเวลาดำเนินการ</dt>
                                <dd class="mt-1 font-semibold text-slate-800">
                                    {{ $project->start_date?->format('d/m/Y') ?? '-' }} – {{ $project->end_date?->format('d/m/Y') ?? '-' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-slate-500">หมวดหมู่</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $project->category?->name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-slate-500">ผู้สร้างโครงการ</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $project->owner?->name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-slate-500">ผู้รับผิดชอบ</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $project->responsible_person ?? $project->owner?->name ?? '-' }}</dd>
                            </div>
                            @if ($project->target_group)
                                <div>
                                    <dt class="text-sm text-slate-500">กลุ่มเป้าหมาย</dt>
                                    <dd class="mt-1 whitespace-pre-line text-slate-700">{{ $project->target_group }}</dd>
                                </div>
                            @endif
                            @if ($project->strategy)
                                <div>
                                    <dt class="text-sm text-slate-500">ความสอดคล้องกับกลยุทธ์</dt>
                                    <dd class="mt-1 whitespace-pre-line text-slate-700">{{ $project->strategy }}</dd>
                                </div>
                            @endif
                        </dl>
                    </section>

                    <section class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-bold text-slate-900">ตัวชี้วัดความสำเร็จ (KPI)</h2>
                                <p class="mt-1 text-sm text-slate-500">เปรียบเทียบค่าเป้าหมายกับผลจริงของโครงการ</p>
                            </div>
                            <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">{{ $project->kpis->count() }} ตัวชี้วัด</span>
                        </div>
                        <div class="mt-5 space-y-3">
                            @forelse ($project->kpis as $kpi)
                                @php
                                    $progress = $kpi->target_value && $kpi->actual_value !== null
                                        ? min(100, max(0, ((float) $kpi->actual_value / (float) $kpi->target_value) * 100))
                                        : 0;
                                @endphp
                                <div class="rounded-xl border border-slate-200 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="font-semibold text-slate-800">{{ $kpi->name }}</p>
                                        <p class="text-sm text-slate-500">ผลจริง {{ $kpi->actual_value ?? '-' }} / เป้าหมาย {{ $kpi->target_value ?? '-' }} {{ $kpi->unit }}</p>
                                    </div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-600" style="width: {{ $progress }}%"></div></div>
                                </div>
                            @empty
                                <p class="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-400">ยังไม่ได้กำหนดตัวชี้วัด</p>
                            @endforelse
                        </div>
                    </section>

                    @if ($project->completionReports->isNotEmpty())
                        <section class="rounded-2xl border border-emerald-100 bg-emerald-50 p-6 shadow-sm sm:p-8">
                            <h2 class="text-lg font-bold text-emerald-950">รายงานผลการดำเนินงาน</h2>
                            @foreach ($project->completionReports as $report)
                                <article class="mt-5 rounded-xl bg-white p-5">
                                    <div class="grid gap-3 sm:grid-cols-3">
                                        <div><p class="text-xs text-slate-400">ความสำเร็จ</p><p class="text-xl font-bold text-emerald-700">{{ number_format($report->success_percent, 2) }}%</p></div>
                                        <div><p class="text-xs text-slate-400">คะแนนคุณภาพ</p><p class="text-xl font-bold text-emerald-700">{{ number_format($report->quality_score, 2) }}/5</p></div>
                                        <div><p class="text-xs text-slate-400">งบใช้จริง</p><p class="text-xl font-bold text-emerald-700">{{ number_format($report->actual_spent, 2) }} บาท</p></div>
                                    </div>
                                    <p class="mt-4 whitespace-pre-line leading-7 text-slate-700">{{ $report->summary }}</p>
                                    @if ($report->problems)<p class="mt-3 text-sm text-slate-600"><strong>ปัญหา:</strong> {{ $report->problems }}</p>@endif
                                    @if ($report->suggestions)<p class="mt-1 text-sm text-slate-600"><strong>ข้อเสนอแนะ:</strong> {{ $report->suggestions }}</p>@endif
                                    <p class="mt-3 text-xs text-slate-400">บันทึกโดย {{ $report->reporter?->name ?? 'ระบบ' }} · {{ $report->reported_at->format('d/m/Y H:i') }}</p>
                                </article>
                            @endforeach
                        </section>
                    @endif

                    <section class="rounded-2xl border border-violet-100 bg-violet-50 p-6 shadow-sm sm:p-8">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-bold text-violet-950">สรุปเอกสารด้วย AI</h2>
                                <p class="mt-1 text-sm text-violet-700">ผลสรุปจากเอกสารล่าสุดที่ส่งไปประมวลผลผ่าน n8n</p>
                            </div>
                            <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-violet-700">AI Summary</span>
                        </div>

                        @if ($project->ai_summary)
                            <div class="markdown-summary mt-5 rounded-xl bg-white p-5 text-slate-700">
                                {!! \Illuminate\Support\Str::markdown($project->ai_summary, [
                                    'html_input' => 'strip',
                                    'allow_unsafe_links' => false,
                                ]) !!}
                            </div>
                            <p class="mt-3 text-xs text-violet-700">อัปเดต {{ $project->ai_summarized_at?->format('d/m/Y H:i') }}</p>
                        @else
                            <div class="mt-5 rounded-xl border border-dashed border-violet-200 bg-white/60 px-5 py-8 text-center">
                                <p class="font-semibold text-violet-900">ยังไม่มีสรุปจาก AI</p>
                                <p class="mt-1 text-sm text-violet-700">อัปโหลดเอกสารด้านล่างเพื่อเริ่มประมวลผล</p>
                            </div>
                        @endif
                    </section>

                    <section class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm sm:p-8">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">เอกสารโครงการ</h2>
                            <p class="mt-1 text-sm text-slate-500">รองรับ PDF, Word และข้อความ ขนาดไม่เกิน 10 MB</p>
                        </div>

                        @can('update', $project)
                            <form class="mt-5 flex flex-col gap-3 rounded-xl bg-slate-50 p-4 sm:flex-row" action="{{ route('projects.documents.store', $project) }}" method="POST" enctype="multipart/form-data">
                                @csrf
                                <input class="block w-full text-sm text-slate-700" type="file" name="document" accept=".pdf,.doc,.docx,.txt" required>
                                <button class="shrink-0 rounded-xl bg-[#172235] px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-600" type="submit">อัปโหลดเอกสาร</button>
                            </form>
                            @error('document')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                        @endcan

                        <div class="mt-5 divide-y divide-slate-100">
                            @forelse ($project->documents as $document)
                                @php
                                    $statusStyles = [
                                        'completed' => 'bg-emerald-50 text-emerald-700',
                                        'processing' => 'bg-blue-50 text-blue-700',
                                        'failed' => 'bg-rose-50 text-rose-700',
                                        'pending' => 'bg-amber-50 text-amber-700',
                                    ];
                                    $statusLabels = [
                                        'completed' => 'ประมวลผลแล้ว',
                                        'processing' => 'กำลังประมวลผล',
                                        'failed' => 'ประมวลผลไม่สำเร็จ',
                                        'pending' => 'รอประมวลผล',
                                    ];
                                @endphp
                                <div class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-slate-800">{{ $document->original_name }}</p>
                                        <p class="mt-1 text-xs text-slate-400">
                                            {{ number_format(($document->size ?? 0) / 1024, 1) }} KB · อัปโหลดโดย {{ $document->uploader?->name ?? '-' }} · {{ $document->created_at->format('d/m/Y H:i') }}
                                        </p>
                                    </div>
                                    <span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold {{ $statusStyles[$document->processing_status] ?? 'bg-slate-100 text-slate-600' }}">
                                        {{ $statusLabels[$document->processing_status] ?? $document->processing_status }}
                                    </span>
                                </div>
                            @empty
                                <p class="py-8 text-center text-sm text-slate-400">ยังไม่มีเอกสารในโครงการนี้</p>
                            @endforelse
                        </div>
                    </section>
                </div>

                <aside class="space-y-6">
                    <section class="rounded-2xl bg-[#172235] p-6 text-white shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-300">ผลการตัดสินใจ DSS ล่าสุด</p>
                                @if ($project->latestDssResult)
                                    <p class="mt-3 text-4xl font-bold">{{ number_format($project->latestDssResult->total_score, 2) }}</p>
                                    <p class="text-sm text-slate-300">จาก 100 คะแนน</p>
                                @endif
                            </div>
                            <span class="rounded-xl bg-white/10 px-3 py-2 text-xs font-semibold">DSS</span>
                        </div>

                        @if ($project->latestDssResult)
                            <p class="mt-5 rounded-xl bg-white/10 px-4 py-3 font-semibold">{{ $project->latestDssResult->recommendation }}</p>
                            <p class="mt-4 text-sm leading-6 text-slate-300">{{ $project->latestDssResult->explanation }}</p>
                            <p class="mt-4 text-xs text-slate-400">ประเมินล่าสุด {{ $project->latestDssResult->generated_at->format('d/m/Y H:i') }} · ผู้ประเมิน {{ $project->evaluations->unique('evaluator_id')->count() }} คน</p>
                        @else
                            <p class="mt-4 text-sm leading-6 text-slate-300">ยังไม่มีคะแนนประเมิน ผู้ใช้ที่มีสิทธิ์ประเมินสามารถสร้างผล DSS ได้จากเกณฑ์ถ่วงน้ำหนัก</p>
                        @endif

                        @can('evaluate', $project)
                            <a href="{{ route('projects.evaluations.edit', $project) }}" class="mt-5 inline-flex w-full items-center justify-center rounded-xl bg-amber-400 px-4 py-3 text-sm font-semibold text-slate-900 hover:bg-amber-300">เปิดแบบประเมิน</a>
                        @endcan
                    </section>

                    <section class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                        <h2 class="font-bold text-slate-900">ประวัติสถานะ</h2>
                        <div class="mt-5 space-y-5">
                            @forelse ($project->statusHistory as $history)
                                <div class="relative border-l-2 border-slate-200 pl-5">
                                    <span class="absolute -left-[7px] top-1 h-3 w-3 rounded-full bg-blue-500 ring-4 ring-white"></span>
                                    <p class="text-sm font-semibold text-slate-800">
                                        {{ $history->fromStatus?->display_name ? $history->fromStatus->display_name.' → ' : '' }}{{ $history->toStatus?->display_name ?? '-' }}
                                    </p>
                                    @if ($history->comment)
                                        <p class="mt-1 text-sm leading-6 text-slate-500">{{ $history->comment }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-slate-400">{{ $history->changedBy?->name ?? 'ระบบ' }} · {{ $history->created_at->format('d/m/Y H:i') }}</p>
                                </div>
                            @empty
                                <p class="text-sm text-slate-400">ยังไม่มีประวัติการเปลี่ยนสถานะ</p>
                            @endforelse
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </div>

    <style>
        .markdown-summary > :first-child { margin-top: 0; }
        .markdown-summary > :last-child { margin-bottom: 0; }
        .markdown-summary h1,
        .markdown-summary h2,
        .markdown-summary h3,
        .markdown-summary h4 { margin: 1.25rem 0 .5rem; color: #312e81; font-weight: 700; line-height: 1.35; }
        .markdown-summary h1 { font-size: 1.5rem; }
        .markdown-summary h2 { font-size: 1.25rem; }
        .markdown-summary h3 { font-size: 1.125rem; }
        .markdown-summary p { margin: .75rem 0; line-height: 1.75; }
        .markdown-summary ul,
        .markdown-summary ol { margin: .75rem 0; padding-left: 1.5rem; }
        .markdown-summary ul { list-style: disc; }
        .markdown-summary ol { list-style: decimal; }
        .markdown-summary li { margin: .35rem 0; padding-left: .2rem; }
        .markdown-summary strong { color: #1e293b; font-weight: 700; }
        .markdown-summary a { color: #4f46e5; text-decoration: underline; text-underline-offset: 2px; }
        .markdown-summary blockquote { margin: 1rem 0; border-left: 4px solid #a78bfa; padding: .25rem 0 .25rem 1rem; color: #475569; }
        .markdown-summary code { border-radius: .25rem; background: #ede9fe; padding: .1rem .3rem; color: #5b21b6; font-size: .9em; }
        .markdown-summary pre { margin: 1rem 0; overflow-x: auto; border-radius: .5rem; background: #1e293b; padding: 1rem; color: #f8fafc; }
        .markdown-summary pre code { background: transparent; padding: 0; color: inherit; }
        .markdown-summary hr { margin: 1.25rem 0; border-color: #ddd6fe; }
        .markdown-summary table { margin: 1rem 0; width: 100%; border-collapse: collapse; background: white; }
        .markdown-summary th,
        .markdown-summary td { border: 1px solid #ddd6fe; padding: .625rem .75rem; text-align: left; }
        .markdown-summary th { background: #ede9fe; color: #312e81; font-weight: 700; }
    </style>
</x-app-layout>
