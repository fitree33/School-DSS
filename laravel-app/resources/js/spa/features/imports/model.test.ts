import { describe, expect, it } from 'vitest';

import type { DocumentImport, ImportPreviewRevision, ProjectImportPreviewPayload, ProjectOptions } from '@/api/contracts';
import {
    buildConfirmImportRequest,
    buildPreviewRevisionRequest,
    canConfirmImportPreview,
    confidencePercentage,
    formatFileSize,
    importStageLabel,
    isStalePreviewRevision,
    mergeImportValidationErrors,
    normalizeImportPreviewPayload,
    previewPayloadsEqual,
    shouldPollDocumentImport,
    validateImportFile,
    validateImportPreview,
    validateImportPreviewReferences,
} from '@/features/imports/model';

const importWithStatus = (status: DocumentImport['status']): DocumentImport => ({ status } as DocumentImport);

const validPayload = (): ProjectImportPreviewPayload => ({
    name: 'โครงการทดสอบ',
    fiscal_year_id: 1,
    department_id: 2,
    school_plan_id: null,
    project_category_id: 3,
    academic_year_id: 4,
    responsible_person: null,
    monitor_person: null,
    start_date: '2026-10-01',
    end_date: '2026-10-31',
    budget: '1500.00',
    objective: 'เพื่อทดสอบระบบ',
    key_points: null,
    evaluation_method: null,
    evaluation_tools: null,
    indicators: [{ name: 'ผ่านเกณฑ์', target_value: '80', unit: 'ร้อยละ' }],
});

const revision = (overrides: Partial<ImportPreviewRevision> = {}): ImportPreviewRevision => ({
    id: 904,
    revision_no: 3,
    source: 'user',
    payload: validPayload(),
    validation_errors: {},
    warnings: [],
    confidence: {},
    edited_by: null,
    created_at: '2026-09-03T10:00:00.000Z',
    ...overrides,
});

const referenceOptions = (): ProjectOptions => ({
    departments: [{ id: 2, name: 'ฝ่ายวิชาการ' }],
    project_categories: [{ id: 3, name: 'พัฒนา' }],
    fiscal_years: [{ id: 1, year: 2570, is_locked: false }],
    academic_years: [{ id: 4, year: 2569, is_locked: true }],
    school_plans: [{ id: 5, name: 'แผนปี 2570', fiscal_year_id: 1 }],
    execution_statuses: [], evaluation_statuses: [], budget_sources: [],
});

