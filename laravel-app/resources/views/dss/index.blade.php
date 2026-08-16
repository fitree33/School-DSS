<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-amber-600">Decision Support Center</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-900">จัดอันดับและวิเคราะห์ทางเลือกโครงการ</h1>
            <p class="mt-1 text-sm text-slate-500">ใช้ SAW รวมคะแนนหลายเกณฑ์ ทดลองปรับน้ำหนักแบบ What-if และเลือกชุดโครงการภายใต้งบประมาณ</p>
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl space-y-6">
            <form method="GET" action="{{ route('dss.index') }}" class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 border-b border-slate-100 pb-5 lg:flex-row lg:items-end lg:justify-between">
                    <div class="grid flex-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="year_id" class="text-sm font-semibold text-slate-700">ปีการศึกษา</label>
                            <select id="year_id" name="year_id" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                                @foreach ($years as $year)
                                    <option value="{{ $year->id }}" @selected($selectedYearId === $year->id)>{{ $year->year }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="budget_limit" class="text-sm font-semibold text-slate-700">งบประมาณรวมสำหรับจำลอง</label>
                            <input id="budget_limit" name="budget_limit" type="number" min="0" step="1000" value="{{ request('budget_limit') }}" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500" placeholder="เช่น 500000">
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('dss.index', ['year_id' => $selectedYearId]) }}" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">คืนค่าน้ำหนัก</a>
                        <button type="submit" class="rounded-xl bg-[#172235] px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">วิเคราะห์สถานการณ์</button>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="font-bold text-slate-900">What-if: น้ำหนักเกณฑ์</h2>
                            <p class="mt-1 text-sm text-slate-500">กรอกค่าใดก็ได้ ระบบจะปรับสัดส่วนรวมเป็น 100% อัตโนมัติ</p>
                        </div>
                        <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">น้ำหนักรวม 100%</span>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        @foreach ($criteria as $criterion)
                            <label class="rounded-xl border border-slate-200 p-4">
                                <span class="block min-h-10 text-sm font-semibold text-slate-700">{{ $criterion->name }}</span>
                                <span class="mt-2 flex items-center gap-2">
                                    <input name="weights[{{ $criterion->id }}]" type="number" min="0" step="0.1" value="{{ number_format($weights[$criterion->id] ?? 0, 2, '.', '') }}" class="w-full rounded-lg border-slate-200">
                                    <span class="text-sm text-slate-400">%</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </form>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl bg-[#172235] p-5 text-white shadow-sm">
                    <p class="text-sm text-slate-300">โครงการที่นำมาวิเคราะห์</p>
                    <p class="mt-2 text-3xl font-bold">{{ $rankedProjects->count() }}</p>
                </article>
                <article class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">มีคะแนนประเมิน</p>
                    <p class="mt-2 text-3xl font-bold text-blue-700">{{ $rankedProjects->whereNotNull('portfolio_score')->count() }}</p>
                </article>
                <article class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">ชุดโครงการที่ระบบแนะนำ</p>
                    <p class="mt-2 text-3xl font-bold text-emerald-700">{{ count($portfolio['ids']) }}</p>
                </article>
                <article class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">งบรวมของชุดที่แนะนำ</p>
                    <p class="mt-2 text-2xl font-bold text-amber-700">{{ number_format($portfolio['budget'], 2) }}</p>
                    <p class="text-xs text-slate-400">จากวงเงิน {{ $budgetLimit > 0 ? number_format($budgetLimit, 2) : '-' }} บาท</p>
                </article>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h2 class="text-lg font-bold text-slate-900">ตารางจัดอันดับโครงการ</h2>
                    <p class="mt-1 text-sm text-slate-500">คะแนนคำนวณใหม่จากแบบประเมินล่าสุดของผู้ประเมินแต่ละคนตามน้ำหนักในสถานการณ์นี้</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1100px] text-left">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-4 text-center">อันดับ</th>
                                <th class="px-5 py-4">โครงการ</th>
                                <th class="px-4 py-4">สถานะ</th>
                                <th class="px-4 py-4 text-right">งบประมาณ</th>
                                <th class="px-4 py-4 text-center">ผู้ประเมิน</th>
                                <th class="px-4 py-4 text-center">คะแนน SAW</th>
                                <th class="px-4 py-4">จุดเด่น / จุดที่ควรพัฒนา</th>
                                <th class="px-5 py-4 text-center">ชุดแนะนำ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($rankedProjects as $project)
                                <tr class="align-top hover:bg-slate-50/70">
                                    <td class="px-5 py-4 text-center">
                                        @if ($project->portfolio_rank)
                                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full {{ $project->portfolio_rank <= 3 ? 'bg-amber-400 font-bold text-slate-900' : 'bg-slate-100 font-semibold text-slate-600' }}">{{ $project->portfolio_rank }}</span>
                                        @else
                                            <span class="text-slate-300">–</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        <a href="{{ route('projects.show', $project) }}" class="font-semibold text-slate-800 hover:text-blue-700">{{ $project->name }}</a>
                                        <p class="mt-1 text-xs text-slate-400">{{ $project->department?->name ?? '-' }}</p>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-600">{{ $project->status?->display_name ?? '-' }}</td>
                                    <td class="px-4 py-4 text-right text-sm font-semibold text-slate-700">{{ number_format($project->budget, 2) }}</td>
                                    <td class="px-4 py-4 text-center text-sm text-slate-600">{{ $project->evaluator_count }}</td>
                                    <td class="px-4 py-4 text-center">
                                        @if ($project->portfolio_score !== null)
                                            <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700">{{ number_format($project->portfolio_score, 2) }}</span>
                                        @else
                                            <span class="text-xs text-slate-400">ยังไม่มีคะแนน</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-xs leading-5">
                                        @if ($project->portfolio_score !== null)
                                            <p class="text-emerald-700"><strong>เด่น:</strong> {{ $project->strengths ?: '-' }}</p>
                                            <p class="mt-1 text-rose-600"><strong>พัฒนา:</strong> {{ $project->weaknesses ?: '-' }}</p>
                                        @else
                                            <span class="text-slate-400">ต้องประเมินโครงการก่อน</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        @if (in_array($project->id, $portfolio['ids'], true))
                                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">เลือก</span>
                                        @else
                                            <span class="text-slate-300">–</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-6 py-16 text-center text-sm text-slate-400">ยังไม่มีโครงการในปีการศึกษาที่เลือก</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                <h2 class="font-bold text-amber-950">วิธีการคำนวณและข้อจำกัด</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-6 text-amber-900">
                    <li>จัดอันดับด้วยวิธี SAW (Simple Additive Weighting) จากคะแนนหลายเกณฑ์ที่ปรับเป็นสัดส่วน 0–1</li>
                    <li>ใช้แบบประเมินรอบล่าสุดของผู้ประเมินแต่ละคน เพื่อลดการนับคะแนนซ้ำ</li>
                    <li>เมื่อระบุวงเงิน ระบบใช้แบบจำลอง 0/1 Knapsack แบบแบ่งหน่วยงบประมาณ เพื่อหาชุดโครงการที่ให้ผลรวมคะแนนสูงภายใต้วงเงิน</li>
                    <li>ผลลัพธ์เป็นข้อมูลประกอบการตัดสินใจ ผู้บริหารยังต้องตรวจหลักฐาน ความเร่งด่วน และข้อจำกัดเชิงนโยบายก่อนอนุมัติ</li>
                </ul>
                <p class="mt-3 text-xs text-amber-700">Calculation version: {{ $method }}</p>
            </section>
        </div>
    </div>
</x-app-layout>
