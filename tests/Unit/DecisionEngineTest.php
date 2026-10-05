<?php

namespace Tests\Unit;

use App\Enums\ReasonCode;
use App\Enums\VerificationStatus;
use App\Models\VerificationPolicy;
use App\Services\DecisionEngine;
use App\Services\Extraction\ExtractionResult;
use App\Services\Matching\FieldMatch;
use Tests\TestCase;

class DecisionEngineTest extends TestCase
{
    private DecisionEngine $engine;
    private VerificationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DecisionEngine();
        $this->policy = VerificationPolicy::where('code', 'schoolrun_driver_identity')->firstOrFail();
    }

    private function extraction(array $fields = [], ?string $type = 'national_id', ?string $country = 'ZW', array $signals = []): ExtractionResult
    {
        return new ExtractionResult(
            provider: 'fake', providerVersion: '1', documentType: $type, country: $country,
            fields: $fields, fieldConfidences: [], extractionConfidence: 0.9, signals: $signals,
        );
    }

    private function match(array $fields, float $score = 0.95, bool $ambiguous = false): array
    {
        return [
            'fields' => $fields,
            'overall_score' => $score,
            'reasons' => [],
            'ambiguous' => $ambiguous,
        ];
    }

    private function fm(bool $match, string $reason): FieldMatch
    {
        return new FieldMatch('x', $match, 0.9, $reason);
    }

    public function test_all_match_verifies(): void
    {
        $m = $this->match([
            'first_name' => $this->fm(true, ReasonCode::FIRST_NAME_MATCH),
            'surname' => $this->fm(true, ReasonCode::SURNAME_MATCH),
            'date_of_birth' => $this->fm(true, ReasonCode::DATE_OF_BIRTH_MATCH),
        ]);
        $r = $this->engine->decide($this->policy, null, $m,
            [$this->extraction(['document_number' => '63-1'])],
            ['doc1' => ['score' => 0.9, 'problems' => []]]);
        $this->assertSame(VerificationStatus::Verified, $r['status']);
    }

    public function test_required_field_mismatch_fails(): void
    {
        $m = $this->match([
            'first_name' => $this->fm(true, ReasonCode::FIRST_NAME_MATCH),
            'surname' => $this->fm(false, ReasonCode::SURNAME_MISMATCH),
            'date_of_birth' => $this->fm(true, ReasonCode::DATE_OF_BIRTH_MATCH),
        ]);
        $r = $this->engine->decide($this->policy, null, $m,
            [$this->extraction()], ['d' => ['score' => 0.9, 'problems' => []]]);
        $this->assertSame(VerificationStatus::Failed, $r['status']);
        $this->assertContains(ReasonCode::SURNAME_MISMATCH, $r['reasons']);
    }

    public function test_ambiguous_goes_to_review(): void
    {
        $m = $this->match([
            'first_name' => $this->fm(true, ReasonCode::FIRST_NAME_MATCH),
            'surname' => $this->fm(true, ReasonCode::SURNAME_MATCH),
            'date_of_birth' => $this->fm(true, ReasonCode::DATE_OF_BIRTH_MATCH),
        ], ambiguous: true);
        $r = $this->engine->decide($this->policy, null, $m,
            [$this->extraction()], ['d' => ['score' => 0.9, 'problems' => []]]);
        $this->assertSame(VerificationStatus::ReviewRequired, $r['status']);
    }

    public function test_low_quality_fails_with_reupload_reason(): void
    {
        $m = $this->match([
            'first_name' => $this->fm(true, ReasonCode::FIRST_NAME_MATCH),
            'surname' => $this->fm(true, ReasonCode::SURNAME_MATCH),
            'date_of_birth' => $this->fm(true, ReasonCode::DATE_OF_BIRTH_MATCH),
        ]);
        $r = $this->engine->decide($this->policy, null, $m,
            [$this->extraction()], ['d' => ['score' => 0.2, 'problems' => ['too blurry']]]);
        $this->assertSame(VerificationStatus::Failed, $r['status']);
        $this->assertContains(ReasonCode::DOCUMENT_UNREADABLE, $r['reasons']);
    }

    public function test_document_type_mismatch_fails(): void
    {
        $m = $this->match([
            'first_name' => $this->fm(true, ReasonCode::FIRST_NAME_MATCH),
            'surname' => $this->fm(true, ReasonCode::SURNAME_MATCH),
            'date_of_birth' => $this->fm(true, ReasonCode::DATE_OF_BIRTH_MATCH),
        ]);
        $r = $this->engine->decide($this->policy, 'national_id', $m,
            [$this->extraction([], 'passport')], ['d' => ['score' => 0.9, 'problems' => []]]);
        $this->assertSame(VerificationStatus::Failed, $r['status']);
        $this->assertContains(ReasonCode::DOCUMENT_TYPE_MISMATCH, $r['reasons']);
    }

    public function test_disallowed_document_type_fails(): void
    {
        $m = $this->match([
            'first_name' => $this->fm(true, ReasonCode::FIRST_NAME_MATCH),
            'surname' => $this->fm(true, ReasonCode::SURNAME_MATCH),
            'date_of_birth' => $this->fm(true, ReasonCode::DATE_OF_BIRTH_MATCH),
        ]);
        $r = $this->engine->decide($this->policy, null, $m,
            [$this->extraction([], 'vehicle_document')], ['d' => ['score' => 0.9, 'problems' => []]]);
        $this->assertSame(VerificationStatus::Failed, $r['status']);
        $this->assertContains(ReasonCode::DOCUMENT_TYPE_NOT_ALLOWED, $r['reasons']);
    }

    public function test_expired_document_fails_when_policy_requires_current(): void
    {
        $m = $this->match([
            'first_name' => $this->fm(true, ReasonCode::FIRST_NAME_MATCH),
            'surname' => $this->fm(true, ReasonCode::SURNAME_MATCH),
            'date_of_birth' => $this->fm(true, ReasonCode::DATE_OF_BIRTH_MATCH),
        ]);
        $r = $this->engine->decide($this->policy, null, $m,
            [$this->extraction(['expiry_date' => '2020-01-01', 'document_number' => 'x'])],
            ['d' => ['score' => 0.9, 'problems' => []]]);
        $this->assertSame(VerificationStatus::Failed, $r['status']);
        $this->assertContains(ReasonCode::DOCUMENT_EXPIRED, $r['reasons']);
    }

    public function test_mrz_check_digit_mismatch_routes_to_review(): void
    {
        $m = $this->match([
            'first_name' => $this->fm(true, ReasonCode::FIRST_NAME_MATCH),
            'surname' => $this->fm(true, ReasonCode::SURNAME_MATCH),
            'date_of_birth' => $this->fm(true, ReasonCode::DATE_OF_BIRTH_MATCH),
        ]);
        $r = $this->engine->decide($this->policy, null, $m,
            [$this->extraction(['document_number' => 'x'], 'passport', 'ZW', ['mrz_check_digit_mismatch'])],
            ['d' => ['score' => 0.9, 'problems' => []]]);
        $this->assertSame(VerificationStatus::ReviewRequired, $r['status']);
        $this->assertContains(ReasonCode::RISK_FLAG, $r['reasons']);
    }

    public function test_score_between_thresholds_reviews(): void
    {
        $m = $this->match([
            'first_name' => $this->fm(true, ReasonCode::FIRST_NAME_MATCH),
            'surname' => $this->fm(true, ReasonCode::SURNAME_MATCH),
            'date_of_birth' => $this->fm(true, ReasonCode::DATE_OF_BIRTH_MATCH),
        ], score: 0.80);
        $r = $this->engine->decide($this->policy, null, $m,
            [$this->extraction()], ['d' => ['score' => 0.9, 'problems' => []]]);
        $this->assertSame(VerificationStatus::ReviewRequired, $r['status']);
    }
}
