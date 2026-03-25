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
}