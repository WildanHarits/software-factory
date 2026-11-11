import { Head } from '@inertiajs/react';
import CredentialsManager from '@/components/credentials-manager';
import Heading from '@/components/heading';
import { index, store } from '@/routes/tokens';
import type { CredentialProvider, CredentialSummary, Option } from '@/types';

type Props = {
    credentials: CredentialSummary[];
    providers: Option<CredentialProvider>[];
};

export default function PersonalTokens({ credentials, providers }: Props) {
    return (
        <>
            <Head title="Personal tokens" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Personal tokens"
                    description="Your own GitHub, GitLab and Anthropic tokens. Only you can see and manage them, and you can pick them for projects in any of your teams."
                />

                <CredentialsManager
                    credentials={credentials}
                    providers={providers}
                    storeForm={store.form()}
                    canManage
                    emptyText="You haven't added any personal tokens. Team tokens are managed on each team's page."
                />
            </div>
        </>
    );
}

PersonalTokens.layout = {
    breadcrumbs: [{ title: 'Personal tokens', href: index() }],
};
