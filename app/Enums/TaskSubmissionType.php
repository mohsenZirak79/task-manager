<?php

namespace App\Enums;

enum TaskSubmissionType: string
{
    case Assignment = 'assignment';
    case Request = 'request';
}
