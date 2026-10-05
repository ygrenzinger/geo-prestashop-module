<?php

namespace LlmsCatalog\Service;

use Db;

/** Owns the persistent cache and invalidation table shape. */
class CacheSchema
{
    public static function install(): bool
    {
        $db = Db::getInstance();
        $revision = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'llmscatalog_revision` (`id_shop` INT UNSIGNED NOT NULL, `scope` VARCHAR(16) CHARACTER SET ascii NOT NULL, `id_entity` INT UNSIGNED NOT NULL DEFAULT 0, `revision` BIGINT UNSIGNED NOT NULL DEFAULT 0, `changed_at` DATETIME NOT NULL, PRIMARY KEY (`id_shop`,`scope`,`id_entity`)) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
        $cache = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'llmscatalog_cache_document` (`cache_key` CHAR(64) CHARACTER SET ascii NOT NULL, `resource_type` VARCHAR(16) CHARACTER SET ascii NOT NULL, `id_shop` INT UNSIGNED NOT NULL, `id_lang` INT UNSIGNED NOT NULL, `id_product` INT UNSIGNED NOT NULL DEFAULT 0, `variant_hash` CHAR(64) CHARACTER SET ascii NOT NULL, `content` MEDIUMTEXT NOT NULL, `content_sha256` CHAR(64) CHARACTER SET ascii NOT NULL, `generated_at` DATETIME NOT NULL, `fresh_until` DATETIME NOT NULL, `stale_until` DATETIME NOT NULL, `settings_revision` BIGINT UNSIGNED NOT NULL, `global_pricing_revision` BIGINT UNSIGNED NOT NULL, `shop_index_revision` BIGINT UNSIGNED NOT NULL, `shop_pricing_revision` BIGINT UNSIGNED NOT NULL, `shop_routing_revision` BIGINT UNSIGNED NOT NULL, `product_revision` BIGINT UNSIGNED NOT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`cache_key`), KEY `shop_resource` (`id_shop`,`resource_type`,`id_product`), KEY `stale_until` (`stale_until`)) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return (bool) $db->execute($revision) && (bool) $db->execute($cache);
    }

    public static function drop(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'llmscatalog_cache_document`')
            && (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'llmscatalog_revision`');
    }
}
