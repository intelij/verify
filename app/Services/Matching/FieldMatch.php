<?php

namespace App\Services\Matching;

final class FieldMatch
{
    public function __construct(
        public readonly string $field,
        public readonly ?bool $match,       // null = not comparable
        public readonly float $confidence,  // 0..1
        public readonly string $reasonCode,
        public readonly ?string $expectedMasked = null,
        public readonly bool $provided = true,
    ) {}
}
