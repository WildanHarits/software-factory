import { Link } from '@inertiajs/react';
import LabeledSelect from '@/components/projects/labeled-select';
import { index as personalTokens } from '@/routes/tokens';
import { index as teamTokens } from '@/routes/teams/tokens';
import type {
    CredentialSummary,
    Option,
    ProjectFormOptions,
    ProjectSettingsValues,
} from '@/types';

export function credentialOptions(credentials: CredentialSummary[]): Option[] {
    return credentials.map((credential) => ({
        value: String(credential.id),
        label: `${credential.name} · ${credential.provider_label} · ${credential.owner === 'personal' ? `personal (${credential.owner_label})` : 'team'}${credential.last_verified_at ? '' : ' · not working'}`,
    }));
}

type Props = {
    options: ProjectFormOptions;
    values: ProjectSettingsValues;
    errors: Record<string, string | undefined>;
};

/** AI key and the defaults every new ticket starts with. */
export function AgentDefaultsFields({ options, values, errors }: Props) {
    return (
        <div className="grid gap-6">
            <LabeledSelect
                id="ai_credential_id"
                label="Anthropic API key"
                options={[
                    { value: 'none', label: 'None yet, add one later' },
                    ...credentialOptions(options.aiCredentials),
                ]}
                defaultValue={
                    values.ai_credential_id
                        ? String(values.ai_credential_id)
                        : 'none'
                }
                help={
                    options.aiCredentials.length === 0 ? (
                        <>
                            No Anthropic keys yet. Add one in{' '}
                            <TokenLinks team={options.team} />. Agents can't run
                            without it.
                        </>
                    ) : (
                        'Used to run Claude Code for this project.'
                    )
                }
                error={errors.ai_credential_id}
            />

            <div className="grid gap-6 sm:grid-cols-2">
                <LabeledSelect
                    id="default_model"
                    label="Default model"
                    options={options.models}
                    defaultValue={values.default_model}
                    error={errors.default_model}
                />
                <LabeledSelect
                    id="default_effort"
                    label="Default effort"
                    options={options.efforts}
                    defaultValue={values.default_effort}
                    error={errors.default_effort}
                />
            </div>

            <p className="-mt-3 text-xs text-muted-foreground">
                Each ticket starts with these and can change them.
            </p>

            <LabeledSelect
                id="approval_mode"
                label="Between phases"
                options={options.approvalModes}
                defaultValue={values.approval_mode}
                help="Tickets can override this."
                error={errors.approval_mode}
            />

            <LabeledSelect
                id="merge_policy"
                label="When a feature is finished"
                options={options.mergePolicies}
                defaultValue={values.merge_policy}
                help="The PR/MR is always opened automatically. This decides who merges it."
                error={errors.merge_policy}
            />
        </div>
    );
}

export function TokenLinks({ team }: { team: { slug: string } }) {
    return (
        <>
            <Link
                href={teamTokens(team.slug)}
                className="underline underline-offset-4"
            >
                team tokens
            </Link>{' '}
            or{' '}
            <Link
                href={personalTokens()}
                className="underline underline-offset-4"
            >
                your personal tokens
            </Link>
        </>
    );
}
