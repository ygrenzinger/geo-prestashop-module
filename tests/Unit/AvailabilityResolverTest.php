<?php

use LlmsCatalog\Availability\AvailabilityFacts;
use LlmsCatalog\Availability\AvailabilityResolver;
use PHPUnit\Framework\TestCase;

final class AvailabilityResolverTest extends TestCase
{
    private AvailabilityResolver $resolver;

    protected function setUp(): void { $this->resolver = new AvailabilityResolver(); }

    public function testMinimumQuantityAndOrderabilityPrecedence(): void
    {
        self::assertSame('in_stock', $this->resolve(3, 3)->state);
        self::assertSame('out_of_stock', $this->resolve(2, 3)->state);
        self::assertSame('not_for_sale', $this->resolver->resolve(new AvailabilityFacts(false, false, true, true, 99))->state);
        self::assertSame('not_for_sale', $this->resolver->resolve(new AvailabilityFacts(true, true, true, true, 99))->state);
        self::assertSame('not_for_sale', $this->resolver->resolve(new AvailabilityFacts(true, false, false, true, 99))->state);
    }

    public function testStockManagementAndBackorderPolicy(): void
    {
        self::assertSame('in_stock', $this->resolver->resolve(new AvailabilityFacts(true, false, true, false, -9))->state);
        self::assertSame('backorder', $this->resolver->resolve(new AvailabilityFacts(true, false, true, true, 0, 4, true))->state);
        self::assertSame('out_of_stock', $this->resolver->resolve(new AvailabilityFacts(true, false, true, true, -1, 1, false))->state);
    }

    public function testOnlyValidCurrentOrFutureBackorderDatesArePublished(): void
    {
        $base = [true, false, true, true, 0, 1, true];
        foreach ([null, '', 'bad', '2020-01-01'] as $date) self::assertNull($this->resolver->resolve(new AvailabilityFacts(...[...$base, $date]))->availabilityDate);
        self::assertSame(date('Y-m-d'), $this->resolver->resolve(new AvailabilityFacts(...[...$base, date('Y-m-d')]))->availabilityDate);
        self::assertNull($this->resolver->resolve(new AvailabilityFacts(true, false, true, true, 1, 1, true, date('Y-m-d')))->availabilityDate);
    }

    public function testStatesMapToStableSchemaUrlsAndLocalizedLabels(): void
    {
        $result = $this->resolver->resolve(new AvailabilityFacts(true, false, true, true, 1), ['in_stock' => 'Disponible']);
        self::assertSame('https://schema.org/InStock', $result->schemaUrl);
        self::assertSame('Disponible', $result->localizedLabel);
    }

    public function testDefaultCombinationRemainsOutOfStockWhenAnotherVariantIsOrderable(): void
    {
        $default = $this->resolver->resolve(new AvailabilityFacts(true, false, true, true, 0, 1, false, null, 17, true));
        self::assertSame('out_of_stock', $default->state);
        self::assertSame('https://schema.org/OutOfStock', $default->schemaUrl);
        self::assertSame(17, $default->selectedCombinationId);
        self::assertTrue($default->otherVariantsOrderable);
    }

    public function testMissingDefaultCombinationFailsClosed(): void
    {
        $result = $this->resolver->resolve(new AvailabilityFacts(true, false, true, true, 40, 1, true, null, 0, false, 'missing_default_combination'));
        self::assertSame('out_of_stock', $result->state);
        self::assertSame('missing_default_combination', $result->reason);
    }

    private function resolve(int $quantity, int $minimum)
    {
        return $this->resolver->resolve(new AvailabilityFacts(true, false, true, true, $quantity, $minimum));
    }
}
