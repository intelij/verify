<?php

namespace App\Support;

final class PiiMasker
{
    /**
     * Mask an identifier for safe display in logs/audit: ********1234
     */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $len = mb_strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4) . mb_substr($value, -4);
    }

    /**
     * Mask a personal name: J*** M*** (keeps first letters for ops readability).
     */
    public static function maskName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return collect(preg_split('/\s+/', trim($value)))
            ->map(fn ($t) => mb_substr($t, 0, 1) . str_repeat('*', max(1, mb_strlen($t) - 1)))
            ->implode(' ');
    }

    /**
     * Recursively mask sensitive keys in an array before logging.
     */
    public static function maskArray(array $data): array
    {
        $sensitiveKeys = [
            'national_id_number', 'passport_number', 'driver_license_number',
            'document_number', 'id_number', 'license_number', 'raw_text',
            'storage_path', 'file', 'image', 'content', 'webhook_secret',
            'secret', 'secret_hash',
        ];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::maskArray($value);
            } elseif (is_string($value) && in_array($key, $sensitiveKeys, true)) {
                $data[$key] = '[masked:' . mb_strlen($value) . ' chars]';
            }
        }

        return $data;
    }
}
