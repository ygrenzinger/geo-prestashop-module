<?php

namespace LlmsCatalog\Service;

use LlmsCatalog\Availability\AvailabilityFacts;
use LlmsCatalog\Availability\AvailabilityResolver;
use Product;
use StockAvailable;

/** PrestaShop adapter: all stock quantities remain internal to this service. */
class AvailabilityProvider
{
    private AvailabilityResolver $resolver;

    public function __construct(?AvailabilityResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new AvailabilityResolver();
    }

    public function forProduct(Product $product, int $shopId, bool $catalogMode, bool $showPrice, bool $includeOtherVariants = false, ?int $languageId = null): \LlmsCatalog\Availability\AvailabilityResult
    {
        $languageId = $languageId ?: (int) \Context::getContext()->language->id;
        $shopCombinationIds = $this->shopCombinationIds((int) $product->id, $shopId);
        $combinationId = $this->defaultCombinationId((int) $product->id, $shopId);
        $hasCombinations = $shopCombinationIds !== [];
        if ($hasCombinations && !$combinationId) {
            SafeDiagnosticLogger::log(
                'public.availability.default-combination-missing',
                $shopId,
                new \UnexpectedValueException('Default combination missing')
            );
            return $this->resolver->resolve(new AvailabilityFacts((bool) $product->available_for_order, $catalogMode, $showPrice, true, 0, 1, false, null, 0, false, 'missing_default_combination'));
        }
        $minimum = max(1, (int) ($combinationId ? $this->combinationMinimum($combinationId) : $product->minimal_quantity));
        $shopGroupId = (int) \Context::getContext()->shop->id_shop_group;
        $stockManagement = (bool) \Configuration::get('PS_STOCK_MANAGEMENT', null, $shopGroupId, $shopId);
        $quantity = 0;
        $allow = false;
        $reason = '';
        try {
            $quantity = $stockManagement ? (int) Product::getQuantity((int) $product->id, $combinationId ?: null) : 0;
            $policy = StockAvailable::outOfStock((int) $product->id, $shopId);
            $allow = (bool) Product::isAvailableWhenOutOfStock($policy);
        } catch (\Throwable $e) {
            $reason = 'stock_resolution_failed';
            SafeDiagnosticLogger::log('public.availability.stock', $shopId, $e);
        }
        $result = $this->resolver->resolve(new AvailabilityFacts((bool) $product->available_for_order, $catalogMode, $showPrice, $stockManagement, $quantity, $minimum, $allow, (string) ($product->available_date ?? ''), $combinationId, false, $reason), $this->labels($product, $combinationId, $languageId));
        if ($includeOtherVariants && $result->state !== 'in_stock' && $hasCombinations) {
            foreach ($shopCombinationIds as $id) {
                if ($id === $combinationId) continue;
                $variant = clone $product;
                $variant->minimal_quantity = $this->combinationMinimum($id);
                $q = $stockManagement ? (int) Product::getQuantity((int) $product->id, $id) : 1;
                $v = $this->resolver->resolve(new AvailabilityFacts((bool) $product->available_for_order, $catalogMode, $showPrice, $stockManagement, $q, max(1, (int) $variant->minimal_quantity), Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock((int) $product->id, $shopId))));
                if (in_array($v->state, ['in_stock', 'backorder'], true)) {
                    return $this->resolver->resolve(new AvailabilityFacts((bool) $product->available_for_order, $catalogMode, $showPrice, $stockManagement, $quantity, $minimum, $allow, (string) ($product->available_date ?? ''), $combinationId, true, $reason), $this->labels($product, $combinationId, $languageId));
                }
            }
        }
        return $result;
    }

    private function defaultCombinationId(int $productId, int $shopId): int
    {
        return (int) \Db::getInstance()->getValue('SELECT pa.id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` pa INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . $shopId . ' WHERE pa.id_product = ' . $productId . ' AND pas.default_on = 1');
    }

    private function combinationMinimum(int $id): int
    {
        $shopId = (int) \Context::getContext()->shop->id;
        return max(1, (int) \Db::getInstance()->getValue('SELECT minimal_quantity FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE id_product_attribute = ' . $id . ' AND id_shop = ' . $shopId));
    }

    private function shopCombinationIds(int $productId, int $shopId): array
    {
        $rows = \Db::getInstance()->executeS('SELECT pa.id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` pa INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . $shopId . ' WHERE pa.id_product = ' . $productId) ?: [];
        return array_map(static fn ($row) => (int) $row['id_product_attribute'], $rows);
    }

    private function labels(Product $product, int $combinationId, int $langId): array
    {
        $labels = [];
        $configured = [
            'in_stock' => \Configuration::get('PS_LABEL_IN_STOCK_PRODUCTS', $langId),
            'backorder' => \Configuration::get('PS_LABEL_OOS_PRODUCTS_BOA', $langId),
            'out_of_stock' => \Configuration::get('PS_LABEL_OOS_PRODUCTS_BOD', $langId),
        ];
        $fallback = ['in_stock' => 'In stock', 'backorder' => 'Available on backorder', 'out_of_stock' => 'Out of stock'];
        $module = \Module::getInstanceByName('llmscatalog');
        foreach (['in_stock' => 'available_now', 'backorder' => 'available_later', 'out_of_stock' => 'available_later'] as $state => $field) {
            // PrestaShop 9 removed the per-combination labels, so on those schemas we
            // rely on the product-level field; older schemas keep the override.
            $value = $combinationId && $this->attributeHasLabelColumn($field)
                ? \Db::getInstance()->getValue('SELECT ' . $field . ' FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . $combinationId)
                : '';
            if (!$value) $value = $product->$field;
            if (is_array($value)) $value = $value[$langId] ?? '';
            $labels[$state] = trim((string) $value) ?: (trim((string) $configured[$state]) ?: $this->translate($module, $fallback[$state], $langId));
        }
        $labels['not_for_sale'] = $this->translate($module, 'Not available for order', $langId);
        return $labels;
    }

    private static array $attributeLabelColumns = [];

    private function attributeHasLabelColumn(string $column): bool
    {
        if (!array_key_exists($column, self::$attributeLabelColumns)) {
            $rows = \Db::getInstance()->executeS(
                'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'product_attribute` LIKE "' . pSQL($column) . '"'
            );
            self::$attributeLabelColumns[$column] = is_array($rows) && $rows !== [];
        }

        return self::$attributeLabelColumns[$column];
    }

    private function translate($module, string $text, int $langId): string
    {
        if (!$module) return $text;
        $context = \Context::getContext();
        $language = $context->language;
        try {
            $context->language = new \Language($langId);
            return (string) $module->l($text);
        } finally {
            $context->language = $language;
        }
    }
}
