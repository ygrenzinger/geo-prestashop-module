<?php

namespace LlmsCatalog\Service;

use Configuration;
use Context;
use Language;
use ShopGroup;

/** Owns stable cache identity construction; key parts are part of cache compatibility. */
class CacheKeyFactory
{
    public function __construct(private Context $context)
    {
    }

    public function create(string $type, int $langId, Settings $settings, string $priceFingerprint, int $productId = 0, string $localizationFingerprint = '', int $cursor = 0, int $categoryId = 0): string
    {
        $context = $this->context; $shopId = (int) $context->shop->id; $groupId = (int) $context->shop->id_shop_group;
        $lang = new \Language($langId); $defaultId = (int) Configuration::get('PS_LANG_DEFAULT', null, $groupId, $shopId); $default = new \Language($defaultId);
        $shopGroup = new \ShopGroup($groupId); $stockScope = !empty($shopGroup->share_stock) ? 'group:' . $groupId : 'shop:' . $shopId;
        $availability = [Configuration::get('PS_STOCK_MANAGEMENT', null, $groupId, $shopId), Configuration::get('PS_ORDER_OUT_OF_STOCK', null, $groupId, $shopId), Configuration::get('PS_PACK_STOCK_TYPE', null, $groupId, $shopId), Configuration::get('PS_CATALOG_MODE', null, $groupId, $shopId), Configuration::get('PS_LABEL_IN_STOCK_PRODUCTS', $langId, $groupId, $shopId), Configuration::get('PS_LABEL_OOS_PRODUCTS_BOA', $langId, $groupId, $shopId), Configuration::get('PS_LABEL_OOS_PRODUCTS_BOD', $langId, $groupId, $shopId), $settings->includesAvailability(), $settings->includesPrice()];
        return 'llmscatalog_v5_' . hash('sha256', json_encode(['schema-5', $type, $shopId, $langId, $type === 'product' ? $productId : 0, $categoryId, $cursor, (string) $lang->locale, $defaultId, (string) $default->locale, $stockScope, $priceFingerprint, $availability, $localizationFingerprint, $settings->completeIndexes()]));
    }
}
