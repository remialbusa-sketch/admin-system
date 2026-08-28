<?php

namespace App\Enums;

enum UserRole: string
{
    case Superadmin = 'superadmin';
    case President = 'president';
    case VpOperations = 'vp_ops';
    case RegionalManager = 'regional_manager';
    case NationalManager = 'national_manager';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Superadmin',
            self::President => 'President',
            self::VpOperations => 'VP Operations',
            self::RegionalManager => 'Regional Manager',
            self::NationalManager => 'National Manager',
        };
    }
}
