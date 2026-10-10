<?php
use LlmsCatalog\Service\StructuredDataAugmenter;
use PHPUnit\Framework\TestCase;

final class StructuredDataAugmenterTest extends TestCase
{
    public function testAddsOnlyMissingEntityAndOnlyWhenEnabled(): void
    {
        $augmenter = new StructuredDataAugmenter();
        $candidate = ['@context'=>'https://schema.org','@type'=>'Organization','name'=>'Shop'];
        $html = '<html><head><link rel="canonical" href="https://shop.example/"></head><body></body></html>';
        $candidate['url']='https://shop.example/';
        self::assertStringContainsString('application/ld+json', $augmenter->augment($html, $candidate, 'Organization', true));
        self::assertSame($html, $augmenter->augment($html, $candidate, 'Organization', false));
        $existing = '<html><head><link rel="canonical" href="https://shop.example/"><script type="application/ld+json">{"@type":"Organization"}</script></head></html>';
        self::assertSame($existing, $augmenter->augment($existing, $candidate, 'Organization', true));
    }

    public function testOrganizationDescriptionIsSerializedIntoJsonLd(): void
    {
        $candidate = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'Shop',
            'url' => 'https://shop.example/',
            'description' => 'A locally sourced description.',
        ];
        $html = '<html><head><link rel="canonical" href="https://shop.example/"></head><body></body></html>';

        $result = (new StructuredDataAugmenter())->augment($html, $candidate, 'Organization', true);
        self::assertSame(1, preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/', $result, $matches));
        self::assertSame('A locally sourced description.', json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR)['description']);
    }

    public function testMalformedExistingMarkupIsNeverChanged(): void
    {
        $html = '<html><head><link rel="canonical" href="https://shop.example/"><script type="application/ld+json">{</script></head></html>';
        self::assertSame($html, (new StructuredDataAugmenter())->augment($html, ['@type'=>'Organization','url'=>'https://shop.example/'], 'Organization', true));
    }
}
