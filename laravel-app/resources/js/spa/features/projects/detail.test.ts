import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AxiosError, type InternalAxiosRequestConfig } from 'axios';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { apiClient } from '@/api/client';
import type { Project } from '@/api/contracts';
import { projectKeys } from '@/features/projects/api';
import { ProjectDetailPage } from '@/features/projects/ProjectDetailPage';

// Capture the rendered button's real callback; keep React Query and the API client intact.
const downloadAction = vi.hoisted(() => ({ click: null as (() => Promise<void>) | null }));
vi.mock('react/jsx-runtime', async (importOriginal) => {
    const runtime = await importOriginal<typeof import('react/jsx-runtime')>();
    const jsx: typeof runtime.jsx = (type, props, key) => {
        const button = props as { children?: unknown; onClick?: () => Promise<void> };
        if (type === 'button' && button.children === 'เปิด / ดาวน์โหลด' && button.onClick) downloadAction.click = button.onClick;
        return runtime.jsx(type, props, key);
    };
    return { ...runtime, jsx };
});
vi.mock('react/jsx-dev-runtime', async (importOriginal) => {
    const runtime = await importOriginal<typeof import('react/jsx-dev-runtime')>();
    const jsxDEV: typeof runtime.jsxDEV = (type, props, key, isStaticChildren, source, self) => {
        const button = props as { children?: unknown; onClick?: () => Promise<void> };
        if (type === 'button' && button.children === 'เปิด / ดาวน์โหลด' && button.onClick) downloadAction.click = button.onClick;
        return runtime.jsxDEV(type, props, key, isStaticChildren, source, self);
    };
    return { ...runtime, jsxDEV };
});

vi.mock('@/auth/AuthContext', () => ({
    useAuth: () => ({ hasPermission: () => false }),
}));

const project = (overrides: Partial<Project> = {}): Project => ({
    id: 71, name: 'Phase 5 QA imported project', project_code: null, objective: 'Imported objective',
    description: null, rationale: null, target_group: null, strategy: null, key_points: null,
    budget: '1500.00', actual_spent: '0.00', budget_source: null, responsible_person: null,
    monitor_person: null, evaluation_method: null, evaluation_tools: null,
    start_date: '2026-10-01', end_date: '2026-10-31',
    owner: { id: 1, name: 'Teacher' }, department: null, category: null,
    academic_year: null, fiscal_year: null, school_plan: null,
    approval_status: null, execution_status: null, evaluation_status: null,
    abilities: { update: false, delete: false, evaluate: false, view_evaluations: false, create_evaluation: false },
    kpis: [{ id: 12, name: 'Completion', target_value: '100.00', actual_value: null, unit: 'percent' }],
    documents: [{
        id: 18, original_name: 'text-project.pdf', mime_type: 'application/pdf', size_bytes: 798,
        source_import_id: 42, download_url: '/api/v2/imports/import-42/original',
    }],
    ...overrides,
});

const clients: QueryClient[] = [];
const originalAdapter = apiClient.defaults.adapter;

