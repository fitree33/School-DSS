import { useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';

import { isApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { BudgetIcon, CloseIcon, DashboardIcon, EvaluationIcon, LogoutIcon, MenuIcon, ProjectsIcon } from '@/components/Icons';

const navigation = [
    { label: 'แดชบอร์ด', to: '/dashboard', icon: DashboardIcon },
    { label: 'โครงการทั้งหมด', to: '/projects', icon: ProjectsIcon },
    { label: 'ประเมินโครงการ', to: '/evaluations', icon: EvaluationIcon, permission: 'evaluations.view' },
    { label: 'จัดการงบประมาณ', to: '/budgets', icon: BudgetIcon, permission: 'budgets.manage' },
];

export function AppLayout() {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [logoutError, setLogoutError] = useState<string | null>(null);
    const { user, logout, isLoggingOut, hasPermission } = useAuth();
    const location = useLocation();
    const navigate = useNavigate();

    const handleLogout = async () => {
        setLogoutError(null);

        try {
            await logout();
            navigate('/login', { replace: true });
        } catch (error) {
            setLogoutError(
                isApiError(error)
                    ? error.message
                    : 'ไม่สามารถออกจากระบบได้ กรุณาลองใหม่อีกครั้ง',
            );
        }
    };

    return (
        <div className="min-h-screen bg-slate-50">
            {sidebarOpen && (
                <button aria-label="ปิดเมนู" className="fixed inset-0 z-40 bg-slate-950/45 backdrop-blur-sm lg:hidden" onClick={() => setSidebarOpen(false)} type="button" />
            )}

            <aside className={`fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-slate-950 text-white shadow-2xl transition-transform duration-200 lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="flex h-20 items-center justify-between border-b border-white/10 px-6">
                    <NavLink className="flex items-center gap-3" onClick={() => setSidebarOpen(false)} to="/dashboard">
                        <div className="grid size-10 place-items-center rounded-xl bg-teal-600 text-sm font-black shadow-lg shadow-teal-950/30">SD</div>
                        <div>
                            <p className="font-bold tracking-wide">School-DSS V2</p>
                            <p className="text-xs text-slate-400">Decision Support System</p>
                        </div>
                    </NavLink>
                    <button aria-label="ปิดแถบเมนู" className="rounded-lg p-2 text-slate-300 hover:bg-white/10 lg:hidden" onClick={() => setSidebarOpen(false)} type="button"><CloseIcon className="size-5" /></button>
                </div>

                <nav aria-label="เมนูหลัก" className="flex-1 space-y-1 px-4 py-6">
                    <p className="mb-3 px-3 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">พื้นที่ทำงาน</p>
                    {navigation.filter(({ permission }) => !permission || hasPermission(permission)).map(({ label, to, icon: Icon }) => (
                        <NavLink
                            className={({ isActive }) => `flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold transition ${isActive ? 'bg-teal-600 text-white shadow-lg shadow-teal-950/30' : 'text-slate-300 hover:bg-white/10 hover:text-white'}`}
                            end={to === '/dashboard'}
                            key={to}
                            onClick={() => setSidebarOpen(false)}
                            to={to}
                        >
                            <Icon className="size-5" />
                            {label}
                        </NavLink>
                    ))}
                </nav>

                <div className="border-t border-white/10 p-4">
                    <div className="rounded-2xl bg-white/5 p-3">
                        <p className="truncate text-sm font-semibold text-white">{user?.name}</p>
                        <p className="mt-0.5 truncate text-xs text-slate-400">{user?.role?.name ?? 'ผู้ใช้งาน'}{user?.department ? ` · ${user.department.name}` : ''}</p>
                        <button className="mt-3 flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs font-semibold text-slate-300 transition hover:bg-white/10 hover:text-white disabled:opacity-60" disabled={isLoggingOut} onClick={() => void handleLogout()} type="button">
                            <LogoutIcon className="size-4" />
                            {isLoggingOut ? 'กำลังออกจากระบบ…' : 'ออกจากระบบ'}
                        </button>
                        {logoutError && <p className="mt-2 text-xs leading-5 text-rose-300" role="alert">{logoutError}</p>}
                    </div>
                </div>
            </aside>

            <div className="min-h-screen lg:pl-72">
                <header className="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
                    <div className="flex h-20 items-center justify-between px-4 sm:px-6 lg:px-8">
                        <div className="flex items-center gap-3">
                            <button aria-label="เปิดแถบเมนู" className="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-700 shadow-sm lg:hidden" onClick={() => setSidebarOpen(true)} type="button"><MenuIcon className="size-5" /></button>
                            <div>
                                <p className="text-xs font-medium text-slate-500">School-DSS V2</p>
                                <p className="text-sm font-bold text-slate-900">{pageTitle(location.pathname)}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <div className="hidden text-right sm:block">
                                <p className="text-sm font-semibold text-slate-900">{user?.name}</p>
                                <p className="text-xs text-slate-500">{user?.role?.name ?? user?.email}</p>
                            </div>
                            <div className="grid size-10 place-items-center rounded-full bg-teal-100 text-sm font-bold text-teal-800 ring-4 ring-white">{initials(user?.name ?? '')}</div>
                        </div>
                    </div>
                </header>

                <main className="mx-auto w-full max-w-[1500px] px-4 py-7 sm:px-6 lg:px-8 lg:py-9">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}

const initials = (name: string) => name.trim().split(/\s+/).slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase() || 'U';

const pageTitle = (pathname: string) => {
    if (pathname === '/dashboard') return 'แดชบอร์ด';
    if (pathname.startsWith('/budgets')) return 'จัดการงบประมาณ';
    if (pathname.startsWith('/evaluations/frameworks')) return 'ชุดเกณฑ์ประเมิน';
    if (pathname.startsWith('/evaluations')) return 'ประเมินโครงการ';
    if (pathname.includes('/new')) return 'สร้างโครงการ';
    if (pathname.includes('/edit')) return 'แก้ไขโครงการ';
    if (pathname.startsWith('/projects/')) return 'รายละเอียดโครงการ';
    if (pathname.startsWith('/projects')) return 'โครงการทั้งหมด';
    return 'School-DSS';
};
