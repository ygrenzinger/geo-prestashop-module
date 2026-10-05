<?php
use LlmsCatalog\Audit\HtmlSignalsInspector;
use PHPUnit\Framework\TestCase;
final class HtmlSignalsInspectorTest extends TestCase
{
    public function testReportsCanonicalAndMissingImageAlternativeEvidence(): void
    {
        $html='<html><head><link rel="canonical" href="https://shop.example/p"></head><body><img src="/image.jpg"></body></html>';
        $result=(new HtmlSignalsInspector())->inspect($html,'https://shop.example/p','en-US');
        self::assertSame(1,$result['canonical_count']);
        self::assertSame('IMAGE_ALT_MISSING',$result['findings'][0]['code']);
    }
}
