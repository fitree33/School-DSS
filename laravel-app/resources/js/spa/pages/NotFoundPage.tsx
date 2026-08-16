import { Link } from 'react-router-dom';

export function NotFoundPage() {
    return <div className="spa-card mx-auto max-w-xl px-6 py-14 text-center"><p className="text-sm font-bold uppercase tracking-[0.18em] text-teal-700">404 Not found</p><h1 className="mt-3 text-2xl font-bold text-slate-950">ไม่พบหน้าที่ต้องการ</h1><p className="mt-3 text-sm leading-6 text-slate-600">ลิงก์อาจไม่ถูกต้อง หรือหน้านี้ยังไม่อยู่ในขอบเขตของ School-DSS V2 ระยะปัจจุบัน</p><Link className="spa-button-primary mt-7" to="/dashboard">กลับแดชบอร์ด</Link></div>;
}
