import { apiClient } from '@/api/client';
import type {
    ApiEnvelope,
    PaginatedProjects,
    Project,
    ProjectFilters,
    ProjectOptions,
    ProjectPayload,
} from '@/api/contracts';

export const projectKeys = {
    all: ['projects'] as const,
    list: (filters: ProjectFilters) => ['projects', 'list', filters] as const,
    detail: (id: string | number) => ['projects', 'detail', String(id)] as const,
    options: ['projects', 'options'] as const,
};

export const fetchProjects = async (filters: ProjectFilters): Promise<PaginatedProjects> => {
    const response = await apiClient.get<PaginatedProjects>('/api/v2/projects', { params: compactParams(filters) });
    return response.data;
};

export const fetchProject = async (id: string | number): Promise<Project> => {
    const response = await apiClient.get<ApiEnvelope<Project>>(`/api/v2/projects/${id}`);
    return response.data.data;
};

export const fetchProjectOptions = async (): Promise<ProjectOptions> => {
    const response = await apiClient.get<ApiEnvelope<ProjectOptions>>('/api/v2/project-options');
    return response.data.data;
};

export const createProject = async (payload: ProjectPayload): Promise<Project> => {
    const response = await apiClient.post<ApiEnvelope<Project>>('/api/v2/projects', payload);
    return response.data.data;
};

export const updateProject = async (id: string | number, payload: Partial<ProjectPayload>): Promise<Project> => {
    const response = await apiClient.put<ApiEnvelope<Project>>(`/api/v2/projects/${id}`, payload);
    return response.data.data;
};

export const deleteProject = async (id: string | number): Promise<void> => {
    await apiClient.delete(`/api/v2/projects/${id}`);
};

const compactParams = (filters: ProjectFilters): Record<string, string | number> =>
    Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== undefined && value !== ''),
    ) as Record<string, string | number>;
