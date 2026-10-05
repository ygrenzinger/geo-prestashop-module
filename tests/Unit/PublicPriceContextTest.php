<?php

use LlmsCatalog\ValueObject\PublicPriceContext;
use PHPUnit\Framework\TestCase;

final class PublicPriceContextTest extends TestCase
{
    public function testFingerprintIsStableAndChangesWithRepresentationInputs(): void
    {
        $a = new PublicPriceContext(['shop_id' => 1, 'currency_id' => 2, 'group_id' => 3]);
        $same = new PublicPriceContext(['shop_id' => 1, 'currency_id' => 2, 'group_id' => 3]);
        $different = new PublicPriceContext(['shop_id' => 1, 'currency_id' => 4, 'group_id' => 3]);
        self::assertSame($a->fingerprint(), $same->fingerprint());
        self::assertNotSame($a->fingerprint(), $different->fingerprint());
    }

    public function testFingerprintChangesForEveryPublicPriceDimension(): void
    {
        $base = [
            'currency_id' => 1, 'currency_iso' => 'EUR', 'currency_precision' => 2,
            'currency_conversion_rate' => '1', 'country_id' => 33, 'state_id' => 0,
            'postcode' => '', 'group_id' => 2, 'display_method' => 1,
            'show_price' => true, 'group_reduction' => 0.0, 'tax_enabled' => true,
            'tax_mode' => 'included', 'quantity' => 1, 'customer_id' => 0,
            'cart_id' => 0, 'default_combination' => true, 'adapter_schema' => 1,
        ];
        $fingerprint = (new PublicPriceContext($base))->fingerprint();
        foreach ($base as $key => $value) {
            $changed = $base;
            $changed[$key] = is_bool($value) ? !$value : (string) $value . '-changed';
            self::assertNotSame($fingerprint, (new PublicPriceContext($changed))->fingerprint(), $key);
        }
    }
}
