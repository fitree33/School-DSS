import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router-dom';

import { queryClient } from '@/app/queryClient';
import { router } from '@/app/router';
import { AuthProvider } from '@/auth/AuthContext';

export function AppProviders() {
    return (
        <QueryClientProvider client={queryClient}>
            <AuthProvider>
                <RouterProvider router={router} />
            </AuthProvider>
        </QueryClientProvider>
    );
}
