<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">จัดการผู้ใช้งาน</h1>
            <p class="mt-1 text-sm text-slate-500">กำหนดบทบาท ฝ่าย และสถานะการเข้าใช้งานของบุคลากร</p>
        </div>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            @if (session('success'))
                <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">{{ session('success') }}</div>
            @endif

            <form method="GET" action="{{ route('users.index') }}" class="mb-5 flex max-w-xl gap-3">
                <input name="q" value="{{ request('q') }}" class="min-w-0 flex-1 rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500" placeholder="ค้นหาชื่อ อีเมล หรือรหัสครู">
                <button class="rounded-xl bg-[#172235] px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-600">ค้นหา</button>
            </form>

            <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[780px] text-left">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-6 py-4">ผู้ใช้งาน</th>
                                <th class="px-4 py-4">รหัสครู</th>
                                <th class="px-4 py-4">บทบาท</th>
                                <th class="px-4 py-4">ฝ่าย</th>
                                <th class="px-4 py-4">สถานะ</th>
                                <th class="px-6 py-4 text-right">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($users as $user)
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-6 py-4">
                                        <p class="font-semibold text-slate-800">{{ $user->name }}</p>
                                        <p class="mt-1 text-sm text-slate-400">{{ $user->email }}</p>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-600">{{ $user->teacher_code ?? '-' }}</td>
                                    <td class="px-4 py-4 text-sm font-semibold text-slate-700">{{ $user->role?->name ?? 'ยังไม่กำหนด' }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-600">{{ $user->department?->name ?? '-' }}</td>
                                    <td class="px-4 py-4">
                                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $user->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                            {{ $user->is_active ? 'ใช้งาน' : 'ระงับใช้งาน' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <a href="{{ route('users.edit', $user) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-700">กำหนดสิทธิ์</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-6 py-14 text-center text-slate-400">ไม่พบผู้ใช้งาน</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-6">{{ $users->links() }}</div>
        </div>
    </div>
</x-app-layout>
