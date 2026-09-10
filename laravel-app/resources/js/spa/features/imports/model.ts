import type {
    ConfirmDocumentImportPayload,
    CreateImportPreviewRevisionPayload,
    DocumentImport,
    DocumentImportStatus,
    ImportPreviewRevision,
    ImportWarning,
    ProjectImportPreviewPayload,
    ProjectOptions,
} from '@/api/contracts';

export const importStatusMeta: Record<DocumentImportStatus, { label: string; description: string }> = {
    uploaded: {
        label: 'อัปโหลดแล้ว',
        description: 'ระบบรับไฟล์ต้นฉบับแล้วและกำลังรอเริ่มประมวลผล',
    },
    processing: {
        label: 'กำลังประมวลผล',
        description: 'ระบบกำลังอ่านข้อความและจัดโครงสร้างข้อมูลด้วย AI',
    },
    needs_review: {
        label: 'รอตรวจสอบ',
        description: 'ข้อมูลจาก AI ยังไม่ผ่านการยืนยัน กรุณาตรวจและแก้ไขก่อนสร้างโครงการ',
    },
    confirmed: {
        label: 'ยืนยันแล้ว',
        description: 'ผู้ใช้ยืนยันข้อมูลและระบบสร้างโครงการเรียบร้อยแล้ว',
    },
    failed: {
        label: 'ประมวลผลไม่สำเร็จ',
        description: 'ระบบเก็บไฟล์ต้นฉบับไว้แล้ว แต่ขั้นตอนประมวลผลไม่สำเร็จ',
    },
};

const stageLabels: Record<string, string> = {
    queued: 'รอคิวประมวลผล',
    validating: 'กำลังตรวจสอบไฟล์',
    extracting_text: 'กำลังอ่านข้อความจาก PDF',
    waiting_ai: 'กำลังรอผลจาก AI',
    building_preview: 'กำลังจัดเตรียมหน้าตรวจสอบ',
};

export interface ImportFileLike {
    name: string;
    size: number;
    type: string;
}

export interface ImportFileConstraints {
    max_bytes: number;
    accepted_mime_types: string[];
}

export const validateImportFile = (
    file: ImportFileLike | null,
    constraints?: ImportFileConstraints,
): string | null => {
    if (!file) return 'กรุณาเลือกไฟล์ PDF';

    const acceptedMimeTypes = (constraints?.accepted_mime_types ?? ['application/pdf'])
        .map((mimeType) => mimeType.toLocaleLowerCase());
    const browserMimeType = file.type.trim().toLocaleLowerCase();
    const hasPdfExtension = file.name.toLocaleLowerCase().endsWith('.pdf');
    const hasAcceptedMimeType = browserMimeType === ''
        || acceptedMimeTypes.includes(browserMimeType)
        || (acceptedMimeTypes.includes('application/pdf') && browserMimeType === 'application/x-pdf')
        || (acceptedMimeTypes.includes('application/x-pdf') && browserMimeType === 'application/pdf');

    if (!hasPdfExtension || !hasAcceptedMimeType) {
        return 'รองรับเฉพาะไฟล์ PDF เท่านั้น';
    }

    if (file.size <= 0) return 'ไฟล์ PDF ว่างเปล่า';
    if (constraints && file.size > constraints.max_bytes) {
        return `ไฟล์มีขนาดเกิน ${formatFileSize(constraints.max_bytes)}`;
    }

    return null;
};

export const importStageLabel = (stage: string | null): string | null => (
    stage ? stageLabels[stage] ?? stage.replaceAll('_', ' ') : null
);

export const shouldPollDocumentImport = (documentImport?: DocumentImport): boolean => (
    documentImport?.status === 'uploaded' || documentImport?.status === 'processing'
);

export const formatFileSize = (bytes: number): string => {
    if (!Number.isFinite(bytes) || bytes < 0) return '—';
    if (bytes < 1024) return `${bytes.toLocaleString('th-TH')} ไบต์`;

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    return `${value.toLocaleString('th-TH', { maximumFractionDigits: 1 })} ${units[unitIndex]}`;
};

