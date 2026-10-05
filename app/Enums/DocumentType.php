<?php

namespace App\Enums;

enum DocumentType: string
{
    case NationalId = 'national_id';
    case DriversLicense = 'drivers_license';
    case Passport = 'passport';
    case ProofOfAddress = 'proof_of_address';
    case VehicleDocument = 'vehicle_document';
    case Other = 'other';
    case Unknown = 'unknown';

    public static function isSupported(string $type): bool
    {
        return in_array($type, array_column(self::cases(), 'value'), true);
    }
}
