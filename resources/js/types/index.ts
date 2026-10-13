import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export type PermissionName =
    | 'projects.view'
    | 'projects.create'
    | 'projects.update'
    | 'projects.delete'
    | 'members.manage'
    | 'settings.manage'
    | 'activity.view'
    | 'tasks.manage'
    | 'tasks.approve'
    | 'agents.view'
    | 'agents.manage'
    | 'runners.manage'
    | 'operations.halt'
    | 'operations.resume';

export interface CurrentOrganization {
    id: number;
    name: string;
    slug: string;
    is_demo: boolean;
    role: string | null;
    permissions: PermissionName[];
}

export interface OperationsState {
    halted: boolean;
    scope: 'global' | 'organization' | null;
    reason: string | null;
    halted_at: string | null;
}

export interface SharedData {
    name: string;
    demoMode: boolean;
    auth: Auth;
    organization: CurrentOrganization | null;
    organizations: Pick<CurrentOrganization, 'id' | 'name' | 'slug'>[];
    operations: OperationsState | null;
    eventCursor: number;
    flash: { status: string | null };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    is_super_admin: boolean;
    created_at?: string;
    updated_at?: string;
    [key: string]: unknown; // This allows for additional properties...
}

/** A persisted outbox event, identical from the catch-up API and the socket (Plan §48.6). */
export interface StudioEvent {
    id: number;
    stream: string;
    sequence: number;
    type: string;
    project_id: number | null;
    payload: Record<string, unknown>;
    actor: { id: number; name: string } | null;
    occurred_at: string;
    project?: { id: number; key: string; name: string; slug: string } | null;
}

export type HealthStatus = 'ok' | 'degraded' | 'down';

export interface HealthReport {
    status: HealthStatus;
    checks: { key: string; label: string; status: HealthStatus; detail: string }[];
}

/** Scanner output (runner/src/scanner/detect.js). Every field comes from a real project file. */
export interface StackProfile {
    name: string;
    languages?: string[];
    backend: { framework: string; version: string | null; constraint: string | null; php: string | null; structure?: string } | null;
    frontend: { framework: string; version: string | null; router?: string | null; meta?: string | null } | null;
    bridge: string | null;
    bundler: string | null;
    css: string[];
    language: string | null;
    packageManager?: string | null;
    node?: string | null;
    database: string | null;
    tests: string[];
    e2e?: string[];
    staticAnalysis?: string[];
    formatters?: string[];
    auth?: string[];
    payments?: string[];
    webhookRoutes?: string[];
    envPrefixes?: string[];
    docker?: string[];
    ci?: string[];
    designSystem?: { tailwindConfig: string | null; componentDirs: string[]; cssVariables: boolean };
    commands?: { label: string; command: string }[];
    envShape?: Record<string, string> | null;
}

export interface GitState {
    enabled: boolean;
    branch: string | null;
    head: string | null;
    hasCommits: boolean;
    modified: number;
    untracked: number;
    remotes: { name: string; url: string }[];
    worktrees: number;
    envTracked: boolean;
}

export interface ProjectConnection {
    type: 'local' | 'github' | 'new' | 'archive';
    type_label: string;
    status: 'pending_scan' | 'scanning' | 'indexing' | 'connected' | 'error';
    status_label: string;
    local_path: string | null;
    repository_url: string | null;
    git_enabled: boolean | null;
    detected_stack: StackProfile | null;
    git_state: GitState | null;
    warnings: string[];
    last_scanned_at: string | null;
    indexed_at: string | null;
    index_stats: { files: number; symbols: number; kinds: Record<string, number>; listed: number } | null;
    last_error: string | null;
    local_runner_id: number | null;
}

export type RunnerStatus = 'online' | 'stale' | 'offline' | 'revoked';

export interface LocalRunner {
    id: number;
    name: string;
    hostname: string | null;
    os: string | null;
    platform: string | null;
    version: string | null;
    status: RunnerStatus;
    status_label: string;
    allowed_roots: string[];
    workspaces_root: string | null;
    last_heartbeat_at: string | null;
    paired_by: string | null;
    created_at: string | null;
}

