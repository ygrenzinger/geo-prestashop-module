<?php

use LlmsCatalog\Service\CacheManager;
use LlmsCatalog\Service\CacheRevisionStore;
use LlmsCatalog\Service\InvalidationService;
use PHPUnit\Framework\TestCase;

/** Requires the PrestaShop test-shop bootstrap and its primary database. */
final class PersistentFreshnessSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('Db') || !defined('_DB_PREFIX_')) $this->markTestSkipped('Requires a PrestaShop database bootstrap.');
    }

    public function testSchemaInstallIsIdempotentAndRevisionUpsertsDoNotLoseBumps(): void
    {
        self::assertTrue(CacheManager::installTables());
        self::assertTrue(CacheManager::installTables());
        $db = Db::getInstance();
        $id = 987654321;
        $table = '`' . _DB_PREFIX_ . 'llmscatalog_revision`';
        $db->execute("DELETE FROM $table WHERE id_shop=0 AND scope='settings' AND id_entity=$id");
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($db->execute("INSERT INTO $table (id_shop,scope,id_entity,revision,changed_at) VALUES (0,'settings',$id,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE revision=revision+1,changed_at=UTC_TIMESTAMP()"));
        }
        self::assertSame('5', (string) $db->getValue("SELECT revision FROM $table WHERE id_shop=0 AND scope='settings' AND id_entity=$id"));
        $db->execute("DELETE FROM $table WHERE id_shop=0 AND scope='settings' AND id_entity=$id");
    }

    public function testCacheDocumentTableHasStablePrimaryKey(): void
    {
        self::assertTrue(CacheManager::installTables());
        $indexes = Db::getInstance()->executeS('SHOW INDEX FROM `' . _DB_PREFIX_ . 'llmscatalog_cache_document`');
        self::assertIsArray($indexes);
        $primary = array_values(array_filter($indexes, static fn (array $row): bool => $row['Key_name'] === 'PRIMARY'));
        self::assertCount(1, $primary);
        self::assertSame('cache_key', $primary[0]['Column_name']);
    }

    public function testRevisionMatchingUsesPersistedColumnNames(): void
    {
        self::assertTrue(CacheManager::installTables());
        $shopId = (int) Context::getContext()->shop->id;
        $store = new CacheRevisionStore();
        $snapshot = $store->snapshotForWrite($shopId, 'index');
        $row = [
            'id_shop' => $shopId,
            'settings_revision' => $snapshot['settings'],
            'global_pricing_revision' => $snapshot['global_pricing'],
            'shop_index_revision' => $snapshot['index'],
            'shop_pricing_revision' => $snapshot['pricing'],
            'shop_routing_revision' => $snapshot['routing'],
            'product_revision' => $snapshot['product'],
        ];
        self::assertTrue($store->matches($row, 'index', 0));
        $row['shop_index_revision']++;
        self::assertFalse($store->matches($row, 'index', 0));
        unset($row['shop_index_revision']);
        self::assertFalse($store->matches($row, 'index', 0));
    }

    public function testProductInvalidationLeavesOtherProductRevisionUntouched(): void
    {
        self::assertTrue(CacheManager::installTables());
        $shop = 987654321;
        $product = 900000000; $other = 900000001;
        $read = static function (int $id) use ($shop): int {
            return (int) Db::getInstance()->getValue("SELECT revision FROM `" . _DB_PREFIX_ . "llmscatalog_revision` WHERE id_shop=$shop AND scope='product' AND id_entity=$id");
        };
        $beforeProduct = $read($product); $beforeOther = $read($other);
        (new InvalidationService())->invalidateProduct([$shop], $product, 'integration-test');
        self::assertSame($beforeProduct + 1, $read($product));
        self::assertSame($beforeOther, $read($other));
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "llmscatalog_revision` WHERE id_shop=$shop");
    }
}
