import { Navigate, createBrowserRouter } from 'react-router-dom';

import { LoginPage, RequireAuth, RequirePermission } from '@/auth/AuthContext';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { ProjectDetailPage } from '@/features/projects/ProjectDetailPage';
import { ProjectFormPage } from '@/features/projects/ProjectFormPage';
import { ProjectListPage } from '@/features/projects/ProjectListPage';
import { AppLayout } from '@/layouts/AppLayout';
import { ForbiddenPage } from '@/pages/ForbiddenPage';
import { NotFoundPage } from '@/pages/NotFoundPage';

export const router = createBrowserRouter(
    [
        { path: '/login', element: <LoginPage /> },
        {
            element: <RequireAuth><AppLayout /></RequireAuth>,
            children: [
                { index: true, element: <Navigate replace to="/dashboard" /> },
                { path: '/dashboard', element: <DashboardPage /> },
                { path: '/projects', element: <ProjectListPage /> },
                { path: '/projects/:projectId', element: <ProjectDetailPage /> },
                { path: '/projects/:projectId/edit', element: <ProjectFormPage mode="edit" /> },
                {
                    path: '/projects/new',
                    element: <RequirePermission permission="projects.create"><ProjectFormPage mode="create" /></RequirePermission>,
                },
                { path: '/forbidden', element: <ForbiddenPage /> },
                { path: '*', element: <NotFoundPage /> },
            ],
        },
    ],
    { basename: '/app' },
);
