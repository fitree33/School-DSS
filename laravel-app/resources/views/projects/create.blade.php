<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-semibold text-amber-600">Project Proposal</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-900">ร่างโครงการใหม่</h1>
            <p class="mt-1 text-sm text-slate-500">บันทึกเป็นร่างก่อน แล้วจึงส่งให้รองผู้อำนวยการกลั่นกรองตามลำดับ</p>
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('projects.store') }}" class="mx-auto max-w-5xl space-y-6">
            @csrf
            @include('projects._form', ['project' => null])

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <a href="{{ route('projects.index') }}" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-center text-sm font-semibold text-slate-600 hover:bg-slate-50">ยกเลิก</a>
                <button class="rounded-xl bg-[#172235] px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-200 hover:bg-blue-700" type="submit">บันทึกร่างโครงการ</button>
            </div>
        </form>
    </div>
</x-app-layout>
