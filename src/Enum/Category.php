<?php

declare(strict_types=1);

namespace App\Enum;

enum Category: string
{
    case CLEANING = 'CLEANING';
    case DIY = 'DIY';
    case ELECTRICITY = 'ELECTRICITY';
    case GARDENING = 'GARDENING';
    case PAINTING = 'PAINTING';
    case PLUMBING = 'PLUMBING';
    case HVAC = 'HVAC';
    case MASONRY = 'MASONRY';
    case APPLIANCES = 'APPLIANCES';
    case MOVING = 'MOVING';
    case LOCKSMITH = 'LOCKSMITH';
    case POOL = 'POOL';
    case SEWING = 'SEWING';
    case BLINDS = 'BLINDS';
    case GLAZING = 'GLAZING';
    case FURNITURE = 'FURNITURE';
    case CLEAROUT = 'CLEAROUT';
    case PEST_CONTROL = 'PEST_CONTROL';
    case SMART_HOME = 'SMART_HOME';
    case BEAUTY = 'BEAUTY';
    case PETS = 'PETS';
    case CARE = 'CARE';
}
