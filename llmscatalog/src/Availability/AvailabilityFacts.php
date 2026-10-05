<?php

namespace LlmsCatalog\Availability;

/** Scalar inputs to the deterministic availability decision. */
final class AvailabilityFacts
{
    public function __construct(
        public readonly bool $availableForOrder,
        public readonly bool $catalogMode,
        public readonly bool $showPrice,
        public readonly bool $stockManagement,
        public readonly int $quantity,
        public readonly int $minimumQuantity = 1,
        public readonly bool $allowOutOfStock = false,
        public readonly ?string $availableDate = null,
        public readonly int $combinationId = 0,
        public readonly bool $otherVariantsOrderable = false,
        public readonly string $reason = ''
    ) {
    }
}
