<?php
use LlmsCatalog\Audit\ProductOfferAuditor;
use LlmsCatalog\Audit\StructuredDataInspector;
use PHPUnit\Framework\TestCase;

final class ProductOfferAuditorTest extends TestCase
{
    private const URL='https://shop.example/p';

    public function testReportsMissingProductSchema(): void
    {
        $findings=(new ProductOfferAuditor())->audit([],self::URL,1,'en-US','2026-10-10T00:00:00Z');
        self::assertSame('PRODUCT_SCHEMA_MISSING',$findings[0]->code);
        self::assertTrue($findings[0]->augmentable);
    }

    public function testReportsMissingOfferFieldsForJsonLdProduct(): void
    {
        $product=['@type'=>'Product','url'=>self::URL,'offers'=>['@type'=>'Offer','price'=>'12.00']];
        $findings=(new ProductOfferAuditor())->audit([$product],self::URL,1,'en-US','2026-10-10T00:00:00Z');
        self::assertSame('PRODUCT_OFFER_FIELDS_MISSING',$findings[0]->code);
        self::assertSame(['priceCurrency','availability'],$findings[0]->observed);
    }

    public function testAcceptsCompleteMicrodataOrJsonLdOffer(): void
    {
        $product=['@type'=>['Product'],'url'=>self::URL,'offers'=>[['@type'=>'AggregateOffer','lowPrice'=>'10.00','highPrice'=>'20.00','priceCurrency'=>'EUR','availability'=>'https://schema.org/InStock']]];
        self::assertSame([],(new ProductOfferAuditor())->audit([$product],self::URL,1,'en-US','2026-10-10T00:00:00Z'));
    }

    public function testFlagsAmbiguousProductEntitiesWithoutCompleteOffers(): void
    {
        $products=[['@type'=>'Product','url'=>self::URL],['@type'=>'Product','url'=>self::URL]];
        $findings=(new ProductOfferAuditor())->audit($products,self::URL,1,'en-US','2026-10-10T00:00:00Z');
        self::assertSame('PRODUCT_SCHEMA_AMBIGUOUS',$findings[0]->code);
        self::assertFalse($findings[0]->augmentable);
    }

    public function testVariantProductRequiresAggregateOfferEvenWhenDefaultOfferIsComplete(): void
    {
        $product=['@type'=>'Product','url'=>self::URL,'offers'=>['@type'=>'Offer','price'=>'12.00','priceCurrency'=>'EUR','availability'=>'https://schema.org/InStock']];
        $findings=(new ProductOfferAuditor())->audit([$product],self::URL,1,'en-US','2026-10-10T00:00:00Z',true);
        self::assertSame('PRODUCT_VARIANT_RANGE_MISSING',$findings[0]->code);
    }

    public function testCompleteMicrodataProductOfferPassesAudit(): void
    {
        $html='<div itemscope itemtype="https://schema.org/Product"><link itemprop="url" href="'.self::URL.'"><div itemprop="offers" itemscope itemtype="https://schema.org/Offer"><meta itemprop="price" content="0"><meta itemprop="priceCurrency" content="EUR"><link itemprop="availability" href="https://schema.org/OutOfStock"></div></div>';
        $inspection=(new StructuredDataInspector())->inspect($html);
        self::assertSame([],(new ProductOfferAuditor())->audit($inspection['products'],self::URL,1,'en-US','2026-10-10T00:00:00Z'));
    }
}
