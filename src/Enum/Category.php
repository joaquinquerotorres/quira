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

    /** Etiqueta española (catálogo / UI). */
    public function label(): string
    {
        return match ($this) {
            self::PLUMBING => 'Fontanería',
            self::ELECTRICITY => 'Electricidad',
            self::MASONRY => 'Albañilería',
            self::HVAC => 'Climatización',
            self::DIY => 'Manitas',
            self::PAINTING => 'Pintura',
            self::GARDENING => 'Jardinería',
            self::CLEANING => 'Limpieza',
            self::APPLIANCES => 'Electrodomésticos',
            self::MOVING => 'Mudanzas y Portes',
            self::LOCKSMITH => 'Cerrajería',
            self::POOL => 'Mantenimiento de Piscinas',
            self::SEWING => 'Costura y Arreglos',
            self::BLINDS => 'Persianas y Toldos',
            self::GLAZING => 'Cristalería',
            self::FURNITURE => 'Restauración de Muebles',
            self::CLEAROUT => 'Vaciado de Pisos',
            self::PEST_CONTROL => 'Control de Plagas',
            self::SMART_HOME => 'Domótica y Seguridad',
            self::BEAUTY => 'Belleza',
            self::PETS => 'Mascotas',
            self::CARE => 'Cuidados',
        };
    }

    public static function tryFromLabel(string $label): ?self
    {
        $needle = trim($label);
        foreach (self::cases() as $case) {
            if ($case->label() === $needle) {
                return $case;
            }
        }

        return null;
    }
}
