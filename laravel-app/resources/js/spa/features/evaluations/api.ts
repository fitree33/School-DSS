import { apiClient } from '@/api/client';
import type {
    ApiEnvelope,
    EvaluationFilters,
    EvaluationFramework,
    EvaluationFrameworkPayload,
    EvaluationOptions,
    FinalizeEvaluationPayload,
    PaginatedEvaluationFrameworks,
    PaginatedEvaluationProjects,
    ProjectEvaluation,
    ProjectEvaluationPayload,
} from '@/api/contracts';
import { compactEvaluationFilters } from '@/features/evaluations/filters';

export const evaluationKeys = {
    all: ['evaluations'] as const,
    options: ['evaluations', 'options'] as const,
    projects: (filters: EvaluationFilters) => ['evaluations', 'projects', filters] as const,
    projectHistory: (projectId: string | number) => ['evaluations', 'project-history', String(projectId)] as const,
    detail: (evaluationId: string | number) => ['evaluations', 'detail', String(evaluationId)] as const,
    frameworks: ['evaluations', 'frameworks'] as const,
    frameworkList: (page: number) => ['evaluations', 'frameworks', 'list', page] as const,
    framework: (frameworkId: string | number) => ['evaluations', 'framework', String(frameworkId)] as const,
};

export const fetchEvaluationOptions = async (): Promise<EvaluationOptions> => {
    const response = await apiClient.get<ApiEnvelope<EvaluationOptions>>('/api/v2/evaluation-options');
    return response.data.data;
};

export const fetchEvaluationProjects = async (filters: EvaluationFilters): Promise<PaginatedEvaluationProjects> => {
    const response = await apiClient.get<PaginatedEvaluationProjects>('/api/v2/evaluation-projects', {
        params: compactEvaluationFilters(filters),
    });
    return response.data;
};

export const fetchProjectEvaluations = async (projectId: string | number): Promise<ProjectEvaluation[]> => {
    const response = await apiClient.get<ApiEnvelope<ProjectEvaluation[]>>(`/api/v2/projects/${projectId}/evaluations`);
    return response.data.data;
};

export const createProjectEvaluation = async (
    projectId: string | number,
    payload: ProjectEvaluationPayload,
): Promise<ProjectEvaluation> => {
    const response = await apiClient.post<ApiEnvelope<ProjectEvaluation>>(`/api/v2/projects/${projectId}/evaluations`, payload);
    return response.data.data;
};

export const fetchProjectEvaluation = async (evaluationId: string | number): Promise<ProjectEvaluation> => {
    const response = await apiClient.get<ApiEnvelope<ProjectEvaluation>>(`/api/v2/project-evaluations/${evaluationId}`);
    return response.data.data;
};

export const updateProjectEvaluation = async (
    evaluationId: string | number,
    payload: Omit<ProjectEvaluationPayload, 'evaluation_framework_id'>,
): Promise<ProjectEvaluation> => {
    const response = await apiClient.put<ApiEnvelope<ProjectEvaluation>>(`/api/v2/project-evaluations/${evaluationId}`, payload);
    return response.data.data;
};

export const finalizeProjectEvaluation = async (
    evaluationId: string | number,
    payload: FinalizeEvaluationPayload,
): Promise<ProjectEvaluation> => {
    const response = await apiClient.post<ApiEnvelope<ProjectEvaluation>>(`/api/v2/project-evaluations/${evaluationId}/finalize`, payload);
    return response.data.data;
};

export const fetchEvaluationFrameworks = async (page: number): Promise<PaginatedEvaluationFrameworks> => {
    const response = await apiClient.get<PaginatedEvaluationFrameworks>('/api/v2/evaluation-frameworks', {
        params: { page },
    });
    return response.data;
};

export const fetchEvaluationFramework = async (frameworkId: string | number): Promise<EvaluationFramework> => {
    const response = await apiClient.get<ApiEnvelope<EvaluationFramework>>(`/api/v2/evaluation-frameworks/${frameworkId}`);
    return response.data.data;
};

export const createEvaluationFramework = async (payload: EvaluationFrameworkPayload): Promise<EvaluationFramework> => {
    const response = await apiClient.post<ApiEnvelope<EvaluationFramework>>('/api/v2/evaluation-frameworks', payload);
    return response.data.data;
};

export const updateEvaluationFramework = async (
    frameworkId: string | number,
    payload: Pick<EvaluationFrameworkPayload, 'name' | 'description'>
        & Partial<Omit<EvaluationFrameworkPayload, 'code' | 'version' | 'name' | 'description'>>,
): Promise<EvaluationFramework> => {
    const response = await apiClient.put<ApiEnvelope<EvaluationFramework>>(`/api/v2/evaluation-frameworks/${frameworkId}`, payload);
    return response.data.data;
};

export const createEvaluationFrameworkVersion = async (
    frameworkId: string | number,
    payload: Omit<EvaluationFrameworkPayload, 'code'>,
): Promise<EvaluationFramework> => {
    const response = await apiClient.post<ApiEnvelope<EvaluationFramework>>(`/api/v2/evaluation-frameworks/${frameworkId}/versions`, payload);
    return response.data.data;
};

export const setEvaluationFrameworkActive = async (
    frameworkId: string | number,
    active: boolean,
): Promise<EvaluationFramework> => {
    const action = active ? 'activate' : 'deactivate';
    const response = await apiClient.post<ApiEnvelope<EvaluationFramework>>(`/api/v2/evaluation-frameworks/${frameworkId}/${action}`);
    return response.data.data;
};
