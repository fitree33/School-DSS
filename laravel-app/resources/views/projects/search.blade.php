<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg shadow-blue-200">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path stroke-linecap="round" d="M20 20l-3.5-3.5" />
                </svg>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">ค้นหาโครงการอัจฉริยะ</h1>
                <p class="mt-1 text-sm text-slate-500">ระบบจะแสดงเฉพาะโครงการที่คุณมีสิทธิ์เข้าถึง</p>
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-5xl">
            <form method="GET" action="{{ route('projects.search') }}" class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm sm:p-6">
                <label for="project-search" class="block text-sm font-semibold text-slate-700">พิมพ์สิ่งที่ต้องการค้นหา</label>
                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                    <div class="relative flex-1">
                        <svg class="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="11" cy="11" r="7" />
                            <path stroke-linecap="round" d="M20 20l-3.5-3.5" />
                        </svg>
                        <input
                            id="project-search"
                            name="q"
                            value="{{ $keyword }}"
                            class="w-full rounded-xl border-slate-200 py-3 pl-12 pr-4 text-slate-800 placeholder:text-slate-400 focus:border-blue-500 focus:ring-blue-500"
                            placeholder="เช่น แสดงโครงการสิ่งแวดล้อม ปีการศึกษา 2569"
                            autofocus
                        >
                    </div>
                    <button class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-6 py-3 font-semibold text-white transition hover:bg-blue-700" type="submit">ค้นหา</button>
                </div>
                @error('q')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
            </form>

            @if ($answer)
                <div class="mt-6 flex items-start gap-3 rounded-2xl border border-blue-100 bg-blue-50 px-5 py-4 text-blue-900">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3a6 6 0 00-3.7 10.7c.5.4.7.9.7 1.5V16h6v-.8c0-.6.3-1.1.7-1.5A6 6 0 0012 3zM9 20h6" />
                    </svg>
                    <div>
                        <p class="font-semibold">ผลการค้นหา</p>
                        <p class="mt-1 text-sm leading-6 text-blue-800">{{ $answer }}</p>
                    </div>
                </div>
            @endif

            @if ($keyword !== '')
                <div class="mt-6 space-y-4">
                    @forelse ($results as $index => $project)
                        <a href="{{ route('projects.show', $project) }}" class="group flex flex-col gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-100 hover:shadow-lg sm:flex-row sm:items-center">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-slate-900 text-sm font-bold text-white">{{ $index + 1 }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-lg font-bold text-slate-900 group-hover:text-blue-600">{{ $project->name }}</span>
                                <span class="mt-1 block line-clamp-2 text-sm leading-6 text-slate-500">{{ $project->objective }}</span>
                                <span class="mt-3 flex flex-wrap gap-2 text-xs font-medium">
                                    @if ($project->academic_year)
                                        <span class="rounded-full bg-blue-50 px-3 py-1 text-blue-700">ปี {{ $project->academic_year }}</span>
                                    @endif
                                    @if ($project->department_name)
                                        <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-600">{{ $project->department_name }}</span>
                                    @endif
                                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">{{ $project->match_reason }}</span>
                                </span>
                            </span>
                            <svg class="h-5 w-5 shrink-0 text-slate-300 transition group-hover:translate-x-1 group-hover:text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
                            <p class="font-semibold text-slate-700">ไม่พบโครงการที่ตรงกับคำค้น</p>
                            <p class="mt-2 text-sm text-slate-400">ลองใช้ชื่อโครงการ หัวข้อ ฝ่าย หรือปีการศึกษา</p>
                        </div>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
