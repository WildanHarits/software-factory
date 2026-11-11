<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Cancelling = 'cancelling';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Still waiting or working; the page keeps refreshing.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Cancelling], true);
    }

    /**
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Queued, self::Running, self::Cancelling];
    }
}
