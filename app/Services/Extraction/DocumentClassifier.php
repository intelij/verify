<?php

namespace App\Services\Extraction;

use App\Enums\DocumentType;

/**
 * Heuristic document-type detection from extracted text. Honest by design:
 * returns DocumentType::Unknown when evidence is weak rather than guessing.
 */
class DocumentClassifier
{
    /** keyword => type, ordered by specificity */
    private const TYPE_KEYWORDS = [
        DocumentType::Passport->value => [
            'passport', 'passeport', 'paspoort', 'type p<', 'republic.*passport',
        ],
        DocumentType::DriversLicense->value => [
            'driver.{0,3}licen[cs]e', 'driving licen[cs]e', 'licen[cs]e no',
            'class.{0,5}(learner|code)', 'dl no', 'permit',
        ],
        DocumentType::NationalId->value => [
            'national.{0,5}id(entit)?y', 'identity (card|document|number)',
            'id(entit)?y no', 'national registration', 'registr(ed|ation) no',
            'zimbabwe national', 'national id',
        ],
        DocumentType::ProofOfAddress->value => [
            'utility bill', 'proof of (residence|address)', 'account statement',
            'rates (invoice|statement)', 'water bill', 'electricity bill',
        ],
        DocumentType::VehicleDocument->value => [
            'vehicle registration', 'registration book', 'licen[cs]e disc',
            'chassis', 'engine number', 'certificate of registration',
        ],
    ];

    private const COUNTRY_HINTS = [
        'ZW' => ['zimbabwe', 'harare', 'bulawayo', 'zim\b', 'registr(?:ed|ation).{0,20}(zimbabwe|zw)'],
        'ZA' => ['south africa', 'rsa\b', 'republiek van suid-afrika', 'suid-afrika'],
        'ZM' => ['zambia', 'lusaka'],
        'KE' => ['kenya', 'nairobi'],
    ];

    public function classify(string $text, string $mimeType): DocumentClassification
    {
        $signals = [];
        $lower = mb_strtolower($text);

        // MRZ is a strong passport/travel-document signal.
        if (preg_match('/P[<A-Z][A-Z0-9<]{20,}/', $text)) {
            $signals[] = 'mrz_present';
            $country = $this->countryFromMrz($text);
            return new DocumentClassification(DocumentType::Passport->value, $country, 0.95, $signals);
        }

        $scores = [];
        foreach (self::TYPE_KEYWORDS as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('/' . $pattern . '/i', $lower)) {
                    $scores[$type] = ($scores[$type] ?? 0) + 1;
                    $signals[] = 'kw:' . strtok($pattern, ' ');
                }
            }
        }

        $country = $this->detectCountry($lower, $signals);

        if ($scores === []) {
            return new DocumentClassification(DocumentType::Unknown->value, $country, 0.1, $signals);
        }

        arsort($scores);
        $type = array_key_first($scores);
        $top = $scores[$type];
        $confidence = min(0.95, 0.5 + ($top * 0.15));

        // A single weak keyword is tentative.
        if ($top === 1) {
            $confidence = 0.55;
        }

        return new DocumentClassification($type, $country, $confidence, array_values(array_unique($signals)));
    }

    private function detectCountry(string $lower, array &$signals): ?string
    {
        foreach (self::COUNTRY_HINTS as $cc => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('/' . $pattern . '/i', $lower)) {
                    $signals[] = 'country:' . $cc;
                    return $cc;
                }
            }
        }
        return null;
    }

    private function countryFromMrz(string $text): ?string
    {
        if (preg_match('/P<([A-Z<]{3})/', $text, $m)) {
            $map = ['ZWE' => 'ZW', 'ZAF' => 'ZA', 'ZMB' => 'ZM', 'KEN' => 'KE'];
            return $map[$m[1]] ?? null;
        }
        return null;
    }
}