export const formatImportDateTime = (value: string | null | undefined): string => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('th-TH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
};

export const warningMessage = (warning: ImportWarning): string => (
    typeof warning === 'string' ? warning : warning.message
);

export const emptyImportPreviewPayload = (): ProjectImportPreviewPayload => ({
    name: '',
    fiscal_year_id: null,
    department_id: null,
    school_plan_id: null,
    project_category_id: null,
    academic_year_id: null,
    responsible_person: null,
    monitor_person: null,
    start_date: null,
    end_date: null,
    budget: '',
    objective: '',
    key_points: null,
    evaluation_method: null,
    evaluation_tools: null,
    indicators: [],
});

// Saved revisions may contain incomplete or invalid values for a reviewer to fix.
export const normalizeImportPreviewPayload = (value: unknown): ProjectImportPreviewPayload => {
    const source = value && typeof value === 'object' && !Array.isArray(value)
        ? value as Record<string, unknown>
        : {};
    const text = (field: string): string | null => typeof source[field] === 'string' ? source[field] : null;
    const identifier = (field: string): number | null => {
        const candidate = source[field];
        return typeof candidate === 'number' && Number.isSafeInteger(candidate) && candidate > 0 ? candidate : null;
    };
    const decimal = (candidate: unknown): string | null => typeof candidate === 'string'
        ? candidate
        : typeof candidate === 'number' && Number.isFinite(candidate) ? String(candidate) : null;

    return {
        name: text('name') ?? '',
        fiscal_year_id: identifier('fiscal_year_id'),
        department_id: identifier('department_id'),
        school_plan_id: identifier('school_plan_id'),
        project_category_id: identifier('project_category_id'),
        academic_year_id: identifier('academic_year_id'),
        responsible_person: text('responsible_person'),
        monitor_person: text('monitor_person'),
        start_date: text('start_date'),
        end_date: text('end_date'),
        budget: decimal(source.budget) ?? '',
        objective: text('objective') ?? '',
        key_points: text('key_points'),
        evaluation_method: text('evaluation_method'),
        evaluation_tools: text('evaluation_tools'),
        indicators: Array.isArray(source.indicators) ? source.indicators.map((value: unknown) => {
            const indicator = value && typeof value === 'object' ? value as Record<string, unknown> : {};
            return {
                name: typeof indicator.name === 'string' ? indicator.name : '',
                target_value: decimal(indicator.target_value),
                unit: typeof indicator.unit === 'string' ? indicator.unit : null,
            };
        }) : [],
    };
};

export const cloneImportPreviewPayload = (payload: ProjectImportPreviewPayload): ProjectImportPreviewPayload => ({
    ...payload,
    indicators: payload.indicators.map((indicator) => ({ ...indicator })),
});

export const buildPreviewRevisionRequest = (
    baseRevision: Pick<ImportPreviewRevision, 'id'>,
    payload: ProjectImportPreviewPayload,
    idempotencyKey: string,
): CreateImportPreviewRevisionPayload => ({
    base_revision_id: baseRevision.id,
    payload: cloneImportPreviewPayload(payload),
    idempotency_key: idempotencyKey,
});

export const buildConfirmImportRequest = (
    revision: Pick<ImportPreviewRevision, 'id'>,
    idempotencyKey: string,
): ConfirmDocumentImportPayload => ({
    preview_revision_id: revision.id,
    idempotency_key: idempotencyKey,
});