export interface RunnerCommand {
    id: string;
    type: string;
    label: string;
    status: 'queued' | 'claimed' | 'succeeded' | 'failed' | 'expired' | 'cancelled';
    progress: string | null;
    error: string | null;
    error_code: string | null;
    created_at: string | null;
    completed_at: string | null;
}

export interface Workspace {
    id: number;
    task_key: string;
    branch: string;
    path: string | null;
    base_branch: string | null;
    base_sha: string | null;
    last_checkpoint_sha: string | null;
    status: 'creating' | 'ready' | 'removing' | 'removed' | 'failed';
    error: string | null;
    created_at: string | null;
}

export interface Project {
    id: number;
    key: string;
    name: string;
    slug: string;
    description: string | null;
    status: string;
    created_at: string | null;
    connection: ProjectConnection | null;
}

export type TaskStatus =
    | 'BACKLOG'
    | 'READY'
    | 'PLANNING'
    | 'IN_PROGRESS'
    | 'TESTING'
    | 'REVIEW'
    | 'SECURITY_REVIEW'
    | 'CHANGES_REQUESTED'
    | 'BLOCKED'
    | 'RELEASE_READY'
    | 'DONE'
    | 'CANCELLED';

export type TaskPriority = 'CRITICAL' | 'HIGH' | 'MEDIUM' | 'LOW';

export type AgentRole = 'PM' | 'BA' | 'SA' | 'UX' | 'FE' | 'BE' | 'DB' | 'QA' | 'SE' | 'SR' | 'DO' | 'RM';

export interface TaskCard {
    id: number;
    number: number;
    key: string;
    title: string;
    type: 'feature' | 'bug' | 'improvement' | 'chore';
    priority: TaskPriority;
    status: TaskStatus;
    blocked_from: TaskStatus | null;
    blocked_reason: string | null;
    position: number;
    version: number;
    complexity: 'S' | 'M' | 'L' | 'XL' | null;
    token_estimate: number | null;
    waiting_on: { key: string; number: number; status: TaskStatus }[];
    comments_count: number | null;
    workspace_status: Workspace['status'] | null;
    agent_run: {
        agent: string;
        role: AgentRole;
        status: 'PENDING' | 'RUNNING' | 'RETRYING' | 'PAUSED' | 'BLOCKED' | 'COMPLETED' | 'FAILED' | 'CANCELLED';
        model: string | null;
        tokens: number;
        token_budget: number;
    } | null;
    user_targets: TaskStatus[];
    updated_at: string | null;
}

export type ImpactLevel = 'none' | 'low' | 'medium' | 'high';

export interface TaskImpact {
    version: string;
    terms: string[];
    areas: Record<'backend' | 'frontend' | 'database' | 'security', { level: ImpactLevel; reasons: string[] }>;
    expected_files: { path: string; reasons: string[]; size: number }[];
    expected_tests: { path: string; reasons: string[] }[];
    new_tests_needed: boolean;
    agents: { role: AgentRole; reason: string }[];
    complexity: 'S' | 'M' | 'L' | 'XL';
    token_estimate: number;
    estimated_cost: { amount: number; currency: string; priced_models: number; total_models: number } | null;
    notes: string[];
    basis: string;
}

export interface TaskRef {
    id: number;
    number: number;
    key: string;
    title: string;
    status: TaskStatus;
}

export interface TaskDetail extends TaskCard {
    description: string | null;
    acceptance_criteria: string[];
    impact: TaskImpact | null;
    impact_analyzed_at: string | null;
    token_budget: number | null;
    created_by: string | null;
    created_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    dependencies: TaskRef[];
    dependents: TaskRef[];
    comments: { id: number; kind: 'comment' | 'instruction' | 'changes_requested'; body: string; author: string | null; created_at: string | null }[];
    transitions: {
        id: number;
        from: TaskStatus | null;
        to: TaskStatus;
        actor: 'user' | 'system';
        user: string | null;
        reason: string | null;
        created_at: string | null;
    }[];
    workspace: Workspace | null;
}

export interface TaskOptions {
    types: { value: TaskCard['type']; label: string }[];
    priorities: { value: TaskPriority; label: string }[];
}
