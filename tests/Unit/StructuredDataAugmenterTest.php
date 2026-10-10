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

    public function testAddsOrganizationContactPointAndPostalAddressWhenNoEntityExists(): void
    {
        $candidate = $this->organizationCandidate();
        $html = '<html><head><link rel="canonical" href="https://shop.example/"></head><body></body></html>';

        $result = (new StructuredDataAugmenter())->augment($html, $candidate, 'Organization', true);
        preg_match('/<script type="application\\/ld\\+json">(.*?)<\\/script>/', $result, $matches);
        $organization = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ContactPoint', $organization['contactPoint']['@type']);
        self::assertSame('customer service', $organization['contactPoint']['contactType']);
        self::assertSame('hello@shop.example', $organization['contactPoint']['email']);
        self::assertSame('+33 1 23 45 67 89', $organization['contactPoint']['telephone']);
        self::assertSame('PostalAddress', $organization['address']['@type']);
        self::assertSame('4 rue du Test, Bâtiment B', $organization['address']['streetAddress']);
        self::assertSame('Paris', $organization['address']['addressLocality']);
        self::assertSame('FR', $organization['address']['addressCountry']);
    }

    public function testFillsMissingOrganizationFieldsWithoutReplacingPopulatedValues(): void
    {
        $html = '<html><head><link rel="canonical" href="https://shop.example/">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","@id":"https://shop.example/#organization","url":"https://shop.example/","name":"Shop","contactPoint":{"@type":"ContactPoint","contactType":"customer service","telephone":"+33 9 00 00 00 00"},"address":{"@type":"PostalAddress","streetAddress":"Existing address","addressCountry":"FR"}}</script>'
            . '</head><body></body></html>';

        $result = (new StructuredDataAugmenter())->augment($html, $this->organizationCandidate(), 'Organization', true);
        preg_match('/<script type="application\\/ld\\+json">(.*?)<\\/script>/', $result, $matches);
        $organization = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('+33 9 00 00 00 00', $organization['contactPoint']['telephone']);
        self::assertSame('hello@shop.example', $organization['contactPoint']['email']);
        self::assertSame('Existing address', $organization['address']['streetAddress']);
        self::assertSame('Paris', $organization['address']['addressLocality']);
        self::assertSame('75001', $organization['address']['postalCode']);
    }

    public function testAddsGeneralContactPointAlongsideExistingSpecializedContact(): void
    {
        $candidate = $this->organizationCandidate();
        unset($candidate['address']);
        $html = '<html><head><link rel="canonical" href="https://shop.example/">'
            . '<script type="application/ld+json">{"@type":"Organization","url":"https://shop.example/","contactPoint":{"@type":"ContactPoint","contactType":"technical support","telephone":"+33 8 00 00 00 00"}}</script>'
            . '</head></html>';

        $result = (new StructuredDataAugmenter())->augment($html, $candidate, 'Organization', true);
        preg_match('/<script type="application\\/ld\\+json">(.*?)<\\/script>/', $result, $matches);
        $organization = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $organization['contactPoint']);
        self::assertSame('technical support', $organization['contactPoint'][0]['contactType']);
        self::assertSame('customer service', $organization['contactPoint'][1]['contactType']);
    }

    public function testDoesNotPatchAmbiguousOrUnmatchedOrganizationEntities(): void
    {
        $candidate = $this->organizationCandidate();
        $multiple = '<html><head><link rel="canonical" href="https://shop.example/">'
            . '<script type="application/ld+json">[{"@type":"Organization","url":"https://shop.example/"},{"@type":"Organization","url":"https://shop.example/#other"}]</script>'
            . '</head></html>';
        $unmatched = '<html><head><link rel="canonical" href="https://shop.example/">'
            . '<script type="application/ld+json">{"@type":"Organization","url":"https://other.example/"}</script>'
            . '</head></html>';

        self::assertSame($multiple, (new StructuredDataAugmenter())->augment($multiple, $candidate, 'Organization', true));
        self::assertSame($unmatched, (new StructuredDataAugmenter())->augment($unmatched, $candidate, 'Organization', true));
    }

    public function testLeavesAmbiguousNestedContactAndAddressValuesUnchanged(): void
    {
        $html = '<html><head><link rel="canonical" href="https://shop.example/">'
            . '<script type="application/ld+json">{"@type":"Organization","url":"https://shop.example/","contactPoint":[{"@type":"ContactPoint","contactType":"customer service","telephone":"111"},{"@type":"ContactPoint","contactType":"customer service","telephone":"222"}],"address":[{"@type":"PostalAddress","addressCountry":"FR"},{"@type":"PostalAddress","addressCountry":"BE"}]}</script>'
            . '</head></html>';

        self::assertSame($html, (new StructuredDataAugmenter())->augment($html, $this->organizationCandidate(), 'Organization', true));
    }

    public function testPatchesOrganizationInsideJsonLdGraph(): void
    {
        $html = '<html><head><link rel="canonical" href="https://shop.example/">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","@id":"https://shop.example/#organization","url":"https://shop.example/","name":"Shop"}]}</script>'
            . '</head></html>';

        $result = (new StructuredDataAugmenter())->augment($html, $this->organizationCandidate(), 'Organization', true);
        preg_match('/<script type="application\\/ld\\+json">(.*?)<\\/script>/', $result, $matches);
        $graph = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];

        self::assertSame('hello@shop.example', $graph[0]['contactPoint']['email']);
        self::assertSame('Paris', $graph[0]['address']['addressLocality']);
    }

    public function testAddsPostalAddressAlongsideExistingPlainTextAddress(): void
    {
        $html = '<html><head><link rel="canonical" href="https://shop.example/">'
            . '<script type="application/ld+json">{"@type":"Organization","url":"https://shop.example/","address":"4 rue du Test, Paris"}</script>'
            . '</head></html>';

        $result = (new StructuredDataAugmenter())->augment($html, $this->organizationCandidate(), 'Organization', true);
        preg_match('/<script type="application\\/ld\\+json">(.*?)<\\/script>/', $result, $matches);
        $organization = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('4 rue du Test, Paris', $organization['address'][0]);
        self::assertSame('PostalAddress', $organization['address'][1]['@type']);
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

    private function organizationCandidate(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => 'https://shop.example/#organization',
            'name' => 'Shop',
            'url' => 'https://shop.example/',
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'customer service',
                'email' => 'hello@shop.example',
                'telephone' => '+33 1 23 45 67 89',
            ],
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => '4 rue du Test, Bâtiment B',
                'postalCode' => '75001',
                'addressLocality' => 'Paris',
                'addressCountry' => 'FR',
            ],
        ];
    }
}
