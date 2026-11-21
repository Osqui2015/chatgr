<?php

namespace App\Enums;

/**
 * Roles users can choose for their personal profile.
 */
enum SexualRole: string
{
    case Activo = 'activo';
    case Versatil = 'versatil';
    case Pasivo = 'pasivo';

    public function label(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Versatil => 'Versátil',
            self::Pasivo => 'Pasivo',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
