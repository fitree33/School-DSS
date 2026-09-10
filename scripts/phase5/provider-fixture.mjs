// Local deterministic QA fixture, not an AI or n8n integration certification.
import { createServer } from 'node:http';
import { createHash, createHmac } from 'node:crypto';
import { readFileSync, appendFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const runtime = fileURLToPath(new URL('../../.foundation-runtime/phase5-qa/', import.meta.url));
const settings = JSON.parse(readFileSync(`${runtime}/settings.json`, 'utf8'));
const log = (entry) => appendFileSync(`${runtime}/provider-events.jsonl`, `${JSON.stringify({ at: new Date().toISOString(), ...entry })}\n`);

const server = createServer((request, response) => {
    if (request.method === 'GET' && request.url === '/health') {
        response.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ fixture: true, status: 'ready' }));
        return;
    }
    if (request.method !== 'POST' || request.url !== '/fixture-extract') {
        response.writeHead(404).end();
        return;
    }
    const chunks = [];
    let bytes = 0;
    request.on('data', (chunk) => {
        bytes += chunk.length;
        if (bytes > 3 * 1024 * 1024) request.destroy();
        else chunks.push(chunk);
    });
    request.on('end', () => {
        let input;
        try {
            input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
            if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(input.extraction_run_id)
                || typeof input.extracted_text !== 'string' || !Number.isInteger(input.attempt_no) || input.attempt_no < 1) throw new Error('Invalid request');
        } catch {
            response.writeHead(422).end();
            return;
        }
        const run = input.extraction_run_id.toLowerCase();
        const job = `phase5-fixture-${run}`;
        const firstFailure = input.extracted_text.includes('QA_RETRY_ONCE') && input.attempt_no === 1;
        const delay = input.extracted_text.includes('QA_SLOW') ? 20000 : 4000;
        log({ event: 'accepted', run, attempt: input.attempt_no, fixture: true });
        response.writeHead(202, { 'Content-Type': 'application/json' }).end(JSON.stringify({ job_id: job }));
        setTimeout(async () => {
            const callback = firstFailure ? {
                status: 'failed', provider_job_id: job, model_name: 'local-qa-fixture',
                failure: { code: 'QA_FIXTURE_RETRY_ONCE', message: 'Disposable QA fixture: retry this import to complete extraction.' },
            } : {
                status: 'succeeded', provider_job_id: job, model_name: 'local-qa-fixture',
                payload: {
                    name: `Phase 5 QA ${input.extracted_text.includes('QA_RETRY_ONCE') ? 'retried' : 'imported'} project`,
                    objective: 'Verify PDF text-layer extraction, review and explicit confirmation.',
                    budget: '1500.00', key_points: 'Deterministic local QA fixture. Review master data before confirming.',
                    responsible_person: 'QA Teacher', monitor_person: 'QA Department Head',
                    evaluation_method: 'Review evidence', evaluation_tools: 'QA checklist',
                    start_date: '2026-10-01', end_date: '2026-10-31',
                    indicators: [{ name: 'Completion', target_value: '100', unit: 'percent' }],
                },
                confidence: { name: 0.95, objective: 0.7, budget: 0.9 },
                warnings: [{ code: 'local_qa_fixture', message: 'Deterministic local provider fixture; no external AI call.' }],
            };
            const body = JSON.stringify(callback);
            const timestamp = String(Math.floor(Date.now() / 1000));
            const event = `phase5-fixture-event-${run}`;
            const digest = createHash('sha256').update(body).digest('hex');
            const signature = createHmac('sha256', settings.callback_secret).update([timestamp, event, run, digest].join('\n')).digest('hex');
            try {
                const result = await fetch(`http://127.0.0.1:8015/api/v2/import-extraction-runs/${run}/callback`, {
                    method: 'POST', body, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Import-Timestamp': timestamp, 'X-Import-Event-Id': event, 'X-Import-Signature': `sha256=${signature}` },
                    signal: AbortSignal.timeout(15000),
                });
                log({ event: 'callback', run, status: result.status, fixture: true });
            } catch (error) {
                log({ event: 'callback-error', run, message: String(error), fixture: true });
            }
        }, delay);
    });
});
server.listen(8016, '127.0.0.1', () => log({ event: 'listening', port: 8016, fixture: true }));
