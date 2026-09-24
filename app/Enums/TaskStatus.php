<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case RevisionRequested = 'revision_requested';
    case ReadyToStart = 'ready_to_start';
    case InProgress = 'in_progress';
    case PendingCompletionApproval = 'pending_completion_approval';
    case Completed = 'completed';
    case NotCompleted = 'not_completed';
    case Rejected = 'rejected';
    case Closed = 'closed';
}
