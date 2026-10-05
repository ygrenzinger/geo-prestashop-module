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
}
