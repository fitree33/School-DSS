<?php

namespace App\Enums;

enum AiExtractionRunStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Superseded = 'superseded';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Queued => in_array($next, [self::Processing, self::Failed, self::Superseded], true),
            self::Processing => in_array($next, [self::Succeeded, self::Failed, self::Superseded], true),
            self::Failed => $next === self::Superseded,
            self::Succeeded, self::Superseded => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Superseded], true);
    }
}
