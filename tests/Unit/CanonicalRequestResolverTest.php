<?php

use LlmsCatalog\Service\CanonicalRequestResolver;
use PHPUnit\Framework\TestCase;

final class CanonicalRequestResolverTest extends TestCase
{
    public function testCanonicalPathAccountsForShopBaseUriAndRejectsTrailingSlash(): void
    {
        $resolver = new CanonicalRequestResolver();
        self::assertTrue($resolver->canonicalPath('/shop/fr-FR/llms.txt', '/shop/', '/fr-FR/llms.txt'));
        self::assertFalse($resolver->canonicalPath('/shop/fr-FR/llms.txt/', '/shop/', '/fr-FR/llms.txt'));
    }

    public function testMalformedEscapesSeparatorsNulAndInvalidUtf8AreRejected(): void
    {
        $resolver = new CanonicalRequestResolver();
        self::assertFalse($resolver->validEncodedPath('/fr-FR/%2'));
        self::assertFalse($resolver->validEncodedPath('/fr-FR/a%2Fb'));
        self::assertFalse($resolver->validEncodedPath("/fr-FR/a%00b"));
        self::assertFalse($resolver->validEncodedPath('/fr-FR/%FF'));
    }

    public function testRoutingParametersAreRemovedButAnalyticsParametersRemain(): void
    {
        $resolver = new CanonicalRequestResolver();
        self::assertSame(['utm_source' => 'crawler', 'campaign' => ['a', 'b']], $resolver->preservedQuery([
            'fc' => 'module', 'llms_lang' => 'fr', 'id_product' => 42,
            'utm_source' => 'crawler', 'campaign' => ['a', 'b'],
        ]));
    }

    public function testProductIdIsBoundedAndNormalizedForRedirect(): void
    {
        $resolver = new CanonicalRequestResolver();
        self::assertTrue($resolver->validPositiveInteger('00042'));
        self::assertFalse($resolver->validPositiveInteger('0'));
        self::assertFalse($resolver->validPositiveInteger('-1'));
        self::assertFalse($resolver->validPositiveInteger('999999999999999999999999999999'));
    }
}
