<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use LlmsCatalog\Service\CacheManager;
use LlmsCatalog\Service\CatalogRepository;
use LlmsCatalog\Service\LlmsGenerator;
use LlmsCatalog\Service\MarkdownRenderer;
use LlmsCatalog\Service\Settings;
use LlmsCatalog\Service\UrlBuilder;
use LlmsCatalog\Service\PublicPriceContextFactory;

// PrestaShop only auto-loads a module's Composer autoloader for modules that are
// already installed (see ContainerBuilder::loadModulesAutoloader). Load it
// explicitly so the module classes resolve during the very first install, before
// the container knows about the module, and on builds that don't load it at all.
require_once __DIR__ . '/vendor/autoload.php';

class LlmsCatalog extends Module
{
    public const CONFIG_PREFIX = 'LLMSCATALOG_';

    private ?LlmsGenerator $generator = null;

    public function __construct()
    {
        $this->name = 'llmscatalog';
        $this->tab = 'seo';
        $this->version = '1.5.0';
        $this->author = 'LLMs.txt for PrestaShop';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.2', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('LLMs.txt for PrestaShop', [], 'Modules.Llmscatalog.Admin');
        $this->description = $this->trans('Publishes a localized, machine-readable catalog index for generative engines.', [], 'Modules.Llmscatalog.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * Expose the module translation helper.
     *
     * ModuleCore::trans() is protected in PrestaShop 9, but module services such as
     * TranslationResolver need to call it. Increasing the visibility in the child
     * class keeps the existing behaviour and works across supported versions.
     */
    public function trans($id, array $parameters = [], $domain = null, $locale = null)
    {
        return parent::trans($id, $parameters, $domain, $locale);
    }

    public function install(): bool
    {
        return parent::install()
            && CacheManager::installTables()
            && $this->installDefaults()
            && $this->installTab()
            && $this->registerHook(array_values(array_unique([
                'moduleRoutes',
                'displayHeader',
                'actionOutputHTMLBefore',
                'actionFrontControllerInitAfter',
                'actionObjectProductAddAfter',
                'actionObjectProductUpdateAfter',
                'actionObjectProductDeleteAfter',
                'actionObjectCategoryAddAfter',
                'actionObjectCategoryUpdateAfter',
                'actionObjectCategoryDeleteAfter',
                'actionShopFormSave',
                'actionUpdateQuantity',
                'actionUpdateLangAfter',
                'actionObjectStockAvailableUpdateAfter',
                'actionPresentProduct',
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
                'actionProductSave', 'actionProductUpdate', 'actionProductDelete',
                'actionAfterCreateProductFormHandler', 'actionAfterUpdateProductFormHandler',
                'actionCategoryAdd', 'actionCategoryUpdate', 'actionCategoryDelete',
                'actionObjectStockAvailableAddAfter', 'actionObjectStockAvailableDeleteAfter',
                'actionAttributeCombinationSave', 'actionAttributeCombinationDelete',
                'actionProductAttributeUpdate', 'actionProductAttributeDelete', 'actionUpdateDefaultCombinationAfter',
                'actionObjectShopAddAfter', 'actionObjectShopUpdateAfter', 'actionObjectShopDeleteAfter', 'actionObjectShopUrlAddAfter', 'actionObjectShopUrlUpdateAfter', 'actionObjectShopUrlDeleteAfter',
                'actionConfigurationUpdateValueBefore', 'actionObjectGroupReductionAddAfter', 'actionObjectGroupReductionUpdateAfter', 'actionObjectGroupReductionDeleteAfter',
                'actionObjectImageAddAfter', 'actionObjectImageUpdateAfter', 'actionObjectImageDeleteAfter',
                'actionObjectFeatureProductAddAfter', 'actionObjectFeatureProductUpdateAfter', 'actionObjectFeatureProductDeleteAfter',
                'actionObjectFeatureUpdateAfter', 'actionObjectFeatureAddAfter', 'actionObjectFeatureDeleteAfter',
                'actionObjectFeatureValueUpdateAfter', 'actionObjectFeatureValueAddAfter', 'actionObjectFeatureValueDeleteAfter',
                'actionObjectManufacturerUpdateAfter', 'actionObjectManufacturerAddAfter', 'actionObjectManufacturerDeleteAfter',
                'actionObjectPackAddAfter', 'actionObjectPackUpdateAfter', 'actionObjectPackDeleteAfter',
                'actionObjectCustomizationFieldAddAfter', 'actionObjectCustomizationFieldUpdateAfter', 'actionObjectCustomizationFieldDeleteAfter',
                'actionObjectAttributeUpdateAfter', 'actionObjectAttributeAddAfter', 'actionObjectAttributeDeleteAfter',
                'actionObjectAttributeGroupUpdateAfter', 'actionObjectAttributeGroupAddAfter', 'actionObjectAttributeGroupDeleteAfter',
                'actionLlmsCatalogProductContributionChanged',
            ])));
    }

    public function uninstall(): bool
    {
        $this->uninstallTab();
        foreach (Settings::keys() as $key) {
            Configuration::deleteByName($key);
        }

        return CacheManager::dropTables() && parent::uninstall();
    }

    public function getContent(): string
    {
        $link = Link::getUrlSmarty([
            'entity' => 'sf',
            'route' => 'llmscatalog_configuration',
        ]);
        Tools::redirectAdmin($link);

        return '';
    }

    public function hookModuleRoutes(array $params): array
    {
        // Route broadly; LanguageResolver performs strict locale/alias checks.
        $localeKeyword = ['regexp' => '[^/]+', 'param' => 'llms_lang'];
        $rewriteKeyword = ['regexp' => '[^/]+', 'param' => 'rewrite'];
        $routes = [
            // Shop-root discovery route must precede the locale parameter route.
            'module-llmscatalog-root' => [
                'rule' => 'llms.txt', 'keywords' => [], 'controller' => 'root',
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            // Dispatcher removes a leading two-letter ISO segment before route
            // matching, so /fr/llms.txt arrives here as /llms.txt.
            'module-llmscatalog-stripped-legacy-product' => [
                'rule' => 'llms/product/{id_product}/{rewrite}.md',
                'keywords' => [
                    'id_product' => ['regexp' => '[0-9]+', 'param' => 'id_product'],
                    'rewrite' => $rewriteKeyword,
                ],
                'controller' => 'product',
                'params' => ['fc' => 'module', 'module' => $this->name, 'llms_route_source' => 'stripped-legacy'],
            ],
            'module-llmscatalog-index' => [
                'rule' => '{llms_lang}/llms.txt',
                'keywords' => [
                    'llms_lang' => $localeKeyword,
                ],
                'controller' => 'catalogindex',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],
            'module-llmscatalog-product' => [
                'rule' => '{llms_lang}/llms/product/{id_product}/{rewrite}.md',
                'keywords' => [
                    'llms_lang' => $localeKeyword,
                    'id_product' => [
                        'regexp' => '[0-9]+',
                        'param' => 'id_product',
                    ],
                    'rewrite' => [
                        'regexp' => '[^/]+',
                        'param' => 'rewrite',
                    ],
                ],
                'controller' => 'product',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],
            'module-llmscatalog-catalog-products' => ['rule' => '{llms_lang}/llms/catalog/products/after/{cursor}.md', 'keywords' => ['llms_lang' => $localeKeyword, 'cursor' => ['regexp' => '[0-9]+', 'param' => 'cursor']], 'controller' => 'catalog', 'params' => ['fc' => 'module', 'module' => $this->name, 'catalog_kind' => 'products']],
            'module-llmscatalog-catalog-categories' => ['rule' => '{llms_lang}/llms/catalog/categories/after/{cursor}.md', 'keywords' => ['llms_lang' => $localeKeyword, 'cursor' => ['regexp' => '[0-9]+', 'param' => 'cursor']], 'controller' => 'catalog', 'params' => ['fc' => 'module', 'module' => $this->name, 'catalog_kind' => 'categories']],
            'module-llmscatalog-category' => ['rule' => '{llms_lang}/llms/category/{id_category}/{rewrite}.md', 'keywords' => ['llms_lang' => $localeKeyword, 'id_category' => ['regexp' => '[0-9]+', 'param' => 'id_category'], 'rewrite' => $rewriteKeyword], 'controller' => 'catalog', 'params' => ['fc' => 'module', 'module' => $this->name, 'catalog_kind' => 'category']],
            'module-llmscatalog-category-products' => ['rule' => '{llms_lang}/llms/category/{id_category}/{rewrite}/products/after/{cursor}.md', 'keywords' => ['llms_lang' => $localeKeyword, 'id_category' => ['regexp' => '[0-9]+', 'param' => 'id_category'], 'rewrite' => $rewriteKeyword, 'cursor' => ['regexp' => '[0-9]+', 'param' => 'cursor']], 'controller' => 'catalog', 'params' => ['fc' => 'module', 'module' => $this->name, 'catalog_kind' => 'category-products']],
        ];
        // PrestaShop strips one leading ISO segment before matching module routes.
        // These explicit forms keep the remaining nested-language legacy paths routable.
        foreach (['catalogindex' => 'llms.txt', 'product' => 'llms/product/{id_product}/{rewrite}.md'] as $kind => $tail) {
            $controller = $kind;
            $rule = '{outer_lang}/{llms_lang}/' . $tail;
            $routes['module-llmscatalog-legacy-double-' . $kind] = [
                'rule' => $rule,
                'keywords' => [
                    'outer_lang' => ['regexp' => '[A-Za-z]{2}', 'param' => 'llms_outer_lang'],
                    'llms_lang' => $localeKeyword,
                ] + ($kind === 'product' ? [
                    'id_product' => ['regexp' => '[0-9]+', 'param' => 'id_product'],
                    'rewrite' => $rewriteKeyword,
                ] : []),
                'controller' => $controller,
                'params' => ['fc' => 'module', 'module' => $this->name, 'llms_route_source' => 'legacy-double'],
            ];
        }
        return $routes;
    }

    public function hookDisplayHeader(): string
    {
        $builder = new \LlmsCatalog\Service\DiscoveryLinkBuilder();
        return $builder->html($builder->pageLinks($this->context, $this->context->controller));
    }

    /** Keep the PrestaShop hook as a thin adapter; the service fails open. */
    public function hookActionOutputHTMLBefore(array &$params): void
    {
        if ((new \LlmsCatalog\Service\HomepageMarkdownNegotiator())->handle($params, $this->context, $this)) return;
        (new \LlmsCatalog\Service\StructuredDataPageAugmentationService())->augment($params);
    }

    public function hookActionFrontControllerInitAfter(array $params = []): void
    {
        if (http_response_code() >= 300) return;
        $controller = $params['controller'] ?? $this->context->controller;
        $builder = new \LlmsCatalog\Service\DiscoveryLinkBuilder();
        foreach ($builder->http($builder->pageLinks($this->context, $controller)) as $field) {
            $alreadyPresent = false;
            foreach (headers_list() as $existing) {
                if (stripos($existing, 'Link:') === 0 && stripos(trim(substr($existing, 5)), $field) !== false) { $alreadyPresent = true; break; }
            }
            if (!$alreadyPresent) header('Link: ' . $field, false);
        }
    }

    public function hookActionObjectProductAddAfter(array $params = []): void
    {
        $this->invalidateProduct($params, __FUNCTION__);
    }

    public function hookActionObjectProductUpdateAfter(array $params = []): void
    {
        $this->invalidateProduct($params, __FUNCTION__);
    }

    public function hookActionObjectProductDeleteAfter(array $params = []): void
    {
        $this->invalidateProduct($params, __FUNCTION__);
    }

    public function hookActionObjectCategoryAddAfter(array $params = []): void
    {
        $this->invalidateRouting($params, __FUNCTION__);
    }

    public function hookActionObjectCategoryUpdateAfter(array $params = []): void
    {
        $this->invalidateRouting($params, __FUNCTION__);
    }

    public function hookActionObjectCategoryDeleteAfter(array $params = []): void
    {
        $this->invalidateRouting($params, __FUNCTION__);
    }

    public function hookActionShopFormSave(array $params = []): void
    {
        (new \LlmsCatalog\Service\InvalidationService())->invalidateRouting($this->hookShops($params), __FUNCTION__);
    }

    public function hookActionUpdateQuantity(array $params = []): void { $this->invalidateProduct($params, __FUNCTION__); }
    public function hookActionUpdateLangAfter(array $params = []): void { $this->invalidateRouting($params, __FUNCTION__); }
    public function hookActionObjectStockAvailableUpdateAfter(array $params = []): void { $this->invalidateProduct($params, __FUNCTION__); }

    /** Correct only the native value, leaving other modules' mutations intact. */
    public function hookActionPresentProduct(array &$params): void
    {
        if (!$this->active || !(new Settings())->isEnabled() || empty($params['presentedProduct']) || !is_object($params['presentedProduct'])) return;
        $presented = $params['presentedProduct'];
        $id = (int) ($presented['id_product'] ?? 0);
        if ($id <= 0 || empty($presented['show_price']) || !isset($presented['quantity'])) return;
        $quantity = (int) ($presented['quantity'] ?? 0);
        $allow = !empty($presented['allow_oosp']);
        $native = $quantity > 0 ? 'https://schema.org/InStock' : ($allow ? 'https://schema.org/BackOrder' : 'https://schema.org/OutOfStock');
        $current = $presented['seo_availability'] ?? null;
        if ($current !== $native) return;
        $shopId = (int) $this->context->shop->id;
        $product = new Product($id, false, (int) $this->context->language->id, $shopId);
        if (!Validate::isLoadedObject($product) || !(bool) $product->active) return;
        $minimum = max(1, (int) ($presented['minimal_quantity'] ?? $product->minimal_quantity));
        $isOrderable = !empty($presented['available_for_order']);
        $catalogMode = (bool) Configuration::get('PS_CATALOG_MODE', null, (int) $this->context->shop->id_shop_group, $shopId);
        $facts = new \LlmsCatalog\Availability\AvailabilityFacts(
            $isOrderable, $catalogMode, true, (bool) Configuration::get('PS_STOCK_MANAGEMENT', null, (int) $this->context->shop->id_shop_group, $shopId),
            $quantity, $minimum, $allow, null, (int) ($presented['id_product_attribute'] ?? 0)
        );
        $resolved = (new \LlmsCatalog\Availability\AvailabilityResolver())->resolve($facts);
        if ($resolved->schemaUrl !== $native) {
            if (method_exists($presented, 'offsetSet')) $presented->offsetSet('seo_availability', $resolved->schemaUrl, true);
            else $presented['seo_availability'] = $resolved->schemaUrl;
        }
    }
    public function hookActionObjectSpecificPriceAddAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__, true); }
    public function hookActionObjectSpecificPriceUpdateAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__, true); }
    public function hookActionObjectSpecificPriceDeleteAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__, true); }
    public function hookActionObjectSpecificPriceRuleAddAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__); }
    public function hookActionObjectSpecificPriceRuleUpdateAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__); }
    public function hookActionObjectSpecificPriceRuleDeleteAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__); }
    public function hookActionObjectCurrencyAddAfter(array $p = []): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectCurrencyUpdateAfter(array $p = []): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectCurrencyDeleteAfter(array $p = []): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectGroupAddAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__); }
    public function hookActionObjectGroupUpdateAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__); }
    public function hookActionObjectGroupDeleteAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__); }
    public function hookActionObjectLanguageUpdateAfter(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectLanguageAddAfter(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectLanguageDeleteAfter(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectCountryUpdateAfter(array $p = []): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectCountryAddAfter(array $p = []): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectCountryDeleteAfter(array $p = []): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectStateUpdateAfter(array $p = []): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectStateAddAfter(array $p = []): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectStateDeleteAfter(array $p = []): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectCombinationAddAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectCombinationUpdateAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectCombinationDeleteAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectTaxUpdateAfter(): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectTaxAddAfter(): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectTaxDeleteAfter(): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectTaxRuleUpdateAfter(): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectTaxRuleAddAfter(): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectTaxRuleDeleteAfter(): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectTaxRulesGroupUpdateAfter(): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectTaxRulesGroupAddAfter(): void { $this->invalidateGlobalPricing(__FUNCTION__); }
    public function hookActionObjectTaxRulesGroupDeleteAfter(): void { $this->invalidateGlobalPricing(__FUNCTION__); }

    public function hookActionProductSave(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectImageAddAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectImageUpdateAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectImageDeleteAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectFeatureProductAddAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectFeatureProductUpdateAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectFeatureProductDeleteAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectFeatureUpdateAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectFeatureAddAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectFeatureDeleteAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectFeatureValueUpdateAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectFeatureValueAddAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectFeatureValueDeleteAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectManufacturerUpdateAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectManufacturerAddAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectManufacturerDeleteAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectPackAddAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectPackUpdateAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectPackDeleteAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectCustomizationFieldAddAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectCustomizationFieldUpdateAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectCustomizationFieldDeleteAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectAttributeUpdateAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectAttributeAddAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectAttributeDeleteAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectAttributeGroupUpdateAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectAttributeGroupAddAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionObjectAttributeGroupDeleteAfter(): void { (new \LlmsCatalog\Service\InvalidationService())->invalidateSettings(__FUNCTION__); }
    public function hookActionLlmsCatalogProductContributionChanged(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionProductUpdate(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionProductDelete(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionAfterCreateProductFormHandler(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionAfterUpdateProductFormHandler(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionCategoryAdd(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionCategoryUpdate(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionCategoryDelete(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectStockAvailableAddAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionObjectStockAvailableDeleteAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionAttributeCombinationSave(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionAttributeCombinationDelete(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionProductAttributeUpdate(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionProductAttributeDelete(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionUpdateDefaultCombinationAfter(array $p = []): void { $this->invalidateProduct($p, __FUNCTION__); }
    public function hookActionConfigurationUpdateValueBefore(array $p = []): void
    {
        $name = (string) ($p['name'] ?? $p['key'] ?? '');
        if (!in_array($name, ['PS_SHOP_NAME','PS_META_DESCRIPTION','PS_LANG_DEFAULT','PS_CURRENCY_DEFAULT','PS_COUNTRY_DEFAULT','PS_SHOP_COUNTRY_ID','PS_SHOP_STATE_ID','PS_SHOP_CODE','PS_UNIDENTIFIED_GROUP','PS_TAX','PS_TAX_ADDRESS_TYPE','PS_PRICE_DISPLAY_PRECISION','PS_PRICE_ROUND_MODE','PS_ROUND_TYPE','PS_USE_ECOTAX','PS_ECOTAX_TAX_RULES_GROUP_ID','PS_REWRITING_SETTINGS','PS_SSL_ENABLED','PS_SSL_ENABLED_EVERYWHERE'], true)) return;
        if (in_array($name, ['PS_CURRENCY_DEFAULT','PS_COUNTRY_DEFAULT','PS_SHOP_COUNTRY_ID','PS_SHOP_STATE_ID','PS_UNIDENTIFIED_GROUP','PS_TAX','PS_TAX_ADDRESS_TYPE','PS_PRICE_DISPLAY_PRECISION','PS_PRICE_ROUND_MODE','PS_ROUND_TYPE','PS_USE_ECOTAX','PS_ECOTAX_TAX_RULES_GROUP_ID'], true)) $this->invalidatePricing($p, __FUNCTION__);
        else $this->invalidateRouting($p, __FUNCTION__);
    }
    public function hookActionObjectShopAddAfter(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectShopUpdateAfter(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectShopDeleteAfter(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectShopUrlAddAfter(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectShopUrlUpdateAfter(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectShopUrlDeleteAfter(array $p = []): void { $this->invalidateRouting($p, __FUNCTION__); }
    public function hookActionObjectGroupReductionAddAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__); }
    public function hookActionObjectGroupReductionUpdateAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__); }
    public function hookActionObjectGroupReductionDeleteAfter(array $p = []): void { $this->invalidatePricing($p, __FUNCTION__); }

    public function getGenerator(): LlmsGenerator
    {
        if ($this->generator === null) {
            $context = Context::getContext();
            $this->generator = new LlmsGenerator(
                new Settings(),
                new CatalogRepository($context),
                new UrlBuilder($context),
                new MarkdownRenderer(),
                new CacheManager($context),
                $context
            );
        }

        return $this->generator;
    }

    private function installDefaults(): bool
    {
        $languages = Language::getLanguages(true);
        $titles = [];
        $summaries = [];
        foreach ($languages as $language) {
            $titles[(int) $language['id_lang']] = (string) Configuration::get('PS_SHOP_NAME');
            $summaries[(int) $language['id_lang']] = (string) Configuration::get('PS_META_DESCRIPTION', (int) $language['id_lang']);
        }

        $defaults = [
            'LLMSCATALOG_ENABLED' => '1',
            'LLMSCATALOG_TITLES' => json_encode($titles),
            'LLMSCATALOG_SUMMARIES' => json_encode($summaries),
            'LLMSCATALOG_MAX_PRODUCTS' => '250',
            'LLMSCATALOG_MAX_BYTES' => '131072',
            'LLMSCATALOG_CACHE_TTL' => '3600',
            'LLMSCATALOG_INCLUDE_CATEGORIES' => '1',
            'LLMSCATALOG_INCLUDE_PRODUCTS' => '1',
            'LLMSCATALOG_INCLUDE_PRICE' => '1',
            'LLMSCATALOG_INCLUDE_AVAILABILITY' => '1',
            'LLMSCATALOG_INJECT_LINKS' => '1',
            'LLMSCATALOG_CONFIG_VERSION' => '1',
            'LLMSCATALOG_CACHE_GENERATION' => '1',
            'LLMSCATALOG_COMPLETE_INDEXES' => '1',
            'LLMSCATALOG_MAX_FEATURED_CATEGORIES' => '50',
            'LLMSCATALOG_RICH_PRODUCT' => '0',
            'LLMSCATALOG_PRODUCT_MAX_BYTES' => '262144',
            'LLMSCATALOG_PRODUCT_MAX_VARIANTS' => '50', 'LLMSCATALOG_PRODUCT_MAX_IMAGES' => '8',
            'LLMSCATALOG_PRODUCT_MAX_SPECS' => '50', 'LLMSCATALOG_PRODUCT_MAX_CATEGORIES' => '10',
            'LLMSCATALOG_PRODUCT_MAX_PACK_ITEMS' => '50', 'LLMSCATALOG_PRODUCT_MAX_CUSTOM_FIELDS' => '25',
            'LLMSCATALOG_PRODUCT_PROVIDERS' => '[]', 'LLMSCATALOG_PRODUCT_SECTIONS' => '{}',
            'LLMSCATALOG_AUGMENT_ORGANIZATION' => '0', 'LLMSCATALOG_AUGMENT_PRODUCT'=>'0', 'LLMSCATALOG_AUGMENT_BREADCRUMB'=>'0',
        ];

        foreach ($defaults as $key => $value) {
            if (!Configuration::hasKey($key, null, 0, 0) && !Configuration::updateValue($key, $value, false, 0, 0)) {
                return false;
            }
        }

        return true;
    }

    private function installTab(): bool
    {
        $parentId = $this->adminTabParentId();
        $existingId = (int) Tab::getIdFromClassName('AdminLlmsCatalog');
        if ($existingId > 0) {
            $tab = new Tab($existingId);
            // Repair tabs installed under AdminModulesManage/AdminModulesSf,
            // which made the Module Manager menu open this configuration page.
            if ($parentId > 0 && (int) $tab->id_parent !== $parentId) {
                $tab->id_parent = $parentId;
                return (bool) $tab->update();
            }
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminLlmsCatalog';
        $tab->module = $this->name;
        $tab->id_parent = $parentId;

        foreach (Language::getLanguages(true) as $language) {
            $tab->name[(int) $language['id_lang']] = $this->trans('LLMs.txt for PrestaShop', [], 'Modules.Llmscatalog.Admin', (string) $language['locale']);
        }

        return (bool) $tab->add();
    }

    /**
     * Resolve a valid "Modules" menu parent across PrestaShop 9.x builds.
     *
     * The legacy "AdminModules" tab no longer exists in PrestaShop 9, which left
     * the module tab orphaned at the menu root. Attach the tab to the "Modules"
     * parent (AdminParentModulesSf) so it becomes a sibling of the Module Manager
     * tab. PrestaShop's nav bar builds a parent tab link from its first active
     * child, so attaching this tab under AdminModulesManage/AdminModulesSf makes
     * "Module Manager" open this module's configuration page instead of the
     * module list, trapping the merchant on the configuration page.
     */
    private function adminTabParentId(): int
    {
        foreach (['AdminParentModulesSf', 'AdminModulesSf', 'AdminModulesManage'] as $className) {
            $idTab = (int) Tab::getIdFromClassName($className);
            if ($idTab > 0) {
                return $idTab;
            }
        }

        return 0;
    }

    private function uninstallTab(): void
    {
        $idTab = (int) Tab::getIdFromClassName('AdminLlmsCatalog');
        if ($idTab <= 0) {
            return;
        }

        $tab = new Tab($idTab);
        $tab->delete();
    }

    private function invalidateCache(): void
    {
        $this->invalidateIndex([], 'legacy-invalidation');
    }

    private function hookShops(array $params): array
    {
        $shopId = (int) ($params['id_shop'] ?? 0);
        $object = $params['object'] ?? null;
        if ($shopId <= 0 && is_object($object) && isset($object->id_shop)) $shopId = (int) $object->id_shop;
        return $shopId > 0 ? [$shopId] : [];
    }

    private function hookProductId(array $params): int
    {
        $object = $params['object'] ?? null;
        foreach (['id_product', 'product_id', 'id'] as $field) {
            if (isset($params[$field]) && (int) $params[$field] > 0) return (int) $params[$field];
            if (is_object($object) && isset($object->{$field}) && (int) $object->{$field} > 0) return (int) $object->{$field};
        }
        $attributeId = (int) ($params['id_product_attribute'] ?? (is_object($object) ? ($object->id_product_attribute ?? 0) : 0));
        if ($attributeId > 0) return (int) Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute=' . $attributeId);
        return 0;
    }

    private function invalidateProduct(array $params, string $source): void
    { (new \LlmsCatalog\Service\InvalidationService())->invalidateProduct($this->hookShops($params), $this->hookProductId($params), $source); }
    private function invalidateIndex(array $params, string $source): void
    { (new \LlmsCatalog\Service\InvalidationService())->invalidateIndex($this->hookShops($params), $source); }
    private function invalidateRouting(array $params, string $source): void
    { (new \LlmsCatalog\Service\InvalidationService())->invalidateRouting($this->hookShops($params), $source); }
    private function invalidatePricing(array $params, string $source, bool $specificProduct = false): void
    {
        $service = new \LlmsCatalog\Service\InvalidationService();
        $product = $specificProduct ? $this->hookProductId($params) : 0;
        if ($product > 0) $service->invalidateProduct($this->hookShops($params), $product, $source);
        else $service->invalidateShopPricing($this->hookShops($params), $source);
    }
    private function invalidateGlobalPricing(string $source): void
    { (new \LlmsCatalog\Service\InvalidationService())->invalidateGlobalPricing($source); }
}