export const validateImportPreview = (payload: ProjectImportPreviewPayload): Record<string, string[]> => {
    const errors: Record<string, string[]> = {};
    const requireText = (field: keyof ProjectImportPreviewPayload, label: string) => {
        const value = payload[field];
        if (typeof value !== 'string' || !value.trim()) errors[field] = [`กรุณาระบุ${label}`];
    };
    const requireId = (field: keyof ProjectImportPreviewPayload, label: string) => {
        const value = payload[field];
        if (typeof value !== 'number' || !Number.isSafeInteger(value) || value <= 0) errors[field] = [`กรุณาเลือก${label}`];
    };

    requireText('name', 'ชื่อโครงการ');
    requireText('objective', 'วัตถุประสงค์');
    requireId('fiscal_year_id', 'ปีงบประมาณ');
    requireId('department_id', 'ฝ่าย/กลุ่มงาน');
    requireId('project_category_id', 'ประเภทโครงการ');
    requireId('academic_year_id', 'ปีการศึกษา');

    const budget = payload.budget.trim();
    if (!budget) {
        errors.budget = ['กรุณาระบุงบประมาณรวม'];
    } else if (!/^\d+(?:\.\d{1,2})?$/.test(budget) || Number(budget) > 9_999_999_999.99) {
        errors.budget = ['งบประมาณต้องอยู่ระหว่าง 0 ถึง 9,999,999,999.99 และมีทศนิยมไม่เกิน 2 ตำแหน่ง'];
    }

    const textLimits = {
        name: 255, objective: 16000, responsible_person: 255, monitor_person: 255,
        evaluation_method: 16000, evaluation_tools: 16000,
    } as const;
    Object.entries(textLimits).forEach(([field, limit]) => {
        const value = payload[field as keyof typeof textLimits];
        if (value && [...value].length > limit) errors[field] = [`ระบุได้ไม่เกิน ${limit.toLocaleString('th-TH')} ตัวอักษร`];
    });

    (['start_date', 'end_date'] as const).forEach((field) => {
        const value = payload[field];
        if (!value) return;
        const date = new Date(`${value}T00:00:00Z`);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== value) {
            errors[field] = ['กรุณาระบุวันที่ที่ถูกต้อง'];
        }
    });

    if (payload.start_date && payload.end_date && payload.end_date < payload.start_date) {
        errors.end_date = ['วันที่สิ้นสุดต้องไม่อยู่ก่อนวันที่เริ่มต้น'];
    }

    if (payload.indicators.length > 20) errors.indicators = ['ระบุตัวชี้วัดได้สูงสุด 20 ตัว'];

    payload.indicators.forEach((indicator, index) => {
        if (!indicator.name.trim()) {
            errors[`indicators.${index}.name`] = ['กรุณาระบุชื่อตัวชี้วัด'];
        } else if ([...indicator.name].length > 255) {
            errors[`indicators.${index}.name`] = ['ชื่อตัวชี้วัดต้องไม่เกิน 255 ตัวอักษร'];
        }

        const target = indicator.target_value?.trim();
        if (target && (!/^\d+(?:\.\d{1,2})?$/.test(target) || Number(target) > 9_999_999_999.99)) {
            errors[`indicators.${index}.target_value`] = ['ค่าเป้าหมายต้องอยู่ระหว่าง 0 ถึง 9,999,999,999.99 และมีทศนิยมไม่เกิน 2 ตำแหน่ง'];
        }
        if (indicator.unit && [...indicator.unit].length > 50) errors[`indicators.${index}.unit`] = ['หน่วยต้องไม่เกิน 50 ตัวอักษร'];
    });

    return errors;
};

export const hasFieldErrors = (errors: Record<string, string[]>): boolean => (
    Object.values(errors).some((messages) => messages.length > 0)
);

