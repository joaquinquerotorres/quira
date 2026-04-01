<?php

declare(strict_types=1);

namespace App\Enum;

enum BidStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case COMPLETED = 'COMPLETED';
}