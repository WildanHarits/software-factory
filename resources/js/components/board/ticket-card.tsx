import {
    GitMerge,
    GitPullRequest,
    GitPullRequestClosed,
    CircleAlert,
    CircleCheck,
    LoaderCircle,
    MessageCircleQuestion,
    Zap,
} from 'lucide-react';
import { formatCost } from '@/components/runs/run-status-badge';
import { cn } from '@/lib/utils';
import type { TicketCard as TicketCardType } from '@/types';

export function TicketStatusLine({ ticket }: { ticket: TicketCardType }) {
    if (ticket.status === 'running') {
        return (
            <span className="flex items-center gap-1 text-blue-700 dark:text-blue-300">
                <LoaderCircle className="size-3.5 animate-spin" />
                Running
            </span>
        );
    }

    if (ticket.status === 'failed') {
        return (
            <span className="flex items-center gap-1 text-red-700 dark:text-red-400">
                <CircleAlert className="size-3.5" />
                Needs attention
            </span>
        );
    }

    if (ticket.status === 'awaiting_approval') {
        return ticket.stage === 'clarify' ? (
            <span className="flex items-center gap-1 text-amber-700 dark:text-amber-300">
                <MessageCircleQuestion className="size-3.5" />
                Questions for you
            </span>
        ) : (
            <span className="flex items-center gap-1 text-green-700 dark:text-green-400">
                <CircleCheck className="size-3.5" />
                Ready for review
            </span>
        );
    }

    return null;
}

export default function TicketCard({
    ticket,
    dragging = false,
    onOpen,
}: {
    ticket: TicketCardType;
    dragging?: boolean;
    onOpen?: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onOpen}
            data-test="ticket-card"
            className={cn(
                'w-full space-y-2 rounded-lg border bg-background p-3 text-left text-sm shadow-xs transition hover:border-foreground/30',
                dragging && 'rotate-1 shadow-lg ring-2 ring-primary/40',
                ticket.status === 'running' &&
                    'border-blue-300 dark:border-blue-500/40',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <span className="font-medium">{ticket.title}</span>
                <span className="shrink-0 text-xs text-muted-foreground">
                    #{ticket.number}
                </span>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-muted-foreground">
                <TicketStatusLine ticket={ticket} />
                <span className="ml-auto flex items-center gap-2">
                    {ticket.approval_mode === 'auto' ? (
                        <Zap
                            className="size-3.5"
                            aria-label="Runs phases automatically"
                        />
                    ) : null}
                    <PullRequestBadge ticket={ticket} />
                    {ticket.cost_usd > 0 ? formatCost(ticket.cost_usd) : null}
                </span>
            </div>
        </button>
    );
}

export function PullRequestBadge({ ticket }: { ticket: TicketCardType }) {
    if (!ticket.pull_request_number) {
        return null;
    }

    const Icon =
        ticket.pull_request_state === 'merged'
            ? GitMerge
            : ticket.pull_request_state === 'closed'
              ? GitPullRequestClosed
              : GitPullRequest;

    return (
        <span
            className={cn(
                'flex items-center gap-0.5',
                ticket.pull_request_state === 'merged' &&
                    'text-purple-700 dark:text-purple-300',
                ticket.pull_request_state === 'open' &&
                    'text-green-700 dark:text-green-400',
            )}
            title={`Pull request ${ticket.pull_request_state ?? ''}`}
        >
            <Icon className="size-3.5" />#{ticket.pull_request_number}
        </span>
    );
}
