<?php

namespace App\Enums;

/**
 * The board columns, in order.
 */
enum TicketStage: string
{
    case Backlog = 'backlog';
    case Specify = 'specify';
    case Clarify = 'clarify';
    case Plan = 'plan';
    case Tasks = 'tasks';
    case Analyze = 'analyze';
    case Implement = 'implement';
    case Review = 'review';
    case Done = 'done';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The Spec Kit command run in this column, if any.
     */
    public function isPhase(): bool
    {
        return ! in_array($this, [self::Backlog, self::Review, self::Done], true);
    }

    /**
     * Clarify and Analyze can be skipped; the other phases build on each other.
     */
    public function isOptional(): bool
    {
        return in_array($this, [self::Clarify, self::Analyze], true);
    }

    /**
     * Whether the phase changes files (and so ends with a commit). Analyze only reports.
     */
    public function changesFiles(): bool
    {
        return $this !== self::Analyze;
    }

    public function runType(): RunType
    {
        return match ($this) {
            self::Specify => RunType::Specify,
            self::Clarify => RunType::Clarify,
            self::Plan => RunType::Plan,
            self::Tasks => RunType::Tasks,
            self::Analyze => RunType::Analyze,
            self::Implement => RunType::Implement,
            default => throw new \LogicException("{$this->value} is not a phase."),
        };
    }

    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * @return array<int, array{value: string, label: string, is_phase: bool, optional: bool}>
     */
    public static function columns(): array
    {
        return array_map(fn (self $stage) => [
            'value' => $stage->value,
            'label' => $stage->label(),
            'is_phase' => $stage->isPhase(),
            'optional' => $stage->isOptional(),
        ], self::cases());
    }
}
