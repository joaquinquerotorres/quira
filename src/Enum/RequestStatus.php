<?php

declare(strict_types=1);

namespace App\Enum;

enum RequestStatus: string
{
    case PENDING = 'PENDING';         
    case ACCEPTED = 'ACCEPTED';        
    case COMPLETED = 'COMPLETED';     
    case PENDING_APPROVAL = 'PENDING_APPROVAL';      
}