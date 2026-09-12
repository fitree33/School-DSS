<?php

namespace App\Enums;

enum DocumentVersionCreatedVia: string
{
    case PhaseFiveConfirm = 'phase5_confirm';
    case LegacyUpload = 'legacy_upload';
    case Backfill = 'backfill';
}
