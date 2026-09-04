<?php

namespace App\Enums;

enum UserRole: string
{
    case Superadmin = 'superadmin';
    case President = 'president';
    case VpOperations = 'vp_ops';
    case RegionalManager = 'regional_manager';
    case NationalManager = 'national_manager';
    case ServiceCoordinator = 'service_coordinator';
    case AssistantCoordinator = 'assistant_coordinator';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Superadmin',
            self::President => 'President',
            self::VpOperations => 'VP Operations',
            self::RegionalManager => 'Regional Manager',
            self::NationalManager => 'National Manager',
            self::ServiceCoordinator => 'Service Coordinator',
            self::AssistantCoordinator => 'Assistant Coordinator',
            self::Assistant => 'Assistant',
        };
    }
}
