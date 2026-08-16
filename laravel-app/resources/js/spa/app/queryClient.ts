import { QueryClient } from '@tanstack/react-query';

import { isApiError } from '@/api/client';

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: false,
            retry: (failureCount, error) => {
                if (isApiError(error) && error.status !== null && error.status >= 400 && error.status < 500) {
                    return false;
                }

                return failureCount < 1;
            },
            staleTime: 30_000,
        },
        mutations: {
            retry: false,
        },
    },
});
