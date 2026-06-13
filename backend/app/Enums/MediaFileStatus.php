<?php

namespace App\Enums;

enum MediaFileStatus: string
{
    case Available = 'available';
    case Missing = 'missing';
    case Error = 'error';
}
