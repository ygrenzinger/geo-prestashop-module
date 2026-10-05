<?php
use LlmsCatalog\Audit\RobotsInspector;
use PHPUnit\Framework\TestCase;
final class RobotsInspectorTest extends TestCase
{
    public function testUsesMostSpecificRuleAndReadsSitemap(): void
    {
        $text="User-agent: *\nDisallow: /\nAllow: /products/\nSitemap: https://shop.example/sitemap.xml\n";
        $inspector=new RobotsInspector();
        self::assertTrue($inspector->access($text,'Googlebot','/products/1'));
        self::assertFalse($inspector->access($text,'Googlebot','/private/'));
        self::assertSame('sitemap',$inspector->rules($text)[0]['rules'][2]['directive']);
    }
}
