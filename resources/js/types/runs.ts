export type RunStatus =
    | 'queued'
    | 'running'
    | 'cancelling'
    | 'succeeded'
    | 'failed'
    | 'cancelled';

export type RunSummary = {
    id: number;
    type:
        | 'setup_speckit'
        | 'constitution'
        | 'specify'
        | 'clarify'
        | 'plan'
        | 'tasks'
        | 'analyze'
        | 'implement'
        | 'publish';
    type_label: string;
    status: RunStatus;
    status_label: string;
    is_active: boolean;
    model: string | null;
    model_label: string | null;
    effort: string | null;
    branch: string | null;
    commit_sha: string | null;
    pull_request_number: number | null;
    pull_request_url: string | null;
    input_tokens: number;
    output_tokens: number;
    cache_read_tokens: number;
    cache_write_tokens: number;
    cost_usd: number;
    num_turns: number | null;
    error: string | null;
    ticket_id: number | null;
    is_reply: boolean;
    triggered_by: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
};

export type RunEventKind =
    | 'stage'
    | 'output'
    | 'system'
    | 'assistant'
    | 'tool'
    | 'tool_result'
    | 'result'
    | 'error';

export type RunEvent = {
    seq: number;
    kind: RunEventKind;
    title: string | null;
    body: string | null;
    at: string | null;
};

export type RunnerProblem = {
    key: string;
    label: string;
    ok: boolean;
    detail: string;
};
