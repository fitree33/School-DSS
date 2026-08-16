import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    createContext,
    type FormEvent,
    type ReactNode,
    useContext,
    useEffect,
    useMemo,
    useState,
} from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';

import { AUTH_EXPIRED_EVENT, ApiError, apiClient, ensureCsrfCookie, isApiError } from '@/api/client';
import type { ApiEnvelope, CurrentUser } from '@/api/contracts';
import {
    acceptAuthenticatedUser,
    authMeKey,
    clearAuthenticatedSession,
    isTerminalSessionFailure,
} from '@/auth/session';

interface LoginCredentials {
    email: string;
    password: string;
    remember: boolean;
}

interface AuthContextValue {
    user: CurrentUser | null;
    isChecking: boolean;
    checkError: unknown;
    login: (credentials: LoginCredentials) => Promise<CurrentUser>;
    logout: () => Promise<void>;
    isLoggingIn: boolean;
    isLoggingOut: boolean;
    refresh: () => Promise<unknown>;
    hasPermission: (permission: string) => boolean;
}

const AuthContext = createContext<AuthContextValue | null>(null);

const fetchCurrentUser = async (): Promise<CurrentUser> => {
    const response = await apiClient.get<ApiEnvelope<CurrentUser>>('/api/v2/me');
    return response.data.data;
};

export function AuthProvider({ children }: { children: ReactNode }) {
    const queryClient = useQueryClient();
    const meQuery = useQuery<CurrentUser | null>({
        queryKey: authMeKey,
        queryFn: fetchCurrentUser,
        retry: false,
    });

    useEffect(() => {
        const expireSession = () => {
            void clearAuthenticatedSession(queryClient);
        };
        window.addEventListener(AUTH_EXPIRED_EVENT, expireSession);
        return () => window.removeEventListener(AUTH_EXPIRED_EVENT, expireSession);
    }, [queryClient]);

    const loginMutation = useMutation({
        mutationFn: async (credentials: LoginCredentials): Promise<CurrentUser> => {
            await ensureCsrfCookie();
            const response = await apiClient.post<ApiEnvelope<CurrentUser>>('/api/v2/auth/login', credentials);
            return response.data.data;
        },
        onSuccess: (user) => acceptAuthenticatedUser(queryClient, user),
    });

    const logoutMutation = useMutation({
        mutationFn: async () => {
            try {
                await apiClient.post('/api/v2/auth/logout');
            } catch (error) {
                if (isApiError(error) && error.status === 401) {
                    return;
                }

                throw error;
            }
        },
        onSuccess: () => clearAuthenticatedSession(queryClient),
    });

    const value = useMemo<AuthContextValue>(
        () => ({
            user: meQuery.data ?? null,
            isChecking: meQuery.isPending,
            checkError: meQuery.error,
            login: loginMutation.mutateAsync,
            logout: logoutMutation.mutateAsync,
            isLoggingIn: loginMutation.isPending,
            isLoggingOut: logoutMutation.isPending,
            refresh: meQuery.refetch,
            hasPermission: (permission) => meQuery.data?.permissions.includes(permission) === true,
        }),
        [
            loginMutation.isPending,
            loginMutation.mutateAsync,
            logoutMutation.isPending,
            logoutMutation.mutateAsync,
            meQuery.data,
            meQuery.error,
            meQuery.isPending,
            meQuery.refetch,
        ],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export const useAuth = (): AuthContextValue => {
    const value = useContext(AuthContext);
    if (!value) {
        throw new Error('useAuth must be used inside AuthProvider.');
    }
    return value;
};

export function RequireAuth({ children }: { children: ReactNode }) {
    const { user, isChecking, checkError, refresh } = useAuth();
    const location = useLocation();

    if (isChecking) {
        return <FullPageLoader label="กำลังตรวจสอบสิทธิ์การใช้งาน" />;
    }

    const hasTerminalSessionFailure = isApiError(checkError)
        && isTerminalSessionFailure(checkError.status ?? undefined, checkError.code);

    if (!user && checkError && !hasTerminalSessionFailure) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
                <div className="spa-card max-w-md p-7 text-center">
                    <h1 className="text-lg font-bold text-slate-900">เชื่อมต่อระบบไม่ได้</h1>
                    <p className="mt-2 text-sm text-slate-600">
                        {checkError instanceof Error ? checkError.message : 'กรุณาลองใหม่อีกครั้ง'}
                    </p>
                    <button className="spa-button-primary mt-5" onClick={() => void refresh()} type="button">
                        ลองใหม่
                    </button>
                </div>
            </div>
        );
    }

    if (!user) {
        return <Navigate replace state={{ from: location }} to="/login" />;
    }

    return children;
}

export function RequirePermission({ permission, children }: { permission: string; children: ReactNode }) {
    const { hasPermission } = useAuth();
    return hasPermission(permission) ? children : <Navigate replace to="/forbidden" />;
}

