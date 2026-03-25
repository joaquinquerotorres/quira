<?php

declare(strict_types=1);

namespace App\Enum;

enum RiskLevel: string
{
    case LOW = 'LOW';       
    case MEDIUM = 'MEDIUM'; 
    case HIGH = 'HIGH';    
}