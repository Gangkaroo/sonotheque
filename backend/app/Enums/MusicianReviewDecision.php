<?php

namespace App\Enums;

enum MusicianReviewDecision: string
{
    case Dismissed = 'dismissed';
    case NoSuitableMatch = 'no_suitable_match';
}
