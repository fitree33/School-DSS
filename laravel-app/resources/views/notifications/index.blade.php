<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-bold text-slate-900">การแจ้งเตือน</h1>
    </x-slot>

    <div class="px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h2 class="font-bold text-slate-900">งานและผลการพิจารณา</h2>
                    <p class="mt-1 text-sm text-slate-500">คลิกรายการเพื่อเปิดโครงการและทำเครื่องหมายว่าอ่านแล้ว</p>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse ($notifications as $notification)
                        <form method="POST" action="{{ route('notifications.read', $notification) }}">
                            @csrf
                            <button type="submit" class="flex w-full items-start gap-4 px-6 py-5 text-left transition hover:bg-slate-50 {{ $notification->read_at ? '' : 'bg-blue-50/50' }}">
                                <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $notification->read_at ? 'bg-slate-200' : 'bg-blue-600' }}"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-slate-800">{{ $notification->title }}</span>
                                    <span class="mt-1 block text-sm leading-6 text-slate-500">{{ $notification->message }}</span>
                                    <span class="mt-2 block text-xs text-slate-400">{{ $notification->created_at->format('d/m/Y H:i') }}</span>
                                </span>
                                <span class="text-slate-400">›</span>
                            </button>
                        </form>
                    @empty
                        <div class="px-6 py-16 text-center text-sm text-slate-400">ยังไม่มีการแจ้งเตือน</div>
                    @endforelse
                </div>
            </div>

            <div class="mt-6">{{ $notifications->links() }}</div>
        </div>
    </div>
</x-app-layout>
