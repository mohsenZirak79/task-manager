<?php

namespace App\Enums;

enum TaskParticipantRole: string
{
    case Assignee = 'assignee';
    case Follower = 'follower';
    case Supervisor = 'supervisor';
}
