<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-blue-600">แบบประเมิน DSS</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-900">{{ $project->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">ให้คะแนนตามหลักฐานของโครงการ ระบบจะคำนวณคะแนนถ่วงน้ำหนักให้โดยอัตโนมัติ</p>
            </div>
            <a href="{{ route('projects.show', $project) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">กลับหน้ารายละเอียด</a>
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
            <form method="POST" action="{{ route('projects.evaluations.store', $project) }}" class="space-y-5 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm sm:p-8">
                @csrf
                <input type="hidden" name="round" value="{{ $round }}">

                @if ($errors->any())
                    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        กรุณาให้คะแนนทุกเกณฑ์ภายในช่วงที่กำหนด
                    </div>
                @endif

                <div class="flex items-center justify-between border-b border-slate-100 pb-5">
                    <div>
                        <h2 class="font-bold text-slate-900">เกณฑ์การประเมิน</h2>
                        <p class="mt-1 text-sm text-slate-500">รอบการประเมินที่ {{ $round }}</p>
                    </div>
                    <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">น้ำหนักรวม {{ number_format($criteria->sum('weight'), 0) }}%</span>
                </div>

                @foreach ($criteria as $criterion)
                    @php
                        $savedScore = $evaluation?->scores->firstWhere('criteria_id', $criterion->id)?->score;
                    @endphp
                    <section class="rounded-xl border border-slate-200 p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <label for="score-{{ $criterion->id }}" class="font-semibold text-slate-800">{{ $loop->iteration }}. {{ $criterion->name }}</label>
                                <p class="mt-1 text-sm leading-6 text-slate-500">{{ $criterion->description }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">น้ำหนัก {{ number_format($criterion->weight, 0) }}%</span>
                        </div>
                        <div class="mt-4 flex items-center gap-3">
                            <input
                                id="score-{{ $criterion->id }}"
                                name="scores[{{ $criterion->id }}]"
                                type="number"
                                min="0"
                                max="{{ $criterion->max_score }}"
                                step="0.1"
                                required
                                value="{{ old('scores.'.$criterion->id, $savedScore) }}"
                                class="w-32 rounded-xl border-slate-200 text-lg font-bold focus:border-blue-500 focus:ring-blue-500"
                            >
                            <span class="text-sm text-slate-500">จาก {{ number_format($criterion->max_score, 0) }} คะแนน</span>
                        </div>
                        @error('scores.'.$criterion->id)<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                    </section>
                @endforeach

                <div>
                    <label for="comment" class="text-sm font-semibold text-slate-700">ความคิดเห็นของผู้ประเมิน</label>
                    <textarea id="comment" name="comment" rows="4" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500" placeholder="ระบุเหตุผล จุดเด่น หรือสิ่งที่ควรปรับปรุง">{{ old('comment', $evaluation?->comment) }}</textarea>
                </div>

                <div class="flex justify-end border-t border-slate-100 pt-5">
                    <button type="submit" class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-200 hover:bg-blue-700">บันทึกและคำนวณผล DSS</button>
                </div>
            </form>

            <aside class="space-y-5">
                <div class="rounded-2xl bg-[#172235] p-6 text-white shadow-sm">
                    <p class="text-sm text-slate-300">ผล DSS ล่าสุด</p>
                    @if ($project->latestDssResult)
                        <p class="mt-3 text-4xl font-bold">{{ number_format($project->latestDssResult->total_score, 2) }}</p>
                        <p class="text-sm text-slate-300">จาก 100 คะแนน</p>
                        <p class="mt-4 rounded-xl bg-white/10 px-3 py-2 text-sm font-semibold">{{ $project->latestDssResult->recommendation }}</p>
                    @else
                        <p class="mt-3 text-sm leading-6 text-slate-300">ยังไม่มีผลประเมิน บันทึกแบบประเมินนี้เพื่อสร้างผลลัพธ์ครั้งแรก</p>
                    @endif
                </div>

                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                    <h2 class="font-bold text-amber-900">หลักการใช้งาน</h2>
                    <p class="mt-2 text-sm leading-6 text-amber-800">คะแนน DSS เป็นข้อมูลประกอบการตัดสินใจ ผู้อนุมัติควรตรวจเอกสาร งบประมาณ และข้อคิดเห็นร่วมด้วยทุกครั้ง</p>
                </div>
            </aside>
        </div>
    </div>
</x-app-layout>
