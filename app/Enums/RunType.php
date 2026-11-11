<?php

namespace App\Enums;

enum RunType: string
{
    case SetupSpecKit = 'setup_speckit';
    case Constitution = 'constitution';

    // Ticket phases (Spec Kit commands).
    case Specify = 'specify';
    case Clarify = 'clarify';
    case Plan = 'plan';
    case Tasks = 'tasks';
    case Analyze = 'analyze';
    case Implement = 'implement';

    // Merge the latest base branch into the feature branch, push, and open the pull request.
    case Publish = 'publish';

    public function label(): string
    {
        return match ($this) {
            self::SetupSpecKit => 'Set up Spec Kit',
            self::Constitution => 'Update constitution',
            self::Publish => 'Open pull request',
            default => ucfirst($this->value),
        };
    }

    public function isTicketPhase(): bool
    {
        return $this->stage() !== null;
    }

    public function stage(): ?TicketStage
    {
        return TicketStage::tryFrom($this->value);
    }
}
