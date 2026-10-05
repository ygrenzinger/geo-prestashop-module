<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Idempotent localization and public-context upgrade; preserves scoped rollback rows. */
function upgrade_module_1_1_0($module): bool
{
    $hooks = [
        'actionPresentProduct',
        'actionUpdateQuantity',
        'actionObjectProductAddAfter', 'actionObjectProductUpdateAfter', 'actionObjectProductDeleteAfter',
        'actionObjectSpecificPriceAddAfter', 'actionObjectSpecificPriceUpdateAfter', 'actionObjectSpecificPriceDeleteAfter',
        'actionObjectSpecificPriceRuleAddAfter', 'actionObjectSpecificPriceRuleUpdateAfter', 'actionObjectSpecificPriceRuleDeleteAfter',
        'actionObjectCurrencyAddAfter', 'actionObjectCurrencyUpdateAfter', 'actionObjectCurrencyDeleteAfter',
        'actionObjectGroupAddAfter', 'actionObjectGroupUpdateAfter', 'actionObjectGroupDeleteAfter',
        'actionObjectLanguageUpdateAfter', 'actionObjectCountryUpdateAfter', 'actionObjectStateUpdateAfter',
        'actionObjectLanguageAddAfter', 'actionObjectLanguageDeleteAfter',
        'actionObjectCountryAddAfter', 'actionObjectCountryDeleteAfter', 'actionObjectStateAddAfter', 'actionObjectStateDeleteAfter',
        'actionObjectCombinationAddAfter', 'actionObjectCombinationUpdateAfter', 'actionObjectCombinationDeleteAfter',
        'actionObjectTaxAddAfter', 'actionObjectTaxUpdateAfter', 'actionObjectTaxDeleteAfter',
        'actionObjectTaxRuleAddAfter', 'actionObjectTaxRuleUpdateAfter', 'actionObjectTaxRuleDeleteAfter',
        'actionObjectTaxRulesGroupAddAfter', 'actionObjectTaxRulesGroupUpdateAfter', 'actionObjectTaxRulesGroupDeleteAfter',
        'actionUpdateLangAfter', 'actionObjectStockAvailableUpdateAfter',
    ];
    foreach ($hooks as $hook) {
        if ((int) Hook::getIdByName($hook) > 0 && !$module->isRegisteredInHook($hook) && !$module->registerHook($hook)) {
            return false;
        }
    }
    // Register optional newer core hooks only when this shop's core provides them.
    foreach (['actionObjectProductAttributeUpdateAfter', 'actionObjectProductDefaultCombinationUpdateAfter'] as $hook) {
        if ((int) Hook::getIdByName($hook) > 0 && !$module->isRegisteredInHook($hook) && !$module->registerHook($hook)) {
            return false;
        }
    }
    $defaultShopId = (int) Configuration::get('PS_SHOP_DEFAULT');
    if ($defaultShopId <= 0) {
        $shops = Shop::getShops(true);
        $defaultShopId = (int) ($shops[0]['id_shop'] ?? 0);
    }
    $defaultShop = $defaultShopId > 0 ? new Shop($defaultShopId) : null;
    $groupId = (int) ($defaultShop->id_shop_group ?? 0);
    foreach (['LLMSCATALOG_ENABLED', 'LLMSCATALOG_TITLES', 'LLMSCATALOG_SUMMARIES', 'LLMSCATALOG_MAX_PRODUCTS',
        'LLMSCATALOG_MAX_BYTES', 'LLMSCATALOG_CACHE_TTL', 'LLMSCATALOG_INCLUDE_CATEGORIES',
        'LLMSCATALOG_INCLUDE_PRODUCTS', 'LLMSCATALOG_INCLUDE_PRICE', 'LLMSCATALOG_INCLUDE_AVAILABILITY',
        'LLMSCATALOG_INJECT_LINKS', 'LLMSCATALOG_CONFIG_VERSION', 'LLMSCATALOG_CACHE_GENERATION'] as $key) {
        if (Configuration::hasKey($key, null, 0, 0)) continue;
        $value = false;
        if ($defaultShopId > 0 && Configuration::hasKey($key, null, $groupId, $defaultShopId)) {
            $value = Configuration::get($key, null, $groupId, $defaultShopId);
        }
        if ($value === false || $value === null) {
            if ($key === 'LLMSCATALOG_CACHE_GENERATION') $value = '1';
            elseif ($key === 'LLMSCATALOG_CONFIG_VERSION') $value = '1';
            else continue;
        }
        if (!Configuration::updateValue($key, $value, false, 0, 0)) return false;
    }
    $version = (int) Configuration::get('LLMSCATALOG_CONFIG_VERSION', null, 0, 0);
    if (!Configuration::updateValue('LLMSCATALOG_CONFIG_VERSION', (string) ($version + 1), false, 0, 0)) return false;
    $generation = (int) Configuration::get('LLMSCATALOG_CACHE_GENERATION', null, 0, 0);
    if (!Configuration::updateValue('LLMSCATALOG_CACHE_GENERATION', (string) ($generation + 1), false, 0, 0)) return false;
    return true;
}
