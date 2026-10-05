<?php

use PHPUnit\Framework\TestCase;

/** Run inside the PrestaShop 9.0.2 / current 9.1.x CI matrix. */
final class PrestaShopPriceCompatibilityTest extends TestCase
{
    public function testCorePriceAdapterSignatureIncludesAllExplicitContextInputs(): void
    {
        if (!class_exists('Product')) {
            self::markTestSkipped('Requires a bootstrapped PrestaShop test shop.');
        }
        $method = new ReflectionMethod('Product', 'getPriceStatic');
        $names = array_map(static fn ($parameter) => $parameter->getName(), $method->getParameters());
        self::assertSame([
            'id_product', 'usetax', 'id_product_attribute', 'decimals', 'divisor', 'only_reduc',
            'usereduc', 'quantity', 'force_associated_tax', 'id_customer', 'id_cart', 'id_address',
            'specific_price_output', 'with_ecotax', 'use_group_reduction', 'context',
            'use_customer_price', 'id_customization',
        ], $names);
    }
}
