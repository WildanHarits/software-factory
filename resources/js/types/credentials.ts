export type CredentialProvider =
    | 'github'
    | 'gitlab'
    | 'anthropic'
    | 'claude_subscription'
    | 'meta'
    | 'qwen';

export type Option<T extends string = string> = {
    value: T;
    label: string;
};

export type CredentialSummary = {
    id: number;
    name: string;
    provider: CredentialProvider;
    provider_label: string;
    owner: 'team' | 'personal';
    owner_label: string;
    token_hint: string;
    base_url: string | null;
    account_name: string | null;
    scopes: string[] | null;
    last_verified_at: string | null;
    last_error: string | null;
    projects_count?: number;
};
