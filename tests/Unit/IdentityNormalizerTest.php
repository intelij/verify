<?php

namespace Tests\Unit;

use App\Services\Matching\IdentityNormalizer;
use PHPUnit\Framework\TestCase;

class IdentityNormalizerTest extends TestCase
{
    private IdentityNormalizer $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->n = new IdentityNormalizer();
    }

    public function test_case_whitespace_punctuation_normalization(): void
    {
        $this->assertSame('john moyo', $this->n->name('  JOHN   MOYO '));
        $this->assertSame('obrien', $this->n->name("O'Brien"));
        $this->assertSame('john moyo', $this->n->name("John\t  Moyo\n"));
    }

    public function test_diacritics_stripped(): void
    {
        $this->assertSame('ncube', $this->n->name('Ncubé'));
    }

    public function test_comma_reorder(): void
    {
        $this->assertSame('john moyo', $this->n->reorderCommaName('Moyo, John'));
    }

    public function test_date_normalization(): void
    {
        $this->assertSame('1990-04-15', $this->n->date('1990-04-15'));
        $this->assertSame('1990-04-15', $this->n->date('15 Apr 1990'));
        $this->assertNull($this->n->date('not a date'));
    }

    public function test_identifier_normalization(): void
    {
        $this->assertSame('631234567A89', $this->n->identifier('63-1234567A89'));
        $this->assertSame('631234567A89', $this->n->identifier('63 1234567A89'));
    }
}
