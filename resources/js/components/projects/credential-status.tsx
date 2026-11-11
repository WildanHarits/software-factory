import { CircleAlert, CircleCheck } from 'lucide-react';
import type { CredentialSummary } from '@/types';

/** One line saying which token is in use and whether it works. */
export default function CredentialStatus({
    credential,
    missing,
}: {
    credential: CredentialSummary | null;
    missing: string;
}) {
    if (!credential) {
        return (
            <p className="flex items-start gap-1.5 text-sm text-amber-700 dark:text-amber-400">
                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                {missing}
            </p>
        );
    }

    const works = credential.last_verified_at !== null;

    return (
        <p
            className={
                works
                    ? 'flex items-start gap-1.5 text-sm'
                    : 'flex items-start gap-1.5 text-sm text-red-700 dark:text-red-400'
            }
        >
            {works ? (
                <CircleCheck className="mt-0.5 size-4 shrink-0 text-green-600 dark:text-green-400" />
            ) : (
                <CircleAlert className="mt-0.5 size-4 shrink-0" />
            )}
            <span>
                {credential.name}{' '}
                <span className="text-muted-foreground">
                    (
                    {credential.owner === 'personal'
                        ? `personal token of ${credential.owner_label}`
                        : 'team token'}
                    {works ? '' : ', not working'})
                </span>
            </span>
        </p>
    );
}
