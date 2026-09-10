import { AxiosError, type AxiosAdapter } from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { apiClient, AUTH_EXPIRED_EVENT } from '@/api/client';

afterEach(() => vi.unstubAllGlobals());

const rejectedBlob = (status: number, body: string, type = 'application/json'): AxiosAdapter => async (config) => {
    throw new AxiosError('Download failed', 'ERR_BAD_RESPONSE', config, undefined, {
        data: new Blob([body], { type }), status, statusText: 'Error', config, headers: { 'content-type': type },
    });
};

describe('binary API errors', () => {
    it.each([
        [401, 'unauthenticated', true],
        [403, 'forbidden', false],
        [403, 'account_inactive', true],
    ])('preserves JSON errors for HTTP %s / %s and existing session expiry handling', async (status, code, expires) => {
        const dispatchEvent = vi.fn();
        vi.stubGlobal('window', { dispatchEvent });
        const body = JSON.stringify({ message: 'Download not permitted', code });

        await expect(apiClient.get('/api/v2/imports/import-1/original', {
            responseType: 'blob', adapter: rejectedBlob(status, body),
        })).rejects.toMatchObject({ status, code, message: 'Download not permitted' });

        expect(dispatchEvent).toHaveBeenCalledTimes(expires ? 1 : 0);
        if (expires) expect(dispatchEvent.mock.calls[0][0].type).toBe(AUTH_EXPIRED_EVENT);
    });

    it.each(['application/json', 'text/html'])('keeps failed %s downloads as errors when the body is not JSON', async (type) => {
        await expect(apiClient.get('/api/v2/imports/import-1/original', {
            responseType: 'blob', adapter: rejectedBlob(500, '<html>Unavailable</html>', type),
        })).rejects.toMatchObject({ status: 500, code: 'request_failed' });
    });
});
