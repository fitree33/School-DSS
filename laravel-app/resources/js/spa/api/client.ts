import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios';

import type { ApiErrorPayload } from '@/api/contracts';
import { isTerminalSessionFailure } from '@/auth/session';

interface CsrfRetriableRequest extends InternalAxiosRequestConfig {
    _csrfRetried?: boolean;
}

export const AUTH_EXPIRED_EVENT = 'school-dss:auth-expired';

export class ApiError extends Error {
    readonly status: number | null;
    readonly code: string;
    readonly errors: Record<string, string[]>;

    constructor(
        message: string,
        status: number | null = null,
        code = 'request_failed',
        errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.errors = errors;
    }
}

export const apiClient = axios.create({
    baseURL: '/',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
    withXSRFToken: true,
});

const toApiError = (error: AxiosError<ApiErrorPayload>): ApiError => {
    const status = error.response?.status ?? null;
    const payload = error.response?.data;

    return new ApiError(
        payload?.message ?? (status ? `คำขอล้มเหลว (${status})` : 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้'),
        status,
        payload?.code ?? 'request_failed',
        payload?.errors ?? {},
    );
};

apiClient.interceptors.response.use(
    (response) => response,
    async (unknownError: unknown) => {
        if (!axios.isAxiosError<ApiErrorPayload>(unknownError)) {
            return Promise.reject(unknownError);
        }

        // Binary downloads still return the normal JSON error contract on failure.
        if (typeof Blob !== 'undefined' && unknownError.response?.data instanceof Blob) {
            const body = unknownError.response.data;
            if (body.type.includes('json')) {
                try {
                    unknownError.response.data = JSON.parse(await body.text()) as ApiErrorPayload;
                } catch {
                    // Preserve the HTTP status and generic error for a malformed response.
                }
            }
        }

        const request = unknownError.config as CsrfRetriableRequest | undefined;
        const isCsrfRequest = request?.url?.includes('/sanctum/csrf-cookie') === true;

        if (unknownError.response?.status === 419 && request && !request._csrfRetried && !isCsrfRequest) {
            request._csrfRetried = true;

            try {
                await apiClient.get('/sanctum/csrf-cookie');
                return await apiClient.request(request);
            } catch (retryError) {
                return Promise.reject(retryError);
            }
        }

        if (
            isTerminalSessionFailure(unknownError.response?.status, unknownError.response?.data?.code)
            && typeof window !== 'undefined'
        ) {
            window.dispatchEvent(new Event(AUTH_EXPIRED_EVENT));
        }

        return Promise.reject(toApiError(unknownError));
    },
);

export const ensureCsrfCookie = async (): Promise<void> => {
    await apiClient.get('/sanctum/csrf-cookie');
};

export const isApiError = (error: unknown): error is ApiError => error instanceof ApiError;
