<?php

namespace App\Enums;

enum ReportActionType: string
{
    case NeedsAction = 'needs_action';
    case NeedsReview = 'needs_review';
    case NeedsInstruction = 'needs_instruction';
}
