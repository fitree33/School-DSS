<?php

namespace App\Enums;

enum DocumentImportStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case NeedsReview = 'needs_review';
    case Confirmed = 'confirmed';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Uploaded => in_array($next, [self::Processing, self::Failed], true),
            self::Processing => in_array($next, [self::NeedsReview, self::Failed], true),
            self::Failed => $next === self::Processing,
            self::NeedsReview => $next === self::Confirmed,
            self::Confirmed => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Confirmed;
    }
}
