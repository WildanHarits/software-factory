<?php

namespace App\Enums;

enum PullRequestState: string
{
    case Open = 'open';
    case Merged = 'merged';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Merged => 'Merged',
            self::Closed => 'Closed without merging',
        };
    }
}
