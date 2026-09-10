<?php

namespace App\Enums;

enum DocumentImportProcessingStage: string
{
    case Validating = 'validating';
    case ExtractingText = 'extracting_text';
    case WaitingForAi = 'waiting_ai';
    case BuildingPreview = 'building_preview';
}
