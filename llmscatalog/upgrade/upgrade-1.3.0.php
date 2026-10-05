<?php

if (!defined('_PS_VERSION_')) { exit; }

/** Add persistent cache/revision state and idempotently install freshness hooks. */
function upgrade_module_1_3_0($module): bool
{
    if (!\LlmsCatalog\Service\CacheManager::installTables()) return false;
    // The approved 1.3.0 release consolidates the locally implemented 1.4-1.6
    // feature work. Defaults are additive and preserve all merchant values.
    $defaults = [
        'LLMSCATALOG_INJECT_LINKS'=>'0', 'LLMSCATALOG_COMPLETE_INDEXES'=>'1',
        'LLMSCATALOG_MAX_FEATURED_CATEGORIES'=>'50', 'LLMSCATALOG_RICH_PRODUCT'=>'0',
        'LLMSCATALOG_PRODUCT_MAX_BYTES'=>'262144', 'LLMSCATALOG_PRODUCT_MAX_VARIANTS'=>'50',
        'LLMSCATALOG_PRODUCT_MAX_IMAGES'=>'8', 'LLMSCATALOG_PRODUCT_MAX_SPECS'=>'50',
        'LLMSCATALOG_PRODUCT_MAX_CATEGORIES'=>'10', 'LLMSCATALOG_PRODUCT_MAX_PACK_ITEMS'=>'50',
        'LLMSCATALOG_PRODUCT_MAX_CUSTOM_FIELDS'=>'25', 'LLMSCATALOG_PRODUCT_PROVIDERS'=>'[]',
        'LLMSCATALOG_PRODUCT_SECTIONS'=>'{}', 'LLMSCATALOG_AUGMENT_ORGANIZATION'=>'0',
        'LLMSCATALOG_AUGMENT_PRODUCT'=>'0','LLMSCATALOG_AUGMENT_BREADCRUMB'=>'0',
    ];
    foreach ($defaults as $key=>$value) if (!Configuration::hasKey($key, null, 0, 0) && !Configuration::updateValue($key, $value, false, 0, 0)) return false;
    $hooks = [
        'actionProductSave','actionProductUpdate','actionProductDelete','actionAfterCreateProductFormHandler','actionAfterUpdateProductFormHandler',
        'actionCategoryAdd','actionCategoryUpdate','actionCategoryDelete','actionUpdateQuantity','actionObjectStockAvailableAddAfter','actionObjectStockAvailableUpdateAfter','actionObjectStockAvailableDeleteAfter',
        'actionObjectCombinationAddAfter','actionObjectCombinationUpdateAfter','actionObjectCombinationDeleteAfter','actionAttributeCombinationSave','actionAttributeCombinationDelete','actionProductAttributeUpdate','actionProductAttributeDelete','actionUpdateDefaultCombinationAfter',
        'actionObjectSpecificPriceAddAfter','actionObjectSpecificPriceUpdateAfter','actionObjectSpecificPriceDeleteAfter','actionObjectSpecificPriceRuleAddAfter','actionObjectSpecificPriceRuleUpdateAfter','actionObjectSpecificPriceRuleDeleteAfter',
        'actionObjectCurrencyAddAfter','actionObjectCurrencyUpdateAfter','actionObjectCurrencyDeleteAfter','actionObjectTaxAddAfter','actionObjectTaxUpdateAfter','actionObjectTaxDeleteAfter',
        'actionObjectTaxRuleAddAfter','actionObjectTaxRuleUpdateAfter','actionObjectTaxRuleDeleteAfter','actionObjectTaxRulesGroupAddAfter','actionObjectTaxRulesGroupUpdateAfter','actionObjectTaxRulesGroupDeleteAfter',
        'actionObjectGroupAddAfter','actionObjectGroupUpdateAfter','actionObjectGroupDeleteAfter','actionObjectGroupReductionAddAfter','actionObjectGroupReductionUpdateAfter','actionObjectGroupReductionDeleteAfter',
        'actionObjectShopAddAfter','actionObjectShopUpdateAfter','actionObjectShopDeleteAfter','actionObjectShopUrlAddAfter','actionObjectShopUrlUpdateAfter','actionObjectShopUrlDeleteAfter','actionConfigurationUpdateValueBefore',
        'actionShopFormSave','actionUpdateLangAfter','actionObjectLanguageAddAfter','actionObjectLanguageUpdateAfter','actionObjectLanguageDeleteAfter',
        'actionOutputHTMLBefore', 'actionObjectImageAddAfter','actionObjectImageUpdateAfter','actionObjectImageDeleteAfter',
        'actionObjectFeatureProductAddAfter','actionObjectFeatureProductUpdateAfter','actionObjectFeatureProductDeleteAfter',
        'actionObjectFeatureUpdateAfter','actionObjectFeatureAddAfter','actionObjectFeatureDeleteAfter',
        'actionObjectFeatureValueUpdateAfter','actionObjectFeatureValueAddAfter','actionObjectFeatureValueDeleteAfter',
        'actionObjectManufacturerUpdateAfter','actionObjectManufacturerAddAfter','actionObjectManufacturerDeleteAfter',
        'actionObjectPackAddAfter','actionObjectPackUpdateAfter','actionObjectPackDeleteAfter',
        'actionObjectCustomizationFieldAddAfter','actionObjectCustomizationFieldUpdateAfter','actionObjectCustomizationFieldDeleteAfter',
        'actionObjectAttributeUpdateAfter','actionObjectAttributeAddAfter','actionObjectAttributeDeleteAfter',
        'actionObjectAttributeGroupUpdateAfter','actionObjectAttributeGroupAddAfter','actionObjectAttributeGroupDeleteAfter',
        'actionLlmsCatalogProductContributionChanged',
    ];
    if (!$module->registerHook($hooks)) return false;
    $service = new \LlmsCatalog\Service\InvalidationService();
    $service->invalidateSettings('upgrade-1.3.0');
    if (class_exists('Tools') && method_exists('Tools', 'clearSf2Cache')) \Tools::clearSf2Cache();
    return true;
}
