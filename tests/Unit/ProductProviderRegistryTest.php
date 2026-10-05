<?php

use LlmsCatalog\Service\ProductProviderRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../llmscatalog/src/Service/ProductProviderRegistry.php';

final class ProductProviderRegistryTest extends TestCase
{
    public function testAcceptsOnlyMatchingPublicV1ContributionAndStripsMarkup(): void
    {
        $item = ['provider_id'=>'reviews.example','schema_version'=>'v1','section'=>'ratings','shop_id'=>2,'language_id'=>4,'product_id'=>9,'public'=>true,'text'=>'<b>4.8 out of 5</b>','url'=>'https://reviews.example/p/9','modified_at'=>'2026-09-20T10:00:00Z'];
        self::assertSame(['provider'=>'reviews.example','section'=>'ratings','text'=>'4.8 out of 5','url'=>'https://reviews.example/p/9','modified_at'=>'2026-09-20T10:00:00Z','used_fallback'=>false], ProductProviderRegistry::validateContribution($item,2,4,9,['reviews.example']));
    }

    public function testRejectsPrivateWrongScopeUnknownSchemaAndUnsafeUrl(): void
    {
        $item = ['provider_id'=>'reviews.example','schema_version'=>'v1','section'=>'ratings','shop_id'=>2,'language_id'=>4,'product_id'=>9,'public'=>true,'text'=>'4.8','url'=>'javascript:alert(1)'];
        self::assertNull(ProductProviderRegistry::validateContribution($item,2,4,9,['reviews.example']));
        $item['url']='https://reviews.example/p/9'; $item['product_id']=10;
        self::assertNull(ProductProviderRegistry::validateContribution($item,2,4,9,['reviews.example']));
        $item['product_id']=9; $item['internal_notes']='private';
        self::assertNull(ProductProviderRegistry::validateContribution($item,2,4,9,['reviews.example']));
        $item['public']=false; unset($item['internal_notes']);
        self::assertNull(ProductProviderRegistry::validateContribution($item,2,4,9,['reviews.example']));
    }
}
