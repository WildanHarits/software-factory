import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import CredentialsManager from '@/components/credentials-manager';
import Heading from '@/components/heading';
import { edit } from '@/routes/teams';
import { index, store } from '@/routes/teams/tokens';
import type { CredentialProvider, CredentialSummary, Option } from '@/types';

type Props = {
    team: { name: string; slug: string };
    credentials: CredentialSummary[];
    providers: Option<CredentialProvider>[];
    canManage: boolean;
};

export default function TeamTokens({
    team,
    credentials,
    providers,
    canManage,
}: Props) {
    return (
        <>
            <Head title={`${team.name} tokens`} />

            <div className="space-y-6">
                <Link
                    href={edit(team.slug)}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> {team.name}
                </Link>

                <Heading
                    variant="small"
                    title="Team tokens"
                    description={
                        canManage
                            ? 'Shared GitHub, GitLab and Anthropic tokens for this team. Every member can use them on projects; only owners and admins can change them.'
                            : 'Shared tokens for this team. Only owners and admins can add or change them.'
                    }
                />

                <CredentialsManager
                    credentials={credentials}
                    providers={providers}
                    storeForm={store.form(team.slug)}
                    canManage={canManage}
                    emptyText="This team has no shared tokens yet."
                />
            </div>
        </>
    );
}

TeamTokens.layout = (props: Props) => ({
    breadcrumbs: [{ title: 'Team tokens', href: index(props.team.slug) }],
});
