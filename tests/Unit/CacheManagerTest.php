<?php

use LlmsCatalog\Service\CacheManager;
use PHPUnit\Framework\TestCase;

final class CacheManagerTest extends TestCase
{
    public function testPriceTtlIsCappedAndPromotionExpiryShortensIt(): void
    {
        $cache = new CacheManager();
        self::assertSame(3600, $cache->boundedTtl(3600, true, null, 1000));
        self::assertSame(90, $cache->boundedTtl(3600, true, 1090, 1000));
        self::assertSame(60, $cache->boundedTtl(60, false, 1090, 1000));
    }
}