afterEach(() => {
    clients.splice(0).forEach((client) => client.clear());
    apiClient.defaults.adapter = originalAdapter;
    downloadAction.click = null;
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

function renderDetail(item: Project): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    clients.push(client);
    client.setQueryData(projectKeys.detail('71'), item);
    const markup = renderToStaticMarkup(createElement(QueryClientProvider, { client },
        createElement(MemoryRouter, { initialEntries: ['/projects/71'] },
            createElement(Routes, null,
                createElement(Route, { path: '/projects/:projectId', element: createElement(ProjectDetailPage) }),
            ),
        ),
    ));
    return markup;
}

function downloadBrowser() {
    vi.useFakeTimers();
    const link = { href: '', download: '', hidden: false, click: vi.fn(), remove: vi.fn() };
    const appendChild = vi.fn();
    vi.stubGlobal('document', { createElement: vi.fn(() => link), body: { appendChild } });
    vi.stubGlobal('window', { setTimeout: globalThis.setTimeout, dispatchEvent: vi.fn() });
    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:local-original');
    const revokeObjectURL = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    return { link, appendChild, createObjectURL, revokeObjectURL };
}

describe('project detail imported evidence', () => {
    it('downloads the exact original through the SPA client from the rendered button and prevents duplicate clicks', async () => {
        const browser = downloadBrowser();
        const bytes = '%PDF-1.4\nOriginal fixture bytes\n%%EOF\n';
        const original = new Blob([bytes], { type: 'application/pdf' });
        const adapter = vi.fn(async (config: InternalAxiosRequestConfig) => ({ data: original, status: 200, statusText: 'OK', config, headers: {} }));
        apiClient.defaults.adapter = adapter;
        renderDetail(project());
        expect(downloadAction.click).toBeTypeOf('function');

        await Promise.all([downloadAction.click!(), downloadAction.click!()]);

        expect(adapter).toHaveBeenCalledTimes(1);
        const config = adapter.mock.calls[0][0];
        expect(config).toMatchObject({ url: '/api/v2/imports/import-42/original', responseType: 'blob', withCredentials: true });
        expect(config.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
        expect(config.headers.get('Authorization')).toBeUndefined();
        expect(browser.createObjectURL).toHaveBeenCalledWith(original);
        const received = await (browser.createObjectURL.mock.calls[0][0] as Blob).arrayBuffer();
        expect(new Uint8Array(received)).toEqual(new TextEncoder().encode(bytes));
        expect(await crypto.subtle.digest('SHA-256', received)).toEqual(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(bytes)));
        expect(browser.link.href).toBe('blob:local-original');
        expect(browser.link.download).toBe('text-project.pdf');
        expect(browser.appendChild).toHaveBeenCalledWith(browser.link);
        expect(browser.link.click).toHaveBeenCalledTimes(1);
        expect(browser.link.remove).toHaveBeenCalledOnce();
        expect(browser.revokeObjectURL).not.toHaveBeenCalled();
        vi.advanceTimersByTime(60_000);
        expect(browser.revokeObjectURL).toHaveBeenCalledWith('blob:local-original');
    });

    it.each([401, 403])('keeps HTTP %s as a visible download error without saving the JSON body as a PDF', async (status) => {
        const browser = downloadBrowser();
        apiClient.defaults.adapter = async (config) => {
            throw new AxiosError('Download failed', 'ERR_BAD_RESPONSE', config, undefined, {
                data: new Blob([JSON.stringify({ message: 'Download denied', code: status === 401 ? 'unauthenticated' : 'forbidden' })], { type: 'application/json' }),
                status, statusText: 'Error', config, headers: {},
            });
        };
        renderDetail(project());
        await downloadAction.click!();

        expect(clients[0].getMutationCache().getAll()[0].state.error).toMatchObject({ status, message: 'Download denied' });
        expect(browser.createObjectURL).not.toHaveBeenCalled();
        expect(browser.link.click).not.toHaveBeenCalled();
    });

    it('rejects a non-PDF success response without creating a download', async () => {
        const browser = downloadBrowser();
        apiClient.defaults.adapter = async (config) => ({
            data: new Blob(['{}'], { type: 'application/json' }), status: 200, statusText: 'OK', config, headers: {},
        });
        renderDetail(project());
        await downloadAction.click!();
        expect(clients[0].getMutationCache().getAll()[0].state.error?.message).toContain('ไฟล์ที่ได้รับไม่ใช่ PDF');
        expect(browser.createObjectURL).not.toHaveBeenCalled();
        expect(browser.link.click).not.toHaveBeenCalled();
    });

    it('shows imported KPI and the authorized original download even without update or evaluation permissions', () => {
        const markup = renderDetail(project());

        expect(markup).toContain('KPI / ตัวชี้วัด');
        expect(markup).toContain('Completion');
        expect(markup).toMatch(/>100<\/td>/);
        expect(markup).toContain('percent');
        expect(markup).toContain('เอกสารโครงการ / เอกสารต้นฉบับ');
        expect(markup).toContain('text-project.pdf');
        expect(markup).toContain('เอกสารต้นฉบับจากการนำเข้า');
        expect(markup).toMatch(/<button[^>]*type="button">เปิด \/ ดาวน์โหลด<\/button>/);
        expect(markup).not.toContain('href="/api/v2/imports/import-42/original"');
        expect(markup).not.toContain('href="/projects/71/edit"');
        expect(markup).not.toContain('href="/evaluations/projects/71"');
    });

    it('retains escaped document and KPI metadata when no download is authorized', () => {
        const item = project();
        const markup = renderDetail({
            ...item,
            kpis: [{ id: 12, name: '<script>KPI</script>', target_value: '0.00', actual_value: '0.00', unit: null }],
            documents: [{ ...item.documents![0], original_name: '<script>file.pdf</script>', download_url: null }],
        });

        expect(markup).toContain('&lt;script&gt;KPI&lt;/script&gt;');
        expect(markup).toContain('&lt;script&gt;file.pdf&lt;/script&gt;');
        expect(markup.match(/>0<\/td>/g)).toHaveLength(2);
        expect(markup).toContain('เอกสารนี้ยังไม่พร้อมให้เปิดหรือไม่มีสิทธิ์เข้าถึงไฟล์');
        expect(markup).not.toContain('/api/v2/imports/import-42/original');
        expect(markup).not.toContain('เปิด / ดาวน์โหลด');
        expect(markup).not.toContain('<script>');
    });

    it('renders explicit empty states for a project without KPIs or documents', () => {
        const markup = renderDetail(project({ kpis: [], documents: [] }));

        expect(markup).toContain('ยังไม่มีตัวชี้วัดสำหรับโครงการนี้');
        expect(markup).toContain('ยังไม่มีเอกสารสำหรับโครงการนี้');
        expect(markup).not.toContain('<table');
        expect(markup).not.toContain('เปิด / ดาวน์โหลด');
    });
});
