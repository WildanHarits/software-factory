<?php

namespace App\Enums;

/**
 * What is happening to a ticket inside its column.
 */
enum TicketStatus: string
{
    /** Waiting to start (backlog), or finished (review, done). */
    case Idle = 'idle';
    /** A run for this ticket is queued or working. */
    case Running = 'running';
    /** The phase finished; a person reviews, replies or approves the next phase. */
    case AwaitingApproval = 'awaiting_approval';
    /** The last run failed or was cancelled. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Idle',
            self::Running => 'Running',
            self::AwaitingApproval => 'Ready for review',
            self::Failed => 'Failed',
        };
    }
}
