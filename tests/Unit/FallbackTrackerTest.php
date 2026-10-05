<?php

use LlmsCatalog\Service\FallbackTracker;
use LlmsCatalog\ValueObject\LocalizedField;
use PHPUnit\Framework\TestCase;

final class FallbackTrackerTest extends TestCase
{
    public function testFallbackIsAggregatedOnceOnlyForNonemptyEmittedFields(): void
    {
        $tracker = new FallbackTracker();
        $tracker->observe(new LocalizedField('Nom de produit', 2), 2);
        self::assertFalse($tracker->hasFallback());
        $tracker->observe(new LocalizedField('', 1), 2);
        self::assertFalse($tracker->hasFallback());
        $tracker->observe(new LocalizedField('Default-language name', 1), 2);
        self::assertTrue($tracker->hasFallback());
        $tracker->observeConfiguredFallback(true);
        self::assertTrue($tracker->hasFallback());
    }

    public function testModuleWordingFallbackDoesNotSetMerchantFallbackState(): void
    {
        $tracker = new FallbackTracker();
        $tracker->observe(new LocalizedField('Titre traduit', 2), 2);
        self::assertFalse($tracker->hasFallback());
    }
}
