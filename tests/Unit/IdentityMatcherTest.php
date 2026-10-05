<?php

namespace Tests\Unit;

use App\Enums\ReasonCode;
use App\Services\Matching\IdentityMatcher;
use App\Services\Matching\IdentityNormalizer;
use Tests\TestCase;

class IdentityMatcherTest extends TestCase
{
    private IdentityMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new IdentityMatcher(new IdentityNormalizer());
    }

    private function expected(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'John',
            'middle_names' => 'Tendai',
            'surname' => 'Moyo',
            'date_of_birth' => '1990-04-15',
            'national_id_number' => '63-1234567A89',
        ], $overrides);
    }

    public function test_exact_match(): void
    {
        $r = $this->matcher->compare($this->expected(), [
            'first_name' => 'John', 'surname' => 'Moyo',
            'date_of_birth' => '1990-04-15', 'document_number' => '63-1234567A89',
        ], ['first_name' => .9, 'surname' => .9, 'date_of_birth' => .9]);

        $this->assertTrue($r['fields']['first_name']->match);
        $this->assertTrue($r['fields']['surname']->match);
        $this->assertTrue($r['fields']['date_of_birth']->match);
        $this->assertFalse($r['ambiguous']);
    }

    public function test_case_and_whitespace_variations_match(): void
    {
        $r = $this->matcher->compare($this->expected(), [
            'first_name' => 'JOHN', 'surname' => '  MOYO ',
            'date_of_birth' => '15 April 1990',
        ]);
        $this->assertTrue($r['fields']['first_name']->match);
        $this->assertTrue($r['fields']['surname']->match);
        $this->assertTrue($r['fields']['date_of_birth']->match);
    }

    public function test_middle_name_in_extracted_first_name_matches(): void
    {
        $r = $this->matcher->compare($this->expected(), [
            'first_name' => 'John Tendai', 'surname' => 'Moyo',
        ]);
        $this->assertTrue($r['fields']['first_name']->match);
        $this->assertTrue($r['fields']['middle_names']->match);
    }

    public function test_middle_name_initial_matches(): void
    {
        $r = $this->matcher->compare($this->expected(), [
            'first_name' => 'John T', 'surname' => 'Moyo',
        ]);
        $this->assertTrue($r['fields']['middle_names']->match);
    }

    public function test_surname_mismatch_fails_and_is_not_fuzzy_approved(): void
    {
        $r = $this->matcher->compare($this->expected(), [
            'first_name' => 'John', 'surname' => 'Ncube',
        ]);
        $this->assertFalse($r['fields']['surname']->match);
        $this->assertContains(ReasonCode::SURNAME_MISMATCH, $r['reasons']);
        $this->assertFalse($r['ambiguous']);
    }

    public function test_first_name_mismatch(): void
    {
        $r = $this->matcher->compare($this->expected(), [
            'first_name' => 'Takudzwa', 'surname' => 'Moyo',
        ]);
        $this->assertFalse($r['fields']['first_name']->match);
        $this->assertContains(ReasonCode::FIRST_NAME_MISMATCH, $r['reasons']);
    }

    public function test_dob_mismatch(): void
    {
        $r = $this->matcher->compare($this->expected(), [
            'first_name' => 'John', 'surname' => 'Moyo', 'date_of_birth' => '1991-04-15',
        ]);
        $this->assertFalse($r['fields']['date_of_birth']->match);
        $this->assertContains(ReasonCode::DOB_MISMATCH, $r['reasons']);
    }

    public function test_document_number_mismatch(): void
    {
        $r = $this->matcher->compare($this->expected(), [
            'first_name' => 'John', 'surname' => 'Moyo', 'document_number' => '63-9999999A99',
        ]);
        $this->assertFalse($r['fields']['national_id_number']->match);
        $this->assertContains(ReasonCode::DOCUMENT_NUMBER_MISMATCH, $r['reasons']);
    }

    public function test_ambiguous_name_goes_to_review_not_approval(): void
    {
        // "tendat" vs expected first "john" fails; but a near-match surname triggers review.
        $r = $this->matcher->compare(
            $this->expected(['surname' => 'Moyo']),
            ['first_name' => 'John', 'surname' => 'Moyu'],
        );
        $this->assertNull($r['fields']['surname']->match);
        $this->assertTrue($r['ambiguous']);
        $this->assertContains(ReasonCode::MANUAL_REVIEW_REQUIRED, $r['reasons']);
    }

    public function test_missing_extracted_field_flags_review(): void
    {
        $r = $this->matcher->compare($this->expected(), []);
        $this->assertNull($r['fields']['first_name']->match);
        $this->assertTrue($r['ambiguous']);
    }

    public function test_comma_name_format(): void
    {
        // Document says "Moyo, John" — the normalizer reorders it before compare.
        $norm = new IdentityNormalizer();
        $this->assertSame('john moyo', $norm->reorderCommaName('Moyo, John'));
    }
}
