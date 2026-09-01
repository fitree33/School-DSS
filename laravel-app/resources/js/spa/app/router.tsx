import { Navigate, createBrowserRouter } from 'react-router-dom';

import { LoginPage, RequireAuth, RequirePermission } from '@/auth/AuthContext';
import { BudgetManagementPage } from '@/features/budgets/BudgetManagementPage';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { EvaluationDetailPage } from '@/features/evaluations/EvaluationDetailPage';
import { EvaluationEditPage } from '@/features/evaluations/EvaluationEditPage';
import { EvaluationFrameworkFormPage } from '@/features/evaluations/EvaluationFrameworkFormPage';
import { EvaluationFrameworkListPage } from '@/features/evaluations/EvaluationFrameworkListPage';
import { EvaluationListPage } from '@/features/evaluations/EvaluationListPage';
import { ProjectEvaluationPage } from '@/features/evaluations/ProjectEvaluationPage';
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
                {
                    path: '/budgets',
                    element: <RequirePermission permission="budgets.manage"><BudgetManagementPage /></RequirePermission>,
                },
                { path: '/projects', element: <ProjectListPage /> },
                { path: '/projects/:projectId', element: <ProjectDetailPage /> },
                { path: '/projects/:projectId/edit', element: <ProjectFormPage mode="edit" /> },
                {
                    path: '/projects/new',
                    element: <RequirePermission permission="projects.create"><ProjectFormPage mode="create" /></RequirePermission>,
                },
                {
                    path: '/evaluations',
                    element: <RequirePermission permission="evaluations.view"><EvaluationListPage /></RequirePermission>,
                },
                {
                    path: '/evaluations/projects/:projectId',
                    element: <RequirePermission permission="evaluations.view"><ProjectEvaluationPage /></RequirePermission>,
                },
                {
                    path: '/evaluations/:evaluationId',
                    element: <RequirePermission permission="evaluations.view"><EvaluationDetailPage /></RequirePermission>,
                },
                {
                    path: '/evaluations/:evaluationId/edit',
                    element: <RequirePermission permission="evaluations.update"><EvaluationEditPage /></RequirePermission>,
                },
                {
                    path: '/evaluations/frameworks',
                    element: <RequirePermission permission="evaluations.manage_frameworks"><EvaluationFrameworkListPage /></RequirePermission>,
                },
                {
                    path: '/evaluations/frameworks/new',
                    element: <RequirePermission permission="evaluations.manage_frameworks"><EvaluationFrameworkFormPage mode="create" /></RequirePermission>,
                },
                {
                    path: '/evaluations/frameworks/:frameworkId/edit',
                    element: <RequirePermission permission="evaluations.manage_frameworks"><EvaluationFrameworkFormPage mode="edit" /></RequirePermission>,
                },
                { path: '/forbidden', element: <ForbiddenPage /> },
                { path: '*', element: <NotFoundPage /> },
            ],
        },
    ],
    { basename: '/app' },
);
