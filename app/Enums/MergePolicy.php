<?php

namespace App\Enums;

enum MergePolicy: string
{
    case Manual = 'manual';
    case WhenChecksPass = 'when_checks_pass';
    case Immediate = 'immediate';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'A person merges the PR/MR',
            self::WhenChecksPass => 'Merge automatically when checks pass',
            self::Immediate => 'Merge immediately',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $policy) => ['value' => $policy->value, 'label' => $policy->label()], self::cases());
    }
}
