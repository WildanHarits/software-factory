import type { RunSummary } from './runs';

export type TicketStageValue =
    | 'backlog'
    | 'specify'
    | 'clarify'
    | 'plan'
    | 'tasks'
    | 'analyze'
    | 'implement'
    | 'review'
    | 'done';

export type TicketStatusValue =
    | 'idle'
    | 'running'
    | 'awaiting_approval'
    | 'failed';

export type BoardColumn = {
    value: TicketStageValue;
    label: string;
    is_phase: boolean;
    optional: boolean;
};

export type TicketCard = {
    id: number;
    number: number;
    title: string;
    stage: TicketStageValue;
    status: TicketStatusValue;
    status_label: string;
    branch: string | null;
    model: string;
    model_label: string;
    effort: string;
    approval_mode: 'manual' | 'auto';
    cost_usd: number;
    pull_request_number: number | null;
    pull_request_url: string | null;
    pull_request_state: 'open' | 'merged' | 'closed' | null;
    updated_at: string | null;
};

export type TicketDocument = {
    path: string;
    name: string;
    content: string;
    updated_at: string | null;
};

export type TicketDetail = TicketCard & {
    description: string | null;
    stage_label: string;
    feature_dir: string | null;
    skip_clarify: boolean;
    skip_analyze: boolean;
    next_stage: TicketStageValue | null;
    next_stage_label: string | null;
    can_reply: boolean;
    pull_request_state_label: string | null;
    merged_at: string | null;
    merge_policy_label: string;
    created_by: string | null;
    created_at: string | null;
    last_run: RunSummary | null;
    last_message: string | null;
    documents: TicketDocument[];
    runs: RunSummary[];
};
