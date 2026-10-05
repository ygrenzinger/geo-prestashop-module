<?php

use LlmsCatalog\Service\PriceFormatter;
use LlmsCatalog\ValueObject\PublicPriceContext;
use PHPUnit\Framework\TestCase;

final class PriceFormatterTest extends TestCase
{
    public function testFormatterUsesRequestedLocaleAndCanonicalCurrency(): void
    {
        $seen = [];
        $formatter = new PriceFormatter(static function (float $amount, string $locale, string $currency) use (&$seen): string {
            $seen = [$amount, $locale, $currency];
            return '1 234,56 €';
        });
        $context = new PublicPriceContext(['locale' => 'fr-FR', 'currency_iso' => 'EUR']);
        self::assertSame('1 234,56 €', $formatter->format(1234.56, $context));
        self::assertSame([1234.56, 'fr-FR', 'EUR'], $seen);
    }
}