export const validateImportPreviewReferences = (
    payload: ProjectImportPreviewPayload,
    options: ProjectOptions,
    allowedDepartmentId?: number | null,
): Record<string, string[]> => {
    const errors: Record<string, string[]> = {};
    const selectors = {
        department_id: options.departments,
        project_category_id: options.project_categories,
        academic_year_id: options.academic_years,
        fiscal_year_id: options.fiscal_years,
        school_plan_id: options.school_plans,
    } as const;

    Object.entries(selectors).forEach(([field, choices]) => {
        const selectedId = payload[field as keyof typeof selectors];
        if (selectedId !== null && !choices.some((choice) => choice.id === selectedId)) {
            errors[field] = ['ค่าที่บันทึกไว้ไม่มีในรายการปัจจุบัน กรุณาเลือกใหม่'];
        }
    });

    if (options.fiscal_years.some((year) => year.id === payload.fiscal_year_id && year.is_locked)) {
        errors.fiscal_year_id = ['ปีงบประมาณนี้ปิดแล้ว กรุณาเลือกปีที่เปิดใช้งานก่อนยืนยัน'];
    }
    const plan = options.school_plans.find((item) => item.id === payload.school_plan_id);
    if (plan && plan.fiscal_year_id !== payload.fiscal_year_id) {
        errors.school_plan_id = ['แผนโรงเรียนไม่อยู่ในปีงบประมาณที่เลือก กรุณาเลือกใหม่'];
    }
    if (allowedDepartmentId !== undefined && payload.department_id !== null && payload.department_id !== allowedDepartmentId) {
        errors.department_id = ['บัญชีนี้สร้างโครงการได้เฉพาะฝ่าย/กลุ่มงานของตนเอง กรุณาเลือกใหม่'];
    }

    return errors;
};

export const mergeImportValidationErrors = (
    ...sources: Array<Record<string, string[]> | null | undefined>
): Record<string, string[]> => {
    const merged: Record<string, string[]> = {};

    sources.forEach((source) => {
        Object.entries(source ?? {}).forEach(([field, messages]) => {
            if (!messages.length) return;
            merged[field] = [...new Set([...(merged[field] ?? []), ...messages])];
        });
    });

    return merged;
};

export const confidencePercentage = (value: number | null | undefined): number | null => {
    if (value === null || value === undefined || !Number.isFinite(value) || value < 0) return null;

    const percentage = value <= 1 ? value * 100 : value;
    return Math.min(100, Math.round(percentage));
};

const canonicalizePayload = (value: unknown): unknown => {
    if (Array.isArray(value)) return value.map(canonicalizePayload);
    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value).sort(([left], [right]) => left.localeCompare(right))
            .map(([key, item]) => [key, canonicalizePayload(item)]));
    }
    return value;
};

export const previewPayloadsEqual = (left: unknown, right: unknown): boolean => (
    JSON.stringify(canonicalizePayload(left)) === JSON.stringify(canonicalizePayload(right))
);

export const isStalePreviewRevision = (
    baseRevisionId: number | null,
    currentRevisionId: number | null | undefined,
): boolean => baseRevisionId !== null && currentRevisionId != null && baseRevisionId !== currentRevisionId;

export const canConfirmImportPreview = ({
    hasConfirmAbility,
    hasReviewAbility,
    draft,
    savedRevision,
    currentRevisionId,
    confirmationValidationErrors,
    referenceValidationErrors,
    isSaving,
    isConfirming,
}: {
    hasConfirmAbility: boolean;
    hasReviewAbility: boolean;
    draft: ProjectImportPreviewPayload;
    savedRevision: Pick<ImportPreviewRevision, 'id' | 'payload' | 'raw_payload' | 'validation_errors'>;
    currentRevisionId: number | null | undefined;
    confirmationValidationErrors?: Record<string, string[]>;
    referenceValidationErrors?: Record<string, string[]>;
    isSaving: boolean;
    isConfirming: boolean;
}): boolean => (
    hasConfirmAbility
    && hasReviewAbility
    && !isSaving
    && !isConfirming
    && currentRevisionId != null
    && !isStalePreviewRevision(savedRevision.id, currentRevisionId)
    && previewPayloadsEqual(draft, savedRevision.raw_payload ?? savedRevision.payload)
    && !hasFieldErrors(validateImportPreview(draft))
    && !hasFieldErrors(savedRevision.validation_errors)
    && !hasFieldErrors(confirmationValidationErrors ?? {})
    && !hasFieldErrors(referenceValidationErrors ?? {})
);

export const makeIdempotencyKey = (): string => {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return `import-${Date.now()}-${Math.random().toString(16).slice(2)}`;
};
