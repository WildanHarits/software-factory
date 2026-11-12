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
                label="AI for agents"
                options={[
                    { value: 'none', label: 'None yet, add one later' },
                    {
                        value: 'subscription',
                        label: "Each member's own Claude subscription",
                    },
                    ...credentialOptions(options.aiCredentials),
                ]}
                defaultValue={
                    values.ai_uses_member_subscription
                        ? 'subscription'
                        : values.ai_credential_id
                          ? String(values.ai_credential_id)
                          : 'none'
                }
                help={
                    <>
                        A key (Anthropic API, Meta or Qwen) is shared by
                        everyone's runs on this project. With{' '}
                        <em>each member's own Claude subscription</em>, every
                        run uses the subscription of the person who starts it,
                        so each member adds theirs under{' '}
                        <Link
                            href={personalTokens()}
                            className="underline underline-offset-4"
                        >
                            personal tokens
                        </Link>
                        {options.hasSubscription
                            ? ' (yours is saved).'
                            : " (you haven't added yours yet)."}
                        {options.aiCredentials.length === 0 ? (
                            <>
                                {' '}
                                Keys are added in{' '}
                                <TokenLinks team={options.team} />.
                            </>
                        ) : null}{' '}
                        With Meta or Qwen, the Claude model you pick maps to
                        that provider's model of the same tier, and effort is
                        ignored.
                    </>
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
