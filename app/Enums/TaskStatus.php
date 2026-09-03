<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case RevisionRequested = 'revision_requested';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case NotCompleted = 'not_completed';
    case Rejected = 'rejected';
}
