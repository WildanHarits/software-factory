import {
    CircleCheck,
    CircleSlash,
    CircleX,
    Clock,
    LoaderCircle,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { RunStatus } from '@/types';

const STYLES: Record<RunStatus, string> = {
    queued: 'bg-muted text-muted-foreground',
    running: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    cancelling:
        'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    succeeded:
        'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
    cancelled: 'bg-muted text-muted-foreground',
};

const ICONS = {
    queued: Clock,
    running: LoaderCircle,
    cancelling: LoaderCircle,
    succeeded: CircleCheck,
    failed: CircleX,
    cancelled: CircleSlash,
} satisfies Record<RunStatus, unknown>;

export default function RunStatusBadge({
    status,
    label,
}: {
    status: RunStatus;
    label: string;
}) {
    const Icon = ICONS[status];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium',
                STYLES[status],
            )}
        >
            <Icon
                className={cn(
                    'size-3.5',
                    (status === 'running' || status === 'cancelling') &&
                        'animate-spin',
                )}
            />
            {label}
        </span>
    );
}

export function formatCost(cost: number): string {
    return cost > 0 && cost < 0.01 ? '<$0.01' : `$${cost.toFixed(2)}`;
}

export function formatDuration(
    startedAt: string | null,
    finishedAt: string | null,
): string | null {
    if (!startedAt) {
        return null;
    }

    const end = finishedAt ? new Date(finishedAt) : new Date();
    const seconds = Math.max(
        0,
        Math.round((end.getTime() - new Date(startedAt).getTime()) / 1000),
    );

    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    return minutes < 60
        ? `${minutes}m ${seconds % 60}s`
        : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}
