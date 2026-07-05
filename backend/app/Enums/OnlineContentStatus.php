<?php

namespace App\Enums;

enum OnlineContentStatus: string
{
    case Ambiguous = 'ambiguous';
    case Error = 'error';
    case NotFound = 'not_found';
    case Pending = 'pending';
    case Ready = 'ready';
}
