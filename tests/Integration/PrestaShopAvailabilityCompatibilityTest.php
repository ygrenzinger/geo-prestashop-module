<?php

use PHPUnit\Framework\TestCase;

/** Core API contract checks for the supported PrestaShop matrix. */
final class PrestaShopAvailabilityCompatibilityTest extends TestCase
{
    public function testProductAwareStockAndOutOfStockPolicyApisExist(): void
    {
        if (!class_exists('Product') || !class_exists('StockAvailable')) {
            self::markTestSkipped('Requires a bootstrapped PrestaShop test shop.');
        }
        self::assertTrue(method_exists('Product', 'getQuantity'));
        self::assertTrue(method_exists('Product', 'isAvailableWhenOutOfStock'));
        self::assertTrue(method_exists('StockAvailable', 'outOfStock'));
        $quantity = new ReflectionMethod('Product', 'getQuantity');
        self::assertGreaterThanOrEqual(2, $quantity->getNumberOfParameters());
        $policy = new ReflectionMethod('Product', 'isAvailableWhenOutOfStock');
        self::assertSame(1, $policy->getNumberOfParameters());
    }
}
