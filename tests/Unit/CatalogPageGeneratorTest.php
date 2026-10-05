<?php

use LlmsCatalog\Service\CatalogPageGenerator;
use LlmsCatalog\Service\CatalogRepository;
use LlmsCatalog\Service\Settings;
use LlmsCatalog\Service\UrlBuilder;
use PHPUnit\Framework\TestCase;

final class CatalogPageGeneratorTest extends TestCase
{
    public function testProductCursorUsesCompactRowsAndLinksFromLastRenderedId(): void
    {
        $repository = new class extends CatalogRepository {
            public function __construct() {}
            public function eligibleProductCount(int $langId): int { return 3; }
            public function productCounts(int $langId,int $cursor,int $categoryId=0): array { return ['total'=>3,'remaining'=>4]; }
            public function productsAfter(int $langId, int $cursor, int $limit = 100): array { return [['id_product'=>2,'name'=>'A','link_rewrite'=>'a'], ['id_product'=>9,'name'=>'B','link_rewrite'=>'b'], ['id_product'=>20,'name'=>'C','link_rewrite'=>'c']]; }
            public function countAfter(int $cursor, bool $categories = false, int $categoryId = 0, int $langId = 0): int { return 1; }
        };
        $urls = new class extends UrlBuilder {
            public function __construct() {}
            public function baseLink(): string { return 'https://shop.test'; }
            public function canonicalLocale(int $langId): string { return 'en-US'; }
            public function indexUrl(int $langId): string { return 'https://shop.test/en-US/llms.txt'; }
            public function productMarkdownUrl(int $idProduct, string $rewrite, int $langId): string { return 'https://shop.test/en-US/llms/product/' . $idProduct . '/' . $rewrite . '.md'; }
        };
        $settings = new class extends Settings { public function maxBytes(): int { return 16384; } public function configVersion(): string { return '7'; } };
        $body = (new CatalogPageGenerator($repository, $urls, $settings, 'v7'))->products(1, 0);
        self::assertStringContainsString('Eligible records: 3.', $body);
        self::assertStringContainsString('/after/20.md', $body);
        self::assertStringNotContainsString('Price', $body);
        self::assertLessThanOrEqual(16384, strlen($body));
    }

    public function testStableTenThousandProductCursorWalkHasNoDuplicatesOrGaps(): void
    {
        $repository = new class extends CatalogRepository {
            public function __construct() {}
            public function eligibleProductCount(int $langId): int { return 10000; }
            public function productCounts(int $langId,int $cursor,int $categoryId=0): array { return ['total'=>10000,'remaining'=>max(0,10000-$cursor)]; }
            public function productsAfter(int $langId, int $cursor, int $limit = 100): array {
                $rows=[]; for($id=$cursor+1;$id<=min(10000,$cursor+101);$id++)$rows[]=['id_product'=>$id,'name'=>'Product '.$id,'link_rewrite'=>'product-'.$id]; return $rows;
            }
            public function countAfter(int $cursor, bool $categories = false, int $categoryId = 0, int $langId = 0): int { return max(0,10000-$cursor); }
        };
        $urls = new class extends UrlBuilder {
            public function __construct() {}
            public function baseLink(): string { return 'https://shop.test'; }
            public function canonicalLocale(int $langId): string { return 'en-US'; }
            public function indexUrl(int $langId): string { return 'https://shop.test/en-US/llms.txt'; }
            public function productMarkdownUrl(int $idProduct, string $rewrite, int $langId): string { return 'https://shop.test/en-US/llms/product/' . $idProduct . '/' . $rewrite . '.md'; }
        };
        $settings = new class extends Settings { public function maxBytes(): int { return 16384; } public function configVersion(): string { return 'test'; } };
        $generator=new CatalogPageGenerator($repository,$urls,$settings,'fixture-v1'); $seen=[]; $cursor=0;
        do {
            $body=$generator->products(1,$cursor);
            preg_match_all('~https://shop\\.test/en-US/llms/product/(\\d+)/~',$body,$matches);
            foreach($matches[1] as $id){self::assertArrayNotHasKey((int)$id,$seen);$seen[(int)$id]=true;}
            preg_match('~catalog/products/after/(\\d+)\\.md~',$body,$next);
            $cursor=isset($next[1])?(int)$next[1]:0;
        } while($cursor>0);
        self::assertCount(10000,$seen);
        self::assertSame(range(1,10000),array_keys($seen));
    }

    public function testUnicodeLabelsAndForwardLinkStayWithinSmallByteBudget(): void
    {
        $repository = new class extends CatalogRepository {
            public function __construct() {}
            public function eligibleProductCount(int $langId): int { return 101; }
            public function productCounts(int $langId,int $cursor,int $categoryId=0): array { return ['total'=>101,'remaining'=>101]; }
            public function productsAfter(int $langId, int $cursor, int $limit = 100): array { $rows=[]; for($id=1;$id<=101;$id++)$rows[]=['id_product'=>$id,'name'=>str_repeat('é',200),'link_rewrite'=>'item-'.$id]; return $rows; }
            public function countAfter(int $cursor, bool $categories = false, int $categoryId = 0, int $langId = 0): int { return 101; }
        };
        $urls = new class extends UrlBuilder {
            public function __construct() {}
            public function baseLink(): string { return 'https://shop.test'; }
            public function canonicalLocale(int $langId): string { return 'en-US'; }
            public function indexUrl(int $langId): string { return 'https://shop.test/en-US/llms.txt'; }
            public function productMarkdownUrl(int $idProduct, string $rewrite, int $langId): string { return 'https://shop.test/en-US/llms/product/' . $idProduct . '/' . $rewrite . '.md'; }
        };
        $settings = new class extends Settings { public function maxBytes(): int { return 1024; } };
        $body=(new CatalogPageGenerator($repository,$urls,$settings,'fixture-v1'))->products(1,0);
        self::assertLessThanOrEqual(1024,strlen($body));
        self::assertSame(1,preg_match('//u',$body));
        self::assertMatchesRegularExpression('~catalog/products/after/\\d+\\.md~',$body);
    }
}
