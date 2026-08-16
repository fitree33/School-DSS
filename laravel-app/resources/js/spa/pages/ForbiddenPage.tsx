import { Link } from 'react-router-dom';

export function ForbiddenPage() {
    return <div className="spa-card mx-auto max-w-xl px-6 py-14 text-center"><p className="text-sm font-bold uppercase tracking-[0.18em] text-rose-600">403 Forbidden</p><h1 className="mt-3 text-2xl font-bold text-slate-950">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h1><p className="mt-3 text-sm leading-6 text-slate-600">สิทธิ์บนหน้าจอช่วยนำทางเท่านั้น การอนุญาตจริงถูกตรวจสอบโดยเซิร์ฟเวอร์ทุกครั้ง</p><Link className="spa-button-primary mt-7" to="/dashboard">กลับแดชบอร์ด</Link></div>;
}
