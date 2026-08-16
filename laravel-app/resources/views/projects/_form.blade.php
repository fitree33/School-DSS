@php
    $editing = isset($project) && $project;
    $kpiRows = old('kpis');

    if ($kpiRows === null) {
        $kpiRows = $editing
            ? $project->kpis->map(fn ($kpi) => [
                'id' => $kpi->id,
                'name' => $kpi->name,
                'target_value' => $kpi->target_value,
                'unit' => $kpi->unit,
            ])->values()->all()
            : [];
    }

    if ($kpiRows === []) {
        $kpiRows[] = ['id' => null, 'name' => '', 'target_value' => '', 'unit' => 'ร้อยละ'];
    }
@endphp

@if ($errors->any())
    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        กรุณาตรวจสอบข้อมูลที่กรอกอีกครั้ง
    </div>
@endif

<section class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm sm:p-8">
    <div class="border-b border-slate-100 pb-4">
        <h2 class="text-lg font-bold text-slate-900">ข้อมูลหลักของโครงการ</h2>
        <p class="mt-1 text-sm text-slate-500">ระบุข้อมูลที่ผู้บริหารต้องใช้ในการกลั่นกรองและตัดสินใจ</p>
    </div>

    <div class="mt-6 grid gap-5 sm:grid-cols-2">
        <div>
            <label for="project_code" class="text-sm font-semibold text-slate-700">รหัสโครงการ</label>
            <input id="project_code" name="project_code" value="{{ old('project_code', $project?->project_code) }}" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500" placeholder="เช่น AC-2569-001">
            @error('project_code')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="responsible_person" class="text-sm font-semibold text-slate-700">ผู้รับผิดชอบโครงการ</label>
            <input id="responsible_person" name="responsible_person" value="{{ old('responsible_person', $project?->responsible_person ?? auth()->user()->name) }}" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
        </div>
        <div class="sm:col-span-2">
            <label for="name" class="text-sm font-semibold text-slate-700">ชื่อโครงการ <span class="text-rose-500">*</span></label>
            <input id="name" name="name" value="{{ old('name', $project?->name) }}" required class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
            @error('name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div class="sm:col-span-2">
            <label for="rationale" class="text-sm font-semibold text-slate-700">หลักการและเหตุผล</label>
            <textarea id="rationale" name="rationale" rows="4" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500" placeholder="อธิบายปัญหา ความจำเป็น และบริบทของโครงการ">{{ old('rationale', $project?->rationale) }}</textarea>
        </div>
        <div class="sm:col-span-2">
            <label for="objective" class="text-sm font-semibold text-slate-700">วัตถุประสงค์ <span class="text-rose-500">*</span></label>
            <textarea id="objective" name="objective" rows="4" required class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">{{ old('objective', $project?->objective) }}</textarea>
            @error('objective')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="target_group" class="text-sm font-semibold text-slate-700">กลุ่มเป้าหมาย</label>
            <textarea id="target_group" name="target_group" rows="3" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">{{ old('target_group', $project?->target_group) }}</textarea>
        </div>
        <div>
            <label for="strategy" class="text-sm font-semibold text-slate-700">ความสอดคล้องกับกลยุทธ์</label>
            <textarea id="strategy" name="strategy" rows="3" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">{{ old('strategy', $project?->strategy) }}</textarea>
        </div>
        <div class="sm:col-span-2">
            <label for="description" class="text-sm font-semibold text-slate-700">รายละเอียดและแผนดำเนินงานเพิ่มเติม</label>
            <textarea id="description" name="description" rows="4" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">{{ old('description', $project?->description) }}</textarea>
        </div>
    </div>
</section>

<section class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm sm:p-8">
    <div class="border-b border-slate-100 pb-4">
        <h2 class="text-lg font-bold text-slate-900">หน่วยงาน ระยะเวลา และงบประมาณ</h2>
    </div>

    <div class="mt-6 grid gap-5 sm:grid-cols-2">
        <div>
            <label for="department_id" class="text-sm font-semibold text-slate-700">ฝ่าย <span class="text-rose-500">*</span></label>
            <select id="department_id" name="department_id" required class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                @foreach ($departments as $item)
                    <option value="{{ $item->id }}" @selected(old('department_id', $project?->department_id) == $item->id)>{{ $item->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="project_category_id" class="text-sm font-semibold text-slate-700">หมวดหมู่ <span class="text-rose-500">*</span></label>
            <select id="project_category_id" name="project_category_id" required class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                @foreach ($categories as $item)
                    <option value="{{ $item->id }}" @selected(old('project_category_id', $project?->project_category_id) == $item->id)>{{ $item->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="academic_year_id" class="text-sm font-semibold text-slate-700">ปีการศึกษา <span class="text-rose-500">*</span></label>
            <select id="academic_year_id" name="academic_year_id" required class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                @foreach ($years as $item)
                    <option value="{{ $item->id }}" @selected(old('academic_year_id', $project?->academic_year_id) == $item->id)>{{ $item->year }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <p class="text-sm font-semibold text-slate-700">สถานะโครงการ</p>
            <div class="mt-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700">
                {{ $editing ? ($project->status?->display_name ?? '-') : 'ร่างโครงการ' }}
            </div>
            <p class="mt-1 text-xs text-slate-400">สถานะเปลี่ยนได้ผ่าน Workflow เท่านั้น</p>
        </div>
        <div>
            <label for="start_date" class="text-sm font-semibold text-slate-700">วันที่เริ่มต้น</label>
            <input id="start_date" name="start_date" type="date" value="{{ old('start_date', $project?->start_date?->format('Y-m-d')) }}" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
        </div>
        <div>
            <label for="end_date" class="text-sm font-semibold text-slate-700">วันที่สิ้นสุด</label>
            <input id="end_date" name="end_date" type="date" value="{{ old('end_date', $project?->end_date?->format('Y-m-d')) }}" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
            @error('end_date')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="budget" class="text-sm font-semibold text-slate-700">งบประมาณ (บาท)</label>
            <input id="budget" name="budget" type="number" min="0" step="0.01" value="{{ old('budget', $project?->budget) }}" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
            @error('budget')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="budget_source" class="text-sm font-semibold text-slate-700">แหล่งงบประมาณ</label>
            <select id="budget_source" name="budget_source" class="mt-2 w-full rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                <option value="">ไม่ระบุ</option>
                @foreach ($budgetSources as $source)
                    <option value="{{ $source }}" @selected(old('budget_source', $project?->budget_source) === $source)>{{ $source }}</option>
                @endforeach
            </select>
        </div>
    </div>
</section>

<section x-data="{ rows: @js($kpiRows) }" class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm sm:p-8">
    <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-bold text-slate-900">ตัวชี้วัดความสำเร็จ (KPI)</h2>
            <p class="mt-1 text-sm text-slate-500">กำหนดค่าเป้าหมายเพื่อใช้เปรียบเทียบกับผลจริงเมื่อปิดโครงการ</p>
        </div>
        <button type="button" @click="rows.push({ id: null, name: '', target_value: '', unit: 'ร้อยละ' })" class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100">+ เพิ่มตัวชี้วัด</button>
    </div>

    <div class="mt-5 space-y-3">
        <template x-for="(row, index) in rows" :key="index">
            <div class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_140px_140px_auto]">
                <input type="hidden" :name="`kpis[${index}][id]`" x-model="row.id">
                <div>
                    <label class="text-xs font-semibold text-slate-500">ชื่อตัวชี้วัด</label>
                    <input :name="`kpis[${index}][name]`" x-model="row.name" required maxlength="255" class="mt-1 w-full rounded-xl border-slate-200" placeholder="เช่น นักเรียนผ่านเกณฑ์การประเมิน">
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-500">ค่าเป้าหมาย</label>
                    <input :name="`kpis[${index}][target_value]`" x-model="row.target_value" type="number" step="0.01" class="mt-1 w-full rounded-xl border-slate-200">
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-500">หน่วย</label>
                    <input :name="`kpis[${index}][unit]`" x-model="row.unit" maxlength="50" class="mt-1 w-full rounded-xl border-slate-200" placeholder="ร้อยละ/คน/ครั้ง">
                </div>
                <button type="button" @click="rows.splice(index, 1)" class="self-end rounded-xl p-3 text-rose-600 hover:bg-rose-50" aria-label="ลบตัวชี้วัด">ลบ</button>
            </div>
        </template>
        <p x-show="rows.length === 0" class="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-400">ยังไม่มีตัวชี้วัด กด “เพิ่มตัวชี้วัด” เพื่อเริ่มต้น</p>
    </div>
</section>
