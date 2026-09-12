<?php

namespace App\Enums;

enum DocumentVersionIntegrityBasis: string
{
    case RecordedSha256 = 'recorded_sha256';
    case ObservedSha256 = 'observed_sha256';
}
