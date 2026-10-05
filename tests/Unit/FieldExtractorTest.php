<?php

namespace Tests\Unit;

use App\Services\Extraction\DocumentClassification;
use App\Services\Extraction\FieldExtractor;
use Tests\TestCase;

class FieldExtractorTest extends TestCase
{
    private FieldExtractor $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = new FieldExtractor();
    }

    private function classification(?string $type = 'national_id', ?string $country = 'ZW'): DocumentClassification
    {
        return new DocumentClassification($type, $country, 0.9);
    }

    public function test_label_extraction(): void
    {
        $text = "NATIONAL IDENTITY CARD\nSURNAME: MOYO\nFIRST NAME: JOHN TENDAI\nDATE OF BIRTH: 15 APR 1990\nID NUMBER: 63-1234567A89\nSEX: M";
        $r = $this->fx->extract($text, $this->classification());

        $this->assertSame('MOYO', $r['fields']['surname']);
        $this->assertSame('JOHN TENDAI', $r['fields']['first_name']);
        $this->assertSame('1990-04-15', $r['fields']['date_of_birth']);
        $this->assertSame('63-1234567A89', $r['fields']['document_number']);
        $this->assertSame('M', $r['fields']['gender']);
    }

    public function test_mrz_parsing_extracts_identity(): void
    {
        // Synthetic TD3 MRZ (ICAO 9303 fixture, not a real person).
        // Line1: P<ZWE + names; Line2 fields padded to 44.
        $l1 = 'P<ZWEMOYO<<JOHN<TENDAI<<<<<<<<<<<<<<<<<<<<';
        // docnum 63X123456 + cd7 + ZWE + dob 900415 + cd4 + M + exp 300415 + cd5 + pad + composite cd
        $l2 = 'P12345678' . '7' . 'ZWE' . '900415' . '4' . 'M' . '300415' . '5' . '<<<<<<<<<<<<<<0';
        $l1 = str_pad(substr($l1, 0, 44), 44, '<');
        $l2 = str_pad(substr($l2, 0, 44), 44, '<');

        $r = $this->fx->extract("SOME TEXT\n$l1\n$l2\nMORE", $this->classification('passport'));

        $this->assertContains('mrz_parsed', $r['signals']);
        $this->assertSame('MOYO', $r['fields']['surname']);
        $this->assertSame('JOHN TENDAI', $r['fields']['first_name']);
        $this->assertSame('1990-04-15', $r['fields']['date_of_birth']);
        $this->assertSame('2030-04-15', $r['fields']['expiry_date']);
        $this->assertSame('P12345678', $r['fields']['document_number']);
        $this->assertSame('male', $r['fields']['gender']);
    }

    public function test_no_fields_from_garbage(): void
    {
        $r = $this->fx->extract('asdf qwer zzzz nothing readable here', $this->classification('unknown', null));
        $this->assertArrayNotHasKey('surname', $r['fields']);
        $this->assertArrayNotHasKey('date_of_birth', $r['fields']);
    }

    public function test_expiry_date_label(): void
    {
        $text = "DRIVING LICENCE\nLICENCE NO: 12345AB\nEXPIRY DATE: 15 APR 2030";
        $r = $this->fx->extract($text, $this->classification('drivers_license'));
        $this->assertSame('2030-04-15', $r['fields']['expiry_date']);
        $this->assertSame('12345AB', $r['fields']['license_number']);
    }
}
