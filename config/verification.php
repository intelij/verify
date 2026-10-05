<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extraction providers
    |--------------------------------------------------------------------------
    |
    | Ordered fallback chain. The first provider that can handle a document
    | is used; on transient failure the next is tried (subject to privacy
    | constraints in the consumer policy).
    |
    */
    'extraction_providers' => array_values(array_filter(explode(',', env('EXTRACTION_PROVIDERS', 'tesseract')))),

    'providers' => [
        'tesseract' => [
            'binary' => env('TESSERACT_BINARY', '/usr/local/bin/tesseract'),
            'language' => env('TESSERACT_LANGUAGE', 'eng'),
            'timeout' => (int) env('OCR_TIMEOUT', 60),
            'enabled' => env('TESSERACT_ENABLED', true),
        ],
        'aws_textract' => [
            'enabled' => env('TEXTRACT_ENABLED', false),
            'region' => env('AWS_DEFAULT_REGION'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Document intake
    |--------------------------------------------------------------------------
    */
    'storage_disk' => env('VERIFICATION_STORAGE_DISK', 'documents'),
    'max_upload_bytes' => (int) env('VERIFICATION_MAX_UPLOAD_BYTES', 12 * 1024 * 1024),
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'],
    'upload_url_ttl_minutes' => (int) env('VERIFICATION_UPLOAD_URL_TTL', 15),
    'documents_due_minutes' => (int) env('VERIFICATION_DOCUMENTS_DUE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    */
    'processing_timeout' => (int) env('VERIFICATION_TIMEOUT', 180),
    'max_attempts' => (int) env('VERIFICATION_MAX_ATTEMPTS', 3),
    'stale_processing_minutes' => (int) env('VERIFICATION_STALE_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Matching thresholds
    |--------------------------------------------------------------------------
    |
    | Field-level similarity required before a field is considered a match.
    | Overall confidence thresholds come from the verification policy.
    |
    */
    'matching' => [
        'name_similarity_threshold' => (float) env('MATCH_NAME_THRESHOLD', 0.92),
        'name_review_threshold' => (float) env('MATCH_NAME_REVIEW_THRESHOLD', 0.72),
        'ambiguous_is_review' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Country-specific document rules
    |--------------------------------------------------------------------------
    |
    | Only formats documented from public/authoritative sources are encoded.
    | Where a format is not known, number_format is null and no validation
    | is claimed.
    |
    */
    'countries' => [
        'ZW' => [
            'name' => 'Zimbabwe',
            'national_id' => [
                // Publicly documented format: 2-digit district, 6-7 digits,
                // check letter, 2 digits (e.g. 63-1234567A89 or 08123456A78).
                'number_format' => '/^\d{2}-?\d{6,7}\s?[A-Z]\s?\d{2}$/i',
                'number_example' => '63-1234567A89',
            ],
            'drivers_license' => [
                'number_format' => null,
            ],
        ],
        'ZA' => [
            'name' => 'South Africa',
            'national_id' => [
                // Publicly documented 13-digit ID: YYMMDDSSSSCAZ with Luhn check.
                'number_format' => '/^\d{13}$/',
                'number_example' => '9004154800089',
                'luhn' => true,
                'encodes_dob' => true,
            ],
        ],
        'ZM' => ['name' => 'Zambia', 'national_id' => ['number_format' => null]],
        'KE' => ['name' => 'Kenya', 'national_id' => ['number_format' => null]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'timeout' => (int) env('WEBHOOK_TIMEOUT', 15),
        'max_attempts' => (int) env('WEBHOOK_MAX_ATTEMPTS', 8),
        'signature_ttl_seconds' => (int) env('WEBHOOK_SIGNATURE_TTL', 300),
        // Backoff base in seconds: 30, 60, 120, ... capped.
        'backoff_base' => (int) env('WEBHOOK_BACKOFF_BASE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention / privacy
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'document_days' => (int) env('DOCUMENT_RETENTION_DAYS', 90),
        'extracted_data_days' => (int) env('EXTRACTED_DATA_RETENTION_DAYS', 180),
        'result_days' => (int) env('VERIFICATION_RESULT_RETENTION_DAYS', 730),
    ],
    'store_raw_ocr' => env('STORE_RAW_OCR', false),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    */
    'default_rate_limit' => (int) env('API_RATE_LIMIT', 60),
];
