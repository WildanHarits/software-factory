import type { CredentialSummary, Option } from './credentials';

export type ProjectSummary = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    provider: 'github' | 'gitlab';
    provider_label: string;
    repo_full_name: string;
    repo_web_url: string;
    default_branch: string;
    repo_private: boolean;
    git_credential_id: number | null;
    ai_credential_id: number | null;
    git_credential: CredentialSummary | null;
    ai_credential: CredentialSummary | null;
    ai_uses_member_subscription: boolean;
    default_model: string;
    default_model_label: string;
    default_effort: string;
    default_effort_label: string;
    approval_mode: 'manual' | 'auto';
    approval_mode_label: string;
    merge_policy: 'manual' | 'when_checks_pass' | 'immediate';
    merge_policy_label: string;
    speckit_initialized: boolean;
    speckit_checked_at: string | null;
    created_by: string | null;
    created_at: string | null;
};

export type ProjectFormOptions = {
    gitCredentials: CredentialSummary[];
    aiCredentials: CredentialSummary[];
    /** Whether the signed-in person has saved a Claude subscription token. */
    hasSubscription: boolean;
    models: Option[];
    efforts: Option[];
    approvalModes: Option[];
    mergePolicies: Option[];
    team: { name: string; slug: string };
};

export type ProjectSettingsValues = {
    git_credential_id?: number | null;
    ai_credential_id?: number | null;
    ai_uses_member_subscription?: boolean;
    default_model: string;
    default_effort: string;
    approval_mode: string;
    merge_policy: string;
};
