<?php

namespace LlmsCatalog\Availability;

final class AvailabilityResult
{
    private const SCHEMA = [
        'in_stock' => 'https://schema.org/InStock',
        'backorder' => 'https://schema.org/BackOrder',
        'out_of_stock' => 'https://schema.org/OutOfStock',
        'not_for_sale' => 'https://schema.org/OutOfStock',
    ];

    public function __construct(
        public readonly string $state,
        public readonly string $schemaUrl,
        public readonly string $localizedLabel,
        public readonly ?string $availabilityDate,
        public readonly int $selectedCombinationId,
        public readonly bool $otherVariantsOrderable,
        public readonly string $reason
    ) {
    }

    public static function create(string $state, string $label, ?string $date, int $combinationId, bool $otherVariants, string $reason): self
    {
        return new self($state, self::SCHEMA[$state], $label, $date, $combinationId, $otherVariants, $reason);
    }

    public static function schemaFor(string $state): string
    {
        return self::SCHEMA[$state] ?? self::SCHEMA['out_of_stock'];
    }
}
