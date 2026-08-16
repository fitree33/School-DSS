<div
    x-show="sidebarOpen"
    x-transition.opacity
    @click="sidebarOpen = false"
    class="fixed inset-0 z-40 bg-slate-950/45 backdrop-blur-sm lg:hidden"
    style="display: none;"
></div>

<aside
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-[#172235] text-slate-200 shadow-2xl transition-transform duration-300 lg:translate-x-0"
>
    <div class="flex h-20 items-center justify-between border-b border-white/10 px-6">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-950/30">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5V9.8L12 4l8 5.8v9.7M8 20v-6h8v6M3 20h18" />
                </svg>
            </span>
            <span>
                <span class="block text-base font-bold tracking-wide text-white">School DSS</span>
                <span class="block text-[11px] uppercase tracking-[0.2em] text-slate-400">Project Center</span>
            </span>
        </a>

        <button @click="sidebarOpen = false" class="rounded-lg p-2 text-slate-400 hover:bg-white/10 hover:text-white lg:hidden" aria-label="ปิดเมนู">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
            </svg>
        </button>
    </div>

    <nav class="flex-1 space-y-2 overflow-y-auto px-4 py-6">
        <p class="mb-3 px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">เมนูหลัก</p>

        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition {{ request()->routeIs('dashboard') ? 'bg-blue-600 text-white shadow-lg shadow-blue-950/30' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7" rx="1" />
                <rect x="14" y="3" width="7" height="7" rx="1" />
                <rect x="3" y="14" width="7" height="7" rx="1" />
                <rect x="14" y="14" width="7" height="7" rx="1" />
            </svg>
            Dashboard
        </a>

        <a href="{{ route('projects.index') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition {{ request()->routeIs('projects.index', 'projects.show', 'projects.edit') ? 'bg-blue-600 text-white shadow-lg shadow-blue-950/30' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h6l2 2h8v10a2 2 0 01-2 2H4a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2" />
            </svg>
            โครงการทั้งหมด
        </a>

        <a href="{{ route('projects.search') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition {{ request()->routeIs('projects.search') ? 'bg-blue-600 text-white shadow-lg shadow-blue-950/30' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path stroke-linecap="round" d="M20 20l-3.5-3.5M11 8v6M8 11h6" />
            </svg>
            ค้นหาด้วย AI
        </a>

        @can('viewDss')
            <a href="{{ route('dss.index') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition {{ request()->routeIs('dss.*') ? 'bg-amber-400 text-slate-900 shadow-lg shadow-slate-950/20' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M4 19V9M10 19V5M16 19v-7M22 19H2" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6l6-3 6 5 6-6" />
                </svg>
                ศูนย์วิเคราะห์ DSS
            </a>
        @endcan

        <a href="{{ route('notifications.index') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition {{ request()->routeIs('notifications.*') ? 'bg-blue-600 text-white shadow-lg shadow-blue-950/30' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
            </svg>
            การแจ้งเตือน
            @if ($unreadNotificationCount > 0)
                <span class="ml-auto rounded-full bg-rose-500 px-2 py-0.5 text-[11px] font-bold text-white">{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>
            @endif
        </a>

        @can('create', App\Models\Project::class)
            <a href="{{ route('projects.create') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition {{ request()->routeIs('projects.create') ? 'bg-blue-600 text-white shadow-lg shadow-blue-950/30' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                    <circle cx="12" cy="12" r="9" />
                </svg>
                เพิ่มโครงการ
            </a>
        @endcan

        @can('manageUsers')
            <a href="{{ route('users.index') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition {{ request()->routeIs('users.*') ? 'bg-blue-600 text-white shadow-lg shadow-blue-950/30' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="9" cy="8" r="3" />
                    <circle cx="17" cy="10" r="2" />
                    <path stroke-linecap="round" d="M3 20a6 6 0 0112 0M15 16a4 4 0 016 4" />
                </svg>
                จัดการผู้ใช้งาน
            </a>
        @endcan

        <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition {{ request()->routeIs('profile.*') ? 'bg-blue-600 text-white shadow-lg shadow-blue-950/30' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="8" r="4" />
                <path stroke-linecap="round" d="M4 21a8 8 0 0116 0" />
            </svg>
            ข้อมูลส่วนตัว
        </a>
    </nav>

    <div class="border-t border-white/10 p-4">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex w-full items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-slate-300 transition hover:bg-rose-500/15 hover:text-rose-200">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 17l5-5-5-5M15 12H3M14 4h5a2 2 0 012 2v12a2 2 0 01-2 2h-5" />
                </svg>
                ออกจากระบบ
            </button>
        </form>
    </div>
</aside>

<header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 backdrop-blur lg:ml-64">
    <div class="flex h-20 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
            <button @click="sidebarOpen = true" class="rounded-xl border border-slate-200 p-2.5 text-slate-600 hover:bg-slate-50 lg:hidden" aria-label="เปิดเมนู">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <a href="{{ route('projects.search') }}" class="hidden min-w-0 items-center gap-3 rounded-xl border border-transparent px-3 py-2 text-sm text-slate-500 transition hover:border-slate-200 hover:bg-slate-50 sm:flex">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path stroke-linecap="round" d="M20 20l-3.5-3.5" />
                </svg>
                <span class="truncate">ค้นหาโครงการด้วยภาษาธรรมชาติ</span>
                <span class="hidden rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-400 md:inline">Ctrl + K</span>
            </a>
        </div>

        <div class="flex items-center gap-3 sm:gap-5">
            <a href="{{ route('notifications.index') }}" class="relative rounded-xl border border-slate-200 p-2.5 text-slate-500 hover:bg-slate-50" aria-label="การแจ้งเตือน">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
                </svg>
                @if ($unreadNotificationCount > 0)
                    <span class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">{{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}</span>
                @endif
            </a>

            <div class="hidden text-right md:block">
                <p class="text-sm font-semibold text-slate-800">ระบบบริหารโครงการ</p>
                <p class="text-xs text-slate-400">Decision Support System</p>
            </div>

            <div x-data="{ profileOpen: false }" class="relative">
                <button @click="profileOpen = ! profileOpen" class="flex items-center gap-3 rounded-xl p-1.5 pr-2 transition hover:bg-slate-50">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-400 text-sm font-bold text-slate-900">
                        {{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}
                    </span>
                    <span class="hidden max-w-36 text-left sm:block">
                        <span class="block truncate text-sm font-semibold text-slate-800">{{ Auth::user()->name }}</span>
                        <span class="block truncate text-xs text-slate-400">{{ Auth::user()->email }}</span>
                    </span>
                    <svg class="hidden h-4 w-4 text-slate-400 sm:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
                    </svg>
                </button>

                <div
                    x-show="profileOpen"
                    x-transition
                    @click.outside="profileOpen = false"
                    class="absolute right-0 mt-2 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-2 shadow-xl"
                    style="display: none;"
                >
                    <a href="{{ route('profile.edit') }}" class="block px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900">ข้อมูลส่วนตัว</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2.5 text-left text-sm text-rose-600 hover:bg-rose-50">ออกจากระบบ</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
