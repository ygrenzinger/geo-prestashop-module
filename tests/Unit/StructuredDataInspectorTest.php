<?php
use LlmsCatalog\Audit\StructuredDataInspector;
use PHPUnit\Framework\TestCase;

final class StructuredDataInspectorTest extends TestCase
{
    public function testFindsProductInsideGraphAndArrayType(): void
    {
        $html = '<html><head><script type="application/ld+json">{"@graph":[{"@id":"x","@type":["Product","Thing"]}]}</script></head></html>';
        $result = (new StructuredDataInspector())->inspect($html);
        self::assertTrue($result['parseable']);
        self::assertTrue((new StructuredDataInspector())->hasType($result['entities'], ['Product']));
    }
    public function testMalformedJsonLdFailsClosed(): void
    {
        self::assertFalse((new StructuredDataInspector())->inspect('<script type="application/ld+json">{</script>')['parseable']);
    }

    public function testExtractsMicrodataProductOfferFields(): void
    {
        $html='<div itemscope itemtype="https://schema.org/Product"><link itemprop="url" href="https://shop.example/p"><div itemprop="offers" itemscope itemtype="https://schema.org/Offer"><meta itemprop="price" content="12.50"><meta itemprop="priceCurrency" content="EUR"><link itemprop="availability" href="https://schema.org/InStock"></div></div>';
        $result=(new StructuredDataInspector())->inspect($html);
        self::assertTrue($result['parseable']);
        self::assertCount(1,$result['products']);
        self::assertSame('12.50',$result['products'][0]['offers'][0]['price']);
        self::assertSame('EUR',$result['products'][0]['offers'][0]['priceCurrency']);
        self::assertSame('microdata',$result['products'][0]['_format']);
    }

    public function testRecognizesProductMicrodataWithoutAnOffer(): void
    {
        $result=(new StructuredDataInspector())->inspect('<div itemscope itemtype="https://schema.org/Product"><link itemprop="url" href="https://shop.example/p"></div>');
        self::assertCount(1,$result['products']);
        self::assertSame([],$result['products'][0]['offers']);
    }
}
