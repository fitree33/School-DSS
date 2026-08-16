export interface ApiEnvelope<T> {
    data: T;
}

export interface ApiErrorPayload {
    message?: string;
    code?: string;
    errors?: Record<string, string[]>;
}

export interface Department {
    id: number;
    name: string;
}

export interface Role {
    id: number;
    code: string;
    name: string;
}

export interface CurrentUser {
    id: number;
    name: string;
    email: string;
    teacher_code: string | null;
    phone: string | null;
    email_verified_at: string | null;
    last_login_at: string | null;
    is_active: boolean;
    department: Department | null;
    role: Role | null;
    permissions: string[];
}

export interface NamedOption {
    id: number;
    name: string;
    code?: string;
}

export interface YearOption {
    id: number;
    year: number | string;
    is_active?: boolean;
    is_locked?: boolean;
}

export interface SchoolPlanOption extends NamedOption {
    fiscal_year_id?: number;
}

export interface StatusOption extends NamedOption {
    code: string;
    color?: string | null;
    is_terminal?: boolean;
}

export interface ProjectOptions {
    departments: NamedOption[];
    project_categories: NamedOption[];
    academic_years: YearOption[];
    fiscal_years: YearOption[];
    school_plans: SchoolPlanOption[];
    execution_statuses: StatusOption[];
    evaluation_statuses: StatusOption[];
    budget_sources: string[];
}

export interface ProjectAbilities {
    update: boolean;
    delete: boolean;
    evaluate: boolean;
}

export interface Project {
    id: number;
    name: string;
    project_code: string | null;
    objective: string;
    description: string | null;
    rationale: string | null;
    target_group: string | null;
    strategy: string | null;
    key_points: string | null;
    budget: number | string;
    actual_spent: number | string;
    budget_source: string | null;
    responsible_person: string | null;
    monitor_person: string | null;
    evaluation_method: string | null;
    evaluation_tools: string | null;
    start_date: string | null;
    end_date: string | null;
    department_id?: number;
    project_category_id?: number;
    academic_year_id?: number;
    fiscal_year_id?: number;
    school_plan_id?: number | null;
    owner: NamedOption | null;
    department: NamedOption | null;
    category: NamedOption | null;
    academic_year: YearOption | null;
    fiscal_year: YearOption | null;
    school_plan: SchoolPlanOption | null;
    approval_status: StatusOption | null;
    execution_status: StatusOption | null;
    evaluation_status: StatusOption | null;
    abilities: ProjectAbilities;
    created_at?: string;
    updated_at?: string;
}

export interface PaginationMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
}

export interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

export interface PaginatedProjects {
    data: Project[];
    links: PaginationLinks;
    meta: PaginationMeta;
}

export interface ProjectFilters {
    q?: string;
    fiscal_year_id?: string;
    department_id?: string;
    execution_status?: string;
    evaluation_status?: string;
    page?: number;
    per_page?: number;
}

export interface ProjectPayload {
    name: string;
    project_code: string | null;
    objective: string;
    description: string | null;
    rationale: string | null;
    target_group: string | null;
    strategy: string | null;
    key_points: string | null;
    budget: string;
    actual_spent?: string;
    budget_source: string | null;
    responsible_person: string | null;
    monitor_person: string | null;
    evaluation_method: string | null;
    evaluation_tools: string | null;
    start_date: string | null;
    end_date: string | null;
    department_id: number;
    project_category_id: number;
    academic_year_id: number;
    fiscal_year_id: number;
    school_plan_id: number | null;
    execution_status?: string;
    evaluation_status?: string;
}

/**
 * Reserved response shape for the Dashboard API planned after Phase 2.
 * Amounts remain decimal strings so the browser does not become the source
 * of truth for budget arithmetic.
 */
export interface DashboardBudgetMetric {
    budget_amount: string;
    actual_spent: string;
    remaining_amount: string;
    used_percentage: number | null;
}

export interface DashboardDepartmentBudget extends DashboardBudgetMetric {
    department: Department;
    is_allocated: boolean;
}

export interface DashboardSummary {
    fiscal_year: YearOption | null;
    school_budget: DashboardBudgetMetric | null;
    department_budgets: DashboardDepartmentBudget[];
}
