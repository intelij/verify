<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\VerificationPolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        VerificationPolicy::upsert([
            [
                'code' => 'generic_identity',
                'name' => 'Generic identity verification',
                'description' => 'Any government photo ID; name + DOB match.',
                'allowed_document_types' => json_encode(['national_id', 'passport', 'drivers_license']),
                'required_fields' => json_encode(['first_name', 'surname']),
                'optional_fields' => json_encode(['date_of_birth', 'gender']),
                'verify_threshold' => 0.90,
                'review_threshold' => 0.70,
                'min_quality' => 0.40,
                'min_extraction_confidence' => 0.40,
                'require_not_expired' => false,
                'require_document_number' => false,
                'require_authenticity_pass' => false,
                'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'code' => 'schoolrun_driver_identity',
                'name' => 'SchoolRun driver identity',
                'description' => 'National ID or passport; name + DOB match; document must be current.',
                'allowed_document_types' => json_encode(['national_id', 'passport']),
                'required_fields' => json_encode(['first_name', 'surname', 'date_of_birth']),
                'optional_fields' => json_encode(['middle_names', 'gender', 'national_id_number', 'passport_number']),
                'verify_threshold' => 0.90,
                'review_threshold' => 0.70,
                'min_quality' => 0.50,
                'min_extraction_confidence' => 0.45,
                'require_not_expired' => true,
                'require_document_number' => true,
                'require_authenticity_pass' => false,
                'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'code' => 'schoolrun_driver_license',
                'name' => 'SchoolRun driver licence',
                'description' => 'Driver licence; name match + licence number extracted + not expired.',
                'allowed_document_types' => json_encode(['drivers_license']),
                'required_fields' => json_encode(['first_name', 'surname']),
                'optional_fields' => json_encode(['date_of_birth', 'driver_license_number']),
                'verify_threshold' => 0.88,
                'review_threshold' => 0.68,
                'min_quality' => 0.50,
                'min_extraction_confidence' => 0.45,
                'require_not_expired' => true,
                'require_document_number' => true,
                'require_authenticity_pass' => false,
                'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ], ['code'], ['name', 'description', 'allowed_document_types', 'required_fields',
            'optional_fields', 'verify_threshold', 'review_threshold', 'min_quality',
            'min_extraction_confidence', 'require_not_expired', 'require_document_number',
            'require_authenticity_pass', 'enabled', 'updated_at']);

        User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@schoolrun.space')],
            [
                'name' => 'Verification Admin',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'change-me-in-production')),
            ],
        );
    }
}
