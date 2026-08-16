import type { QueryClient } from '@tanstack/react-query';

import type { CurrentUser } from '@/api/contracts';

export const authMeKey = ['auth', 'me'] as const;

export const isTerminalSessionFailure = (status: number | undefined, code: string | undefined): boolean =>
    status === 401 || (status === 403 && code === 'account_inactive');

const removeProtectedQueries = async (queryClient: QueryClient): Promise<void> => {
    await queryClient.cancelQueries();
    queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'auth' });
};

export const clearAuthenticatedSession = async (queryClient: QueryClient): Promise<void> => {
    await removeProtectedQueries(queryClient);
    queryClient.setQueryData<CurrentUser | null>(authMeKey, null);
};

export const acceptAuthenticatedUser = async (
    queryClient: QueryClient,
    user: CurrentUser,
): Promise<void> => {
    await removeProtectedQueries(queryClient);
    queryClient.setQueryData(authMeKey, user);
};