export function LoginPage() {
    const { user, isChecking, login, isLoggingIn } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    const destination = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname ?? '/dashboard';

    if (isChecking) {
        return <FullPageLoader label="กำลังเตรียมระบบ" />;
    }

    if (user) {
        return <Navigate replace to="/dashboard" />;
    }

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setFieldErrors({});

        try {
            await login({ email, password, remember });
            navigate(destination, { replace: true });
        } catch (unknownError) {
            if (unknownError instanceof ApiError) {
                setError(unknownError.message);
                setFieldErrors(unknownError.errors);
            } else {
                setError('ไม่สามารถเข้าสู่ระบบได้ กรุณาลองใหม่');
            }
        }
    };

    return (
        <main className="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-950 px-4 py-10">
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(20,184,166,0.23),_transparent_34%),radial-gradient(circle_at_bottom_right,_rgba(14,116,144,0.18),_transparent_32%)]" />
            <div className="relative grid w-full max-w-5xl overflow-hidden rounded-3xl border border-white/10 bg-white shadow-2xl lg:grid-cols-[1.05fr_0.95fr]">
                <section className="hidden bg-gradient-to-br from-teal-800 via-teal-700 to-cyan-800 p-12 text-white lg:flex lg:flex-col lg:justify-between">
                    <Brand light />
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-[0.22em] text-teal-100">School decision support</p>
                        <h1 className="mt-4 text-4xl font-bold leading-tight">ตัดสินใจจากข้อมูลงบประมาณและโครงการที่ตรวจสอบได้</h1>
                        <p className="mt-5 max-w-lg text-base leading-7 text-teal-50/90">
                            School-DSS V2 รวมข้อมูลโครงการ การใช้งบ และสถานะการดำเนินงานไว้ในพื้นที่ทำงานเดียว
                        </p>
                    </div>
                    <p className="text-xs text-teal-100/75">ระบบสารสนเทศเพื่อสนับสนุนการบริหารสถานศึกษา</p>
                </section>

                <section className="px-6 py-9 sm:px-12 sm:py-12">
                    <div className="mb-9 lg:hidden"><Brand /></div>
                    <p className="text-sm font-semibold text-teal-700">ยินดีต้อนรับกลับมา</p>
                    <h2 className="mt-2 text-3xl font-bold tracking-tight text-slate-950">เข้าสู่ระบบ School-DSS V2</h2>
                    <p className="mt-3 text-sm leading-6 text-slate-600">ใช้บัญชีบุคลากรของโรงเรียนเพื่อเข้าสู่พื้นที่ทำงาน</p>

                    {error && (
                        <div className="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
                            {error}
                        </div>
                    )}

                    <form className="mt-7 space-y-5" onSubmit={handleSubmit}>
                        <div>
                            <label className="spa-label" htmlFor="email">อีเมล</label>
                            <input
                                autoComplete="username"
                                autoFocus
                                className="spa-input"
                                disabled={isLoggingIn}
                                id="email"
                                onChange={(event) => setEmail(event.target.value)}
                                placeholder="name@school.ac.th"
                                required
                                type="email"
                                value={email}
                            />
                            <FieldError errors={fieldErrors.email} />
                        </div>

                        <div>
                            <label className="spa-label" htmlFor="password">รหัสผ่าน</label>
                            <input
                                autoComplete="current-password"
                                className="spa-input"
                                disabled={isLoggingIn}
                                id="password"
                                onChange={(event) => setPassword(event.target.value)}
                                required
                                type="password"
                                value={password}
                            />
                            <FieldError errors={fieldErrors.password} />
                        </div>

                        <label className="flex cursor-pointer items-center gap-3 text-sm text-slate-600">
                            <input
                                checked={remember}
                                className="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                                disabled={isLoggingIn}
                                onChange={(event) => setRemember(event.target.checked)}
                                type="checkbox"
                            />
                            จดจำการเข้าสู่ระบบ
                        </label>

                        <button className="spa-button-primary w-full" disabled={isLoggingIn} type="submit">
                            {isLoggingIn ? 'กำลังเข้าสู่ระบบ…' : 'เข้าสู่ระบบ'}
                        </button>
                    </form>
                </section>
            </div>
        </main>
    );
}

function Brand({ light = false }: { light?: boolean }) {
    return (
        <div className="flex items-center gap-3">
            <div className={`grid size-11 place-items-center rounded-2xl ${light ? 'bg-white/15 text-white' : 'bg-teal-700 text-white'}`}>
                <span className="text-lg font-black">SD</span>
            </div>
            <div>
                <p className={`text-base font-bold ${light ? 'text-white' : 'text-slate-950'}`}>School-DSS</p>
                <p className={`text-xs ${light ? 'text-teal-100' : 'text-slate-500'}`}>Decision Support System</p>
            </div>
        </div>
    );
}

function FieldError({ errors }: { errors?: string[] }) {
    if (!errors?.length) return null;
    return <p className="mt-1.5 text-xs font-medium text-rose-700">{errors[0]}</p>;
}

function FullPageLoader({ label }: { label: string }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50" role="status">
            <div className="text-center">
                <div className="mx-auto size-10 animate-spin rounded-full border-4 border-slate-200 border-t-teal-700" />
                <p className="mt-4 text-sm font-medium text-slate-600">{label}</p>
            </div>
        </div>
    );
}
