<?php
use LlmsCatalog\Audit\SitemapReader;
use PHPUnit\Framework\TestCase;
final class SitemapReaderTest extends TestCase
{
    public function testReadsLocationsAndLastModifiedWithoutNetworkResolution(): void
    {
        $xml='<urlset><url><loc>https://shop.example/p/1</loc><lastmod>2026-09-01</lastmod></url></urlset>';
        $result=(new SitemapReader())->read($xml,10);
        self::assertSame('https://shop.example/p/1',$result['items'][0]['url']);
        self::assertSame('2026-09-01',$result['items'][0]['lastmod']);
    }
    public function testDisablesExternalEntityResolution(): void
    {
        $xml='<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url></urlset>';
        self::assertSame([], (new SitemapReader())->read($xml)['items']);
    }
}
