<?php

namespace App\Enums;

enum ApprovalMode: string
{
    case Manual = 'manual';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Wait for approval after each phase',
            self::Auto => 'Run the next phase automatically',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode) => ['value' => $mode->value, 'label' => $mode->label()], self::cases());
    }
}
