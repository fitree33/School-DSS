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
    view_evaluations?: boolean;
    create_evaluation?: boolean;
}

export interface ProjectBudgetMetrics {
    budget: string;
    actual_spent: string;
    remaining: string;
    used_percentage: number | null;
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
    latest_evaluation?: ProjectEvaluationSummary | null;
    budget_metrics?: ProjectBudgetMetrics;
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
}

export type EvaluationStatusCode = 'pending' | 'passed' | 'failed';

export interface EvaluationCriterion {
    id: number;
    name: string;
    description: string | null;
    max_score: number | string;
    weight: number | string | null;
    sort_order: number;
    evaluation_method: string | null;
    evaluation_tools: string | null;
    is_active: boolean;
}

export interface EvaluationFrameworkAbilities {
    update: boolean;
    activate: boolean;
    deactivate: boolean;
    create_version: boolean;
}

export interface EvaluationFramework {
    id: number;
    code: string;
    version: string;
    name: string;
    description: string | null;
    fiscal_year: YearOption | null;
    effective_from: string | null;
    effective_to: string | null;
    is_active: boolean;
    is_used: boolean;
    criteria: EvaluationCriterion[];
    abilities?: Partial<EvaluationFrameworkAbilities>;
    created_at?: string | null;
    updated_at?: string | null;
}

export interface EvaluationScore {
    id?: number;
    evaluation_criterion_id: number;
    criterion?: EvaluationCriterion;
    score: number | string;
    comment: string | null;
}

export interface ProjectEvaluationResult {
    id: number;
    status: StatusOption;
    decision_note: string;
    finalized_by: NamedOption | null;
    finalized_at: string;
    total_score: number | string;
    maximum_score: number | string;
    percentage: number | string | null;
    weighted_percentage: number | string | null;
}

export interface ProjectEvaluationAbilities {
    view: boolean;
    update: boolean;
    finalize: boolean;
}

export interface ProjectEvaluationSummary {
    id: number;
    round: number;
    total_score: number | string;
    maximum_score: number | string;
    percentage: number | string | null;
    weighted_percentage: number | string | null;
    evaluated_at: string | null;
    is_finalized?: boolean;
    finalized_at: string | null;
    evaluator: NamedOption | null;
    framework: EvaluationFramework;
    result: ProjectEvaluationResult | null;
}

export interface ProjectEvaluation extends ProjectEvaluationSummary {
    project: Omit<EvaluationProject, 'latest_evaluation' | 'evaluation_count' | 'abilities'>;
    comment: string | null;
    scores: EvaluationScore[];
    abilities: ProjectEvaluationAbilities;
}

export interface EvaluationProject {
    id: number;
    name: string;
    project_code: string | null;
    department: NamedOption | null;
    fiscal_year: YearOption | null;
    evaluation_status: StatusOption | null;
    latest_evaluation?: ProjectEvaluationSummary | null;
    evaluation_count?: number;
    abilities: {
        view_evaluations: boolean;
        create_evaluation: boolean;
    };
}

export interface EvaluationOptions {
    fiscal_years: YearOption[];
    departments: NamedOption[];
    evaluation_statuses: StatusOption[];
    frameworks?: EvaluationFramework[];
    can: {
        manage_frameworks: boolean;
    };
}

export interface EvaluationFilters {
    fiscal_year_id?: string;
    department_id?: string;
    evaluation_status?: EvaluationStatusCode | '';
    page?: number;
    per_page?: number;
}

export interface PaginatedEvaluationProjects {
    data: EvaluationProject[];
    links: PaginationLinks;
    meta: PaginationMeta;
}

export interface PaginatedEvaluationFrameworks {
    data: EvaluationFramework[];
    links: PaginationLinks;
    meta: PaginationMeta;
}

export interface EvaluationScorePayload {
    evaluation_criterion_id: number;
    score: string;
    comment: string | null;
}

export interface ProjectEvaluationPayload {
    evaluation_framework_id: number;
    evaluated_at: string;
    comment: string | null;
    scores: EvaluationScorePayload[];
}

export interface FinalizeEvaluationPayload {
    status: EvaluationStatusCode;
    decision_note: string;
}

export interface EvaluationCriterionPayload {
    name: string;
    description: string | null;
    max_score: string;
    weight: string | null;
    sort_order: number;
    evaluation_method: string | null;
    evaluation_tools: string | null;
    is_active: boolean;
}

export interface EvaluationFrameworkPayload {
    code: string;
    version: string;
    name: string;
    description: string | null;
    fiscal_year_id: number | null;
    effective_from: string | null;
    effective_to: string | null;
    criteria: EvaluationCriterionPayload[];
}

export interface DashboardSchoolBudget {
    total_budget: string;
    allocated_to_departments: string;
    unallocated: string;
    total_actual_spent: string;
    remaining: string;
    used_percentage: number | null;
    remaining_percentage: number | null;
    overallocated: boolean;
    overspent: boolean;
}

export interface DashboardDepartmentBudget {
    department: Department;
    is_allocated: boolean;
    allocated_budget: string;
    planned_project_budget: string;
    actual_spent: string;
    remaining: string;
    used_percentage: number | null;
    remaining_percentage: number | null;
    overcommitted: boolean;
    overspent: boolean;
}

export interface DashboardExecutionStatusCounts {
    not_started: number;
    in_progress: number;
    completed: number;
}

export interface DashboardEvaluationStatusCounts {
    pending: number;
    passed: number;
    failed: number;
}

export interface DashboardSummary {
    fiscal_year: YearOption | null;
    fiscal_years: YearOption[];
    school_budget: DashboardSchoolBudget;
    departments: DashboardDepartmentBudget[];
    project_execution_status_counts: DashboardExecutionStatusCounts;
    evaluation_status_counts: DashboardEvaluationStatusCounts;
    total_projects: number;
    can: {
        manage_budgets: boolean;
    };
}

export interface SchoolBudgetRecord {
    id: number;
    fiscal_year_id: number;
    total_amount: string;
    notes: string | null;
    updated_at: string | null;
}

export interface DepartmentBudgetRecord {
    id: number | null;
    department: Department;
    allocated_amount: string;
    is_allocated: boolean;
    allocated_at: string | null;
    allocated_by: number | null;
    notes: string | null;
    updated_at: string | null;
}

export interface BudgetManagementData {
    fiscal_year: YearOption | null;
    fiscal_years: YearOption[];
    school_budget: SchoolBudgetRecord | null;
    department_budgets: DepartmentBudgetRecord[];
    can: {
        manage_budgets: boolean;
    };
}

export interface SchoolBudgetPayload {
    total_amount: string;
    notes: string | null;
}

export interface DepartmentBudgetPayload {
    allocated_amount: string;
    is_allocated: boolean;
    notes: string | null;
}
