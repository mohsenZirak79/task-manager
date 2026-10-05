<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Inactive = 'inactive';
}
