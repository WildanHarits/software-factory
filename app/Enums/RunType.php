<?php

namespace App\Enums;

enum RunType: string
{
    case SetupSpecKit = 'setup_speckit';
    case Constitution = 'constitution';

    public function label(): string
    {
        return match ($this) {
            self::SetupSpecKit => 'Set up Spec Kit',
            self::Constitution => 'Update constitution',
        };
    }
}
