import { QueryClient } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';

import type { CurrentUser } from '@/api/contracts';
import {
    acceptAuthenticatedUser,
    authMeKey,
    clearAuthenticatedSession,
    isTerminalSessionFailure,
} from '@/auth/session';

const user = (id: number): CurrentUser => ({
    id,
    name: `User ${id}`,
    email: `user${id}@example.test`,
    teacher_code: null,
    phone: null,
    email_verified_at: null,
    last_login_at: null,
    is_active: true,
    department: null,
    role: null,
    permissions: [],
});

describe('authenticated query isolation', () => {
    it('removes protected data when a session expires or is deactivated', async () => {
        const queryClient = new QueryClient();
        queryClient.setQueryData(authMeKey, user(1));
        queryClient.setQueryData(['projects', 'list'], [{ id: 10 }]);

        await clearAuthenticatedSession(queryClient);

        expect(queryClient.getQueryData(authMeKey)).toBeNull();
        expect(queryClient.getQueryData(['projects', 'list'])).toBeUndefined();
    });

    it('purges the previous identity data before accepting another user', async () => {
        const queryClient = new QueryClient();
        queryClient.setQueryData(authMeKey, user(1));
        queryClient.setQueryData(['projects', 'detail', 10], { id: 10, owner: 1 });

        await acceptAuthenticatedUser(queryClient, user(2));

        expect(queryClient.getQueryData(authMeKey)).toEqual(user(2));
        expect(queryClient.getQueryData(['projects', 'detail', 10])).toBeUndefined();
    });

    it('recognizes only terminal authentication failures', () => {
        expect(isTerminalSessionFailure(401, 'unauthenticated')).toBe(true);
        expect(isTerminalSessionFailure(403, 'account_inactive')).toBe(true);
        expect(isTerminalSessionFailure(403, 'forbidden')).toBe(false);
        expect(isTerminalSessionFailure(422, 'validation_failed')).toBe(false);
    });
});
