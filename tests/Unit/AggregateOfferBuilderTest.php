<?php
use LlmsCatalog\Service\AggregateOfferBuilder;
use PHPUnit\Framework\TestCase;

final class AggregateOfferBuilderTest extends TestCase
{
    public function testBuildsCompletePriceRangeAndAvailableState(): void
    {
        $result=(new AggregateOfferBuilder())->build('https://shop.example/p',[
            ['amount'=>12.5,'currency'=>'EUR','availability'=>'backorder'],
            ['amount'=>0,'currency'=>'EUR','availability'=>'in_stock'],
            ['amount'=>18.25,'currency'=>'EUR','availability'=>'out_of_stock'],
        ],'EUR',2,50);
        self::assertSame(['@type'=>'AggregateOffer','url'=>'https://shop.example/p','lowPrice'=>'0.00','highPrice'=>'18.25','priceCurrency'=>'EUR','availability'=>'https://schema.org/InStock','offerCount'=>3],$result);
    }

    public function testBackorderAvailabilityWinsWhenNoVariantIsInStock(): void
    {
        $result=(new AggregateOfferBuilder())->build('https://shop.example/p',[
            ['amount'=>12,'currency'=>'EUR','availability'=>'out_of_stock'],
            ['amount'=>15,'currency'=>'EUR','availability'=>'backorder'],
        ],'EUR',2,50);
        self::assertSame('https://schema.org/BackOrder',$result['availability']);
    }

    public function testRejectsPartialRangesMixedCurrencyAndOverLimitSets(): void
    {
        $builder=new AggregateOfferBuilder();
        self::assertNull($builder->build('https://shop.example/p',[], 'EUR',2,50));
        self::assertNull($builder->build('https://shop.example/p', [['amount'=>1,'currency'=>'USD','availability'=>'in_stock']], 'EUR',2,50));
        self::assertNull($builder->build('https://shop.example/p', [['amount'=>1,'currency'=>'EUR','availability'=>'in_stock'],['amount'=>2,'currency'=>'EUR','availability'=>'in_stock']], 'EUR',2,1));
        self::assertNull($builder->build('https://shop.example/p', [['amount'=>NAN,'currency'=>'EUR','availability'=>'in_stock']], 'EUR',2,50));
    }
}
