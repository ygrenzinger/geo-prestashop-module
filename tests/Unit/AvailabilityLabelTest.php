<?php

use LlmsCatalog\Availability\AvailabilityFacts;
use LlmsCatalog\Availability\AvailabilityResolver;
use PHPUnit\Framework\TestCase;

final class AvailabilityLabelTest extends TestCase
{
    public function testEmptyMerchantLabelUsesStableFallbackAndReasonIsNotPublicLabel(): void
    {
        $result = (new AvailabilityResolver())->resolve(new AvailabilityFacts(false, false, true, true, 5, 1, false, null, 0, false, 'available_for_order_disabled'), ['not_for_sale' => '']);
        self::assertSame('Not available for order', $result->localizedLabel);
        self::assertSame('available_for_order_disabled', $result->reason);
        self::assertSame('https://schema.org/OutOfStock', $result->schemaUrl);
    }
}
