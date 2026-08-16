import { PageHeader } from '@/components/PageHeader';

const metrics = [
    ['งบประมาณรวม', 'รอเชื่อมต่อ Dashboard API'],
    ['ใช้ไปแล้ว', 'รอเชื่อมต่อ Dashboard API'],
    ['งบคงเหลือ', 'รอเชื่อมต่อ Dashboard API'],
    ['เปอร์เซ็นต์การใช้', 'รอเชื่อมต่อ Dashboard API'],
];

export function DashboardPage() {
    return (
        <div className="space-y-7">
            <PageHeader description="พื้นที่สรุปงบประมาณรวมและงบแต่ละฝ่าย — ระยะนี้วางเฉพาะโครงหน้าจอโดยยังไม่เรียก Dashboard API" eyebrow="Overview" title="แดชบอร์ด" />
            <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {metrics.map(([label, note]) => <article className="spa-card p-5" key={label}><p className="text-sm font-semibold text-slate-600">{label}</p><p className="mt-3 text-3xl font-bold text-slate-300">—</p><p className="mt-3 text-xs text-slate-400">{note}</p></article>)}
            </section>
            <section className="spa-card overflow-hidden">
                <div className="border-b border-slate-100 px-5 py-4 sm:px-6"><h2 className="font-bold text-slate-900">งบประมาณรายฝ่าย</h2><p className="mt-1 text-sm text-slate-500">โครงสร้างพร้อมรับงบตั้งต้น ใช้ไป คงเหลือ และเปอร์เซ็นต์จาก API ในระยะถัดไป</p></div>
                <div className="grid min-h-64 place-items-center bg-gradient-to-br from-white to-slate-50 p-8 text-center"><div><div className="mx-auto grid size-14 place-items-center rounded-2xl bg-teal-50 text-2xl text-teal-700">%</div><p className="mt-4 font-semibold text-slate-700">Dashboard API contract placeholder</p><p className="mt-2 max-w-md text-sm leading-6 text-slate-500">ไม่มีข้อมูลจำลอง กราฟ หรือการคำนวณฝั่งหน้าเว็บ เพื่อหลีกเลี่ยงตัวเลขที่ไม่ตรงกับแหล่งข้อมูลจริง</p></div></div>
            </section>
        </div>
    );
}