describe('document import model', () => {
    it('polls only while an import is uploaded or processing', () => {
        expect(shouldPollDocumentImport(importWithStatus('uploaded'))).toBe(true);
        expect(shouldPollDocumentImport(importWithStatus('processing'))).toBe(true);
        expect(shouldPollDocumentImport(importWithStatus('needs_review'))).toBe(false);
        expect(shouldPollDocumentImport(importWithStatus('confirmed'))).toBe(false);
        expect(shouldPollDocumentImport(importWithStatus('failed'))).toBe(false);
    });

    it('rejects incomplete, invalid budget and reversed dates before save', () => {
        const payload = validPayload();
        payload.name = '';
        payload.budget = '-1';
        payload.end_date = '2026-09-30';

        const errors = validateImportPreview(payload);

        expect(errors.name).toBeDefined();
        expect(errors.budget).toBeDefined();
        expect(errors.end_date).toBeDefined();
    });

    it('accepts a complete candidate payload without inventing activities', () => {
        const payload = validPayload();
        expect(validateImportPreview(payload)).toEqual({});
        expect(payload).not.toHaveProperty('activities');
        expect(payload).not.toHaveProperty('sub_activities');
    });

    it('formats bounded file sizes for the upload UI', () => {
        expect(formatFileSize(1024)).toBe('1 KB');
        expect(formatFileSize(1_572_864)).toBe('1.5 MB');
    });

    it('rejects non-PDF, empty and oversized uploads before sending them', () => {
        const constraints = { max_bytes: 1024, accepted_mime_types: ['application/pdf'] };

        expect(validateImportFile(null, constraints)).toContain('เลือกไฟล์');
        expect(validateImportFile({ name: 'project.docx', size: 100, type: 'application/pdf' }, constraints)).toContain('PDF');
        expect(validateImportFile({ name: 'project.pdf', size: 100, type: 'text/plain' }, constraints)).toContain('PDF');
        expect(validateImportFile({ name: 'project.pdf', size: 0, type: 'application/pdf' }, constraints)).toContain('ว่างเปล่า');
        expect(validateImportFile({ name: 'project.pdf', size: 1025, type: 'application/pdf' }, constraints)).toContain('เกิน');
        expect(validateImportFile({ name: 'project.pdf', size: 1024, type: 'application/pdf' }, constraints)).toBeNull();
    });

    it('accepts PDF MIME aliases and missing browser MIME while leaving content validation to the server', () => {
        const constraints = { max_bytes: 1024, accepted_mime_types: ['application/pdf'] };

        expect(validateImportFile({ name: 'project.pdf', size: 100, type: '' }, constraints)).toBeNull();
        expect(validateImportFile({ name: 'project.pdf', size: 100, type: 'application/x-pdf' }, constraints)).toBeNull();
        expect(validateImportFile({ name: 'project.pdf', size: 100, type: 'TEXT/PLAIN' }, constraints)).toContain('PDF');
    });

    it('normalizes confidence values and recognizes the backend validating stage', () => {
        expect(confidencePercentage(0.875)).toBe(88);
        expect(confidencePercentage(72)).toBe(72);
        expect(confidencePercentage(120)).toBe(100);
        expect(confidencePercentage(-1)).toBeNull();
        expect(importStageLabel('validating')).toBe('กำลังตรวจสอบไฟล์');
    });

    it('detects stale revisions without changing or expanding the project payload', () => {
        const payload = validPayload();
        const cloned = { ...payload, indicators: payload.indicators.map((indicator) => ({ ...indicator })) };

        expect(previewPayloadsEqual(payload, cloned)).toBe(true);
        expect(isStalePreviewRevision(10, 10)).toBe(false);
        expect(isStalePreviewRevision(10, 11)).toBe(true);
        expect(cloned).not.toHaveProperty('activities');
        expect(cloned).not.toHaveProperty('sub_activities');
    });

    it('uses the revision row ID in save and confirm requests, never the display revision number', () => {
        const savedRevision = revision({ id: 904, revision_no: 3 });
        const saveRequest = buildPreviewRevisionRequest(savedRevision, savedRevision.payload, 'save-request-1');
        const confirmRequest = buildConfirmImportRequest(savedRevision, 'confirm-request-1');

        expect(saveRequest.base_revision_id).toBe(904);
        expect(saveRequest.base_revision_id).not.toBe(savedRevision.revision_no);
        expect(saveRequest.idempotency_key).toBe('save-request-1');
        expect(saveRequest.payload).not.toBe(savedRevision.payload);
        expect(confirmRequest).toEqual({
            preview_revision_id: 904,
            idempotency_key: 'confirm-request-1',
        });
    });

    it('reuses an explicit idempotency key for replay of the same logical request', () => {
        const savedRevision = revision();
        const first = buildConfirmImportRequest(savedRevision, 'stable-confirm-key');
        const replay = buildConfirmImportRequest(savedRevision, 'stable-confirm-key');

        expect(replay).toEqual(first);
    });

    it('allows confirm only for the unchanged, current, valid saved revision row', () => {
        const savedRevision = revision({ id: 904, revision_no: 3 });
        const base = {
            hasConfirmAbility: true,
            hasReviewAbility: true,
            draft: validPayload(),
            savedRevision,
            currentRevisionId: 904,
            isSaving: false,
            isConfirming: false,
        };

        expect(canConfirmImportPreview(base)).toBe(true);
        expect(canConfirmImportPreview({ ...base, currentRevisionId: savedRevision.revision_no })).toBe(false);
        expect(canConfirmImportPreview({ ...base, draft: { ...validPayload(), name: 'แก้ไขแต่ยังไม่บันทึก' } })).toBe(false);
        expect(canConfirmImportPreview({
            ...base,
            savedRevision: revision({ validation_errors: { fiscal_year_id: ['Fiscal year is locked.'] } }),
        })).toBe(false);
        expect(canConfirmImportPreview({
            ...base,
            confirmationValidationErrors: { department_id: ['Department is no longer allowed.'] },
        })).toBe(false);
        expect(canConfirmImportPreview({ ...base, isConfirming: true })).toBe(false);
    });

    it('merges authoritative and local validation messages without losing server-only errors', () => {
        expect(mergeImportValidationErrors(
            { fiscal_year_id: ['Fiscal year is locked.'], name: ['Required.'] },
            { name: ['Required.', 'Too long.'], budget: ['Invalid amount.'] },
        )).toEqual({
            fiscal_year_id: ['Fiscal year is locked.'],
            name: ['Required.', 'Too long.'],
            budget: ['Invalid amount.'],
        });
    });

    it('compares values independently of JSON object key order while preserving indicator order', () => {
        const payload = validPayload();
        const reordered = Object.fromEntries(Object.entries(payload).reverse());
        expect(previewPayloadsEqual(payload, reordered)).toBe(true);
        const indicators = [...payload.indicators, { name: 'จำนวนผู้เข้าร่วม', target_value: '20', unit: 'คน' }];
        expect(previewPayloadsEqual({ ...payload, indicators }, { ...payload, indicators: [...indicators].reverse() })).toBe(false);
        expect(previewPayloadsEqual(payload, { ...payload, budget: 1500 })).toBe(false);
    });

    it('allows editing malformed saved revisions without silently considering the repaired form saved', () => {
        const raw = { name: null, objective: [], budget: 42, indicators: [null, { name: 12, target_value: 3 }] };
        const payload = normalizeImportPreviewPayload(raw);
        expect(payload.name).toBe('');
        expect(payload.objective).toBe('');
        expect(payload.budget).toBe('42');
        expect(payload.indicators).toEqual([
            { name: '', target_value: null, unit: null },
            { name: '', target_value: '3', unit: null },
        ]);
        expect(validateImportPreview(payload).name).toBeDefined();
        expect(previewPayloadsEqual(payload, raw)).toBe(false);
        expect(normalizeImportPreviewPayload({ indicators: null }).indicators).toEqual([]);
        expect(raw.name).toBeNull();
    });

    it('rejects values outside schema limits and impossible calendar dates before confirmation', () => {
        const payload = validPayload();
        payload.name = 'ก'.repeat(256);
        payload.budget = '10000000000';
        payload.department_id = NaN;
        payload.start_date = '2026-02-30';
        payload.indicators = Array.from({ length: 21 }, () => ({ name: 'จำนวน', target_value: '10000000000', unit: 'ก'.repeat(51) }));
        const errors = validateImportPreview(payload);
        for (const field of ['name', 'budget', 'department_id', 'start_date', 'indicators', 'indicators.0.target_value', 'indicators.0.unit']) {
            expect(errors[field], field).toBeDefined();
        }
        expect(validateImportPreview({ ...validPayload(), start_date: '2028-02-29', end_date: '2028-03-01' })).toEqual({});
    });

    it('requires saving normalized values and rejects missing abilities, active saves and missing revisions', () => {
        const savedRevision = revision();
        const base = {
            hasConfirmAbility: true, hasReviewAbility: true, draft: savedRevision.payload,
            savedRevision, currentRevisionId: savedRevision.id, isSaving: false, isConfirming: false,
        };
        expect(canConfirmImportPreview({ ...base, hasConfirmAbility: false })).toBe(false);
        expect(canConfirmImportPreview({ ...base, hasReviewAbility: false })).toBe(false);
        expect(canConfirmImportPreview({ ...base, isSaving: true })).toBe(false);
        expect(canConfirmImportPreview({ ...base, currentRevisionId: null })).toBe(false);
        expect(canConfirmImportPreview({
            ...base, savedRevision: { ...savedRevision, raw_payload: { ...savedRevision.payload, budget: 1500 } },
        })).toBe(false);
    });

    it('blocks confirmation when the saved fiscal year has since been locked', () => {
        const options = referenceOptions();
        const savedRevision = revision();
        options.fiscal_years[0].is_locked = true;
        const errors = validateImportPreviewReferences(savedRevision.payload, options, 2);

        expect(errors.fiscal_year_id).toBeDefined();
        expect(canConfirmImportPreview({
            hasConfirmAbility: true, hasReviewAbility: true,
            draft: savedRevision.payload, savedRevision, currentRevisionId: savedRevision.id,
            isSaving: false, isConfirming: false, referenceValidationErrors: errors,
        })).toBe(false);
    });

    it('keeps academic-year lock behavior aligned with canonical project creation', () => {
        expect(validateImportPreviewReferences(validPayload(), referenceOptions(), 2)).toEqual({});
    });

    it('surfaces missing selectors, mismatched plans and department scope without mutating saved values', () => {
        const payload = { ...validPayload(), project_category_id: 99, school_plan_id: 5 };
        const options = referenceOptions();
        options.school_plans[0].fiscal_year_id = 9;
        const errors = validateImportPreviewReferences(payload, options, 7);

        expect(Object.keys(errors).sort()).toEqual(['department_id', 'project_category_id', 'school_plan_id']);
        expect(payload.department_id).toBe(2);
        expect(payload.school_plan_id).toBe(5);
        expect(validateImportPreviewReferences(validPayload(), referenceOptions()).department_id).toBeUndefined();
        expect(validateImportPreviewReferences(validPayload(), referenceOptions(), null).department_id).toBeDefined();
    });
});
