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

    public function testProductOfferFieldsAreAddedToOneCanonicalJsonLdEntity(): void
    {
        $html='<html><head><link rel="canonical" href="https://shop.example/p"><script type="application/ld+json">{"@type":"Product","@id":"https://shop.example/p#product","url":"https://shop.example/p","name":"Product","offers":{"@type":"Offer","price":"12.00"}}</script></head><body></body></html>';
        $result=(new StructuredDataAugmenter())->augmentProduct($html,$this->productCandidate(),true);
        self::assertSame(1,preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/',$result,$matches));
        $product=json_decode($matches[1],true,512,JSON_THROW_ON_ERROR);
        self::assertSame('Product',$product['@type']);
        self::assertSame('12.00',$product['offers']['price']);
        self::assertSame('EUR',$product['offers']['priceCurrency']);
        self::assertSame('https://schema.org/InStock',$product['offers']['availability']);
    }

    public function testIncompleteMicrodataIsSupplementedWithCompleteJsonLd(): void
    {
        $html='<html><head><link rel="canonical" href="https://shop.example/p"></head><body><div itemscope itemtype="https://schema.org/Product"><link itemprop="url" href="https://shop.example/p"></div></body></html>';
        $result=(new StructuredDataAugmenter())->augmentProduct($html,$this->productCandidate(),true);
        self::assertStringContainsString('application/ld+json',$result);
        $inspection=(new \LlmsCatalog\Audit\StructuredDataInspector())->inspect($result);
        self::assertCount(2,$inspection['products']);
        $jsonLd=array_values(array_filter($inspection['products'],static fn(array $entity):bool=>($entity['_format']??'')==='jsonld'));
        self::assertSame('12.00',$jsonLd[0]['offers']['price']);
    }

    public function testProductMarkupWithConflictingPriceIsNotOverwritten(): void
    {
        $html='<html><head><link rel="canonical" href="https://shop.example/p"><script type="application/ld+json">{"@type":"Product","url":"https://shop.example/p","offers":{"@type":"Offer","price":"99.00"}}</script></head><body></body></html>';
        self::assertSame($html,(new StructuredDataAugmenter())->augmentProduct($html,$this->productCandidate(),true));
    }

    public function testVariantRangeIsAddedAlongsideExistingDefaultOffer(): void
    {
        $html='<html><head><link rel="canonical" href="https://shop.example/p"><script type="application/ld+json">{"@type":"Product","url":"https://shop.example/p","offers":{"@type":"Offer","price":"12.00","priceCurrency":"EUR","availability":"https://schema.org/InStock"}}</script></head></html>';
        $candidate=$this->productCandidate();
        $candidate['offers']=['@type'=>'AggregateOffer','url'=>'https://shop.example/p','lowPrice'=>'10.00','highPrice'=>'14.00','priceCurrency'=>'EUR','availability'=>'https://schema.org/InStock','offerCount'=>3];
        $result=(new StructuredDataAugmenter())->augmentProduct($html,$candidate,true);
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/',$result,$matches);
        $product=json_decode($matches[1],true,512,JSON_THROW_ON_ERROR);
        self::assertCount(2,$product['offers']);
        self::assertSame('12.00',$product['offers'][0]['price']);
        self::assertSame('10.00',$product['offers'][1]['lowPrice']);
    }

    private function productCandidate(): array
    {
        return ['@context'=>'https://schema.org','@type'=>'Product','@id'=>'https://shop.example/p#product','url'=>'https://shop.example/p','name'=>'Product',
            'offers'=>['@type'=>'Offer','url'=>'https://shop.example/p','price'=>'12.00','priceCurrency'=>'EUR','availability'=>'https://schema.org/InStock']];
    }
}
