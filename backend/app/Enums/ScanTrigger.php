<?php

namespace App\Enums;

enum ScanTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Startup = 'startup';
}
