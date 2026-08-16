<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-amber-600">Project Proposal</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-900">แก้ไขโครงการ</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $project->name }}</p>
            </div>
            <a href="{{ route('projects.show', $project) }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-center text-sm font-semibold text-slate-600 hover:bg-slate-50">กลับหน้ารายละเอียด</a>
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('projects.update', $project) }}" class="mx-auto max-w-5xl space-y-6">
            @csrf
            @method('PUT')
            @include('projects._form', ['project' => $project])

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <a href="{{ route('projects.show', $project) }}" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-center text-sm font-semibold text-slate-600 hover:bg-slate-50">ยกเลิก</a>
                <button class="rounded-xl bg-blue-700 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-800" type="submit">บันทึกการแก้ไข</button>
            </div>
        </form>

        @can('delete', $project)
            <div class="mx-auto mt-6 max-w-5xl rounded-2xl border border-rose-200 bg-rose-50 p-6">
                <h2 class="font-bold text-rose-900">ลบโครงการ</h2>
                <p class="mt-1 text-sm text-rose-700">โครงการจะถูกย้ายออกจากรายการหลักและสามารถกู้คืนได้ภายหลัง</p>
                <form class="mt-4" method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm('ยืนยันการลบโครงการนี้หรือไม่?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-700">ลบโครงการ</button>
                </form>
            </div>
        @endcan
    </div>
</x-app-layout>
