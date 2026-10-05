<?php

namespace LlmsCatalog\Service;

use Configuration;
use Language;

class Settings
{
    public static function keys(): array
    {
        return [
            'LLMSCATALOG_ENABLED',
            'LLMSCATALOG_TITLES',
            'LLMSCATALOG_SUMMARIES',
            'LLMSCATALOG_MAX_PRODUCTS',
            'LLMSCATALOG_MAX_BYTES',
            'LLMSCATALOG_CACHE_TTL',
            'LLMSCATALOG_INCLUDE_CATEGORIES',
            'LLMSCATALOG_INCLUDE_PRODUCTS',
            'LLMSCATALOG_INCLUDE_PRICE',
            'LLMSCATALOG_INCLUDE_AVAILABILITY',
            'LLMSCATALOG_INJECT_LINKS',
            'LLMSCATALOG_CONFIG_VERSION',
            'LLMSCATALOG_CACHE_GENERATION',
            'LLMSCATALOG_COMPLETE_INDEXES',
            'LLMSCATALOG_MAX_FEATURED_CATEGORIES',
            'LLMSCATALOG_RICH_PRODUCT', 'LLMSCATALOG_PRODUCT_MAX_BYTES',
            'LLMSCATALOG_PRODUCT_MAX_VARIANTS', 'LLMSCATALOG_PRODUCT_MAX_IMAGES',
            'LLMSCATALOG_PRODUCT_MAX_SPECS', 'LLMSCATALOG_PRODUCT_MAX_CATEGORIES',
            'LLMSCATALOG_PRODUCT_MAX_PACK_ITEMS', 'LLMSCATALOG_PRODUCT_MAX_CUSTOM_FIELDS',
            'LLMSCATALOG_PRODUCT_PROVIDERS', 'LLMSCATALOG_PRODUCT_SECTIONS',
            'LLMSCATALOG_AUGMENT_ORGANIZATION',
            'LLMSCATALOG_AUGMENT_PRODUCT', 'LLMSCATALOG_AUGMENT_BREADCRUMB',
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) self::global('LLMSCATALOG_ENABLED');
    }

    public function isHtmlLinkInjectionEnabled(): bool
    {
        return $this->isDiscoveryMetadataEnabled();
    }

    /** One merchant setting governs the equivalent HTML and HTTP relations. */
    public function isDiscoveryMetadataEnabled(): bool
    {
        return (bool) self::global('LLMSCATALOG_INJECT_LINKS');
    }

    public function title(int $langId, ?int $defaultLangId = null): string
    {
        $values = $this->localized('LLMSCATALOG_TITLES');
        $value = trim((string) ($values[$langId] ?? ''));
        if ($value === '' && $defaultLangId !== null) $value = trim((string) ($values[$defaultLangId] ?? ''));
        $context = \Context::getContext();
        $shopName = (string) Configuration::get('PS_SHOP_NAME', $langId, (int) $context->shop->id_shop_group, (int) $context->shop->id);
        if ($value === '' && $defaultLangId !== null && trim($shopName) === '') $shopName = (string) Configuration::get('PS_SHOP_NAME', $defaultLangId, (int) $context->shop->id_shop_group, (int) $context->shop->id);
        return $value ?: $shopName;
    }

    public function summary(int $langId, ?int $defaultLangId = null): string
    {
        $values = $this->localized('LLMSCATALOG_SUMMARIES');
        $value = trim((string) ($values[$langId] ?? ''));
        if ($value !== '') {
            return $value;
        }

        if ($defaultLangId !== null) {
            $defaultValue = trim((string) ($values[$defaultLangId] ?? ''));
            if ($defaultValue !== '') return $defaultValue;
        }

        $value = (string) Configuration::get('PS_META_DESCRIPTION', $langId, (int) \Context::getContext()->shop->id_shop_group, (int) \Context::getContext()->shop->id);
        if (trim($value) === '' && $defaultLangId !== null) $value = (string) Configuration::get('PS_META_DESCRIPTION', $defaultLangId, (int) \Context::getContext()->shop->id_shop_group, (int) \Context::getContext()->shop->id);
        return trim($value) !== '' ? $value : '';
    }

    public function titleUsesFallback(int $langId, int $defaultLangId): bool
    {
        $values = $this->localized('LLMSCATALOG_TITLES');
        if (trim((string) ($values[$langId] ?? '')) !== '') return false;
        if (trim((string) ($values[$defaultLangId] ?? '')) !== '') return true;
        $context = \Context::getContext();
        return trim((string) Configuration::get('PS_SHOP_NAME', $langId, (int) $context->shop->id_shop_group, (int) $context->shop->id)) === ''
            && trim((string) Configuration::get('PS_SHOP_NAME', $defaultLangId, (int) $context->shop->id_shop_group, (int) $context->shop->id)) !== '';
    }

    public function summaryUsesFallback(int $langId, int $defaultLangId): bool
    {
        $values = $this->localized('LLMSCATALOG_SUMMARIES');
        if (trim((string) ($values[$langId] ?? '')) !== '') return false;
        if (trim((string) ($values[$defaultLangId] ?? '')) !== '') return true;
        $context = \Context::getContext();
        return trim((string) Configuration::get('PS_META_DESCRIPTION', $langId, (int) $context->shop->id_shop_group, (int) $context->shop->id)) === ''
            && trim((string) Configuration::get('PS_META_DESCRIPTION', $defaultLangId, (int) $context->shop->id_shop_group, (int) $context->shop->id)) !== '';
    }

    public function maxProducts(): int
    {
        return min(250, max(1, (int) self::global('LLMSCATALOG_MAX_PRODUCTS')));
    }

    public function configuredMaxProducts(): int { return max(1, (int) self::global('LLMSCATALOG_MAX_PRODUCTS')); }
    public function completeIndexes(): bool { return (bool) self::global('LLMSCATALOG_COMPLETE_INDEXES'); }
    public function maxFeaturedCategories(): int { return min(250, max(1, (int) self::global('LLMSCATALOG_MAX_FEATURED_CATEGORIES'))); }

    public function maxBytes(): int
    {
        return max(1024, (int) self::global('LLMSCATALOG_MAX_BYTES'));
    }

    public function cacheTtl(): int
    {
        return max(60, (int) self::global('LLMSCATALOG_CACHE_TTL'));
    }

    public function includesCategories(): bool
    {
        return (bool) self::global('LLMSCATALOG_INCLUDE_CATEGORIES');
    }

    public function includesProducts(): bool
    {
        return (bool) self::global('LLMSCATALOG_INCLUDE_PRODUCTS');
    }

    public function includesPrice(): bool
    {
        return (bool) self::global('LLMSCATALOG_INCLUDE_PRICE');
    }

    public function includesAvailability(): bool
    {
        return (bool) self::global('LLMSCATALOG_INCLUDE_AVAILABILITY');
    }

    public function configVersion(): string
    {
        return (string) self::global('LLMSCATALOG_CONFIG_VERSION') ?: '1';
    }

    public function bumpConfigVersion(): void
    {
        self::globalUpdate('LLMSCATALOG_CONFIG_VERSION', (string) ((int) $this->configVersion() + 1));
    }

    public function cacheGeneration(): int
    {
        return (int) self::global('LLMSCATALOG_CACHE_GENERATION');
    }

    public function richProductEnabled(): bool { return (bool) self::global('LLMSCATALOG_RICH_PRODUCT'); }
    public function enabledProductProviders(): array
    {
        $ids = json_decode((string)self::global('LLMSCATALOG_PRODUCT_PROVIDERS'), true);
        if (!is_array($ids)) return [];
        return array_slice(array_values(array_unique(array_filter(array_map('strval', $ids), static function($id){ return preg_match('/^[a-z0-9_.-]{1,64}$/i',$id)===1; }))), 0, 10);
    }
    public function productSectionEnabled(string $section): bool
    {
        if (!in_array($section, ['ratings','shipping','returns','warranty'], true)) return false;
        $sections = json_decode((string)self::global('LLMSCATALOG_PRODUCT_SECTIONS'), true);
        return is_array($sections) && !empty($sections[$section]);
    }
    public function productLimit(string $kind): int
    {
        $map = ['bytes'=>['LLMSCATALOG_PRODUCT_MAX_BYTES',262144,262144], 'variants'=>['LLMSCATALOG_PRODUCT_MAX_VARIANTS',50,200], 'images'=>['LLMSCATALOG_PRODUCT_MAX_IMAGES',8,50], 'specs'=>['LLMSCATALOG_PRODUCT_MAX_SPECS',50,200], 'categories'=>['LLMSCATALOG_PRODUCT_MAX_CATEGORIES',10,50], 'pack'=>['LLMSCATALOG_PRODUCT_MAX_PACK_ITEMS',50,200], 'custom'=>['LLMSCATALOG_PRODUCT_MAX_CUSTOM_FIELDS',25,100]];
        if (!isset($map[$kind])) return 0;
        [$key,$default,$max] = $map[$kind]; $value = self::global($key);
        return min($max, max($kind === 'bytes' ? 1024 : 1, (int) ($value === false || $value === null || $value === '' ? $default : $value)));
    }

    public function formData(): array
    {
        $data = [
            'enabled' => $this->isEnabled(),
            'max_products' => $this->maxProducts(),
            'max_bytes' => $this->maxBytes(),
            'cache_ttl' => $this->cacheTtl(),
            'include_categories' => $this->includesCategories(),
            'include_products' => $this->includesProducts(),
            'include_price' => $this->includesPrice(),
            'include_availability' => $this->includesAvailability(),
            'inject_links' => $this->isHtmlLinkInjectionEnabled(),
            'complete_indexes' => $this->completeIndexes(),
            'max_featured_categories' => $this->maxFeaturedCategories(),
            'configured_max_products' => $this->configuredMaxProducts(),
            'effective_max_products' => $this->maxProducts(),
            'rich_product' => $this->richProductEnabled(),
            'product_max_bytes' => $this->productLimit('bytes'), 'product_max_variants' => $this->productLimit('variants'),
            'product_max_images' => $this->productLimit('images'), 'product_max_specs' => $this->productLimit('specs'),
            'product_max_categories' => $this->productLimit('categories'), 'product_max_pack_items' => $this->productLimit('pack'),
            'product_max_custom_fields' => $this->productLimit('custom'),
            'product_providers' => implode(', ', $this->enabledProductProviders()),
            'product_ratings' => $this->productSectionEnabled('ratings'), 'product_shipping' => $this->productSectionEnabled('shipping'),
            'product_returns' => $this->productSectionEnabled('returns'), 'product_warranty' => $this->productSectionEnabled('warranty'),
        ];

        foreach (Language::getLanguages(true) as $language) {
            $id = (int) $language['id_lang'];
            $data['title_' . $id] = $this->title($id);
            $data['summary_' . $id] = $this->summary($id);
        }

        return $data;
    }

    public function saveFormData(array $data): void
    {
        self::globalUpdate('LLMSCATALOG_ENABLED', !empty($data['enabled']) ? '1' : '0');
        self::globalUpdate('LLMSCATALOG_MAX_PRODUCTS', (string) max(1, (int) $data['max_products']));
        self::globalUpdate('LLMSCATALOG_MAX_BYTES', (string) max(1024, (int) $data['max_bytes']));
        self::globalUpdate('LLMSCATALOG_CACHE_TTL', (string) max(60, (int) $data['cache_ttl']));
        self::globalUpdate('LLMSCATALOG_INCLUDE_CATEGORIES', !empty($data['include_categories']) ? '1' : '0');
        self::globalUpdate('LLMSCATALOG_INCLUDE_PRODUCTS', !empty($data['include_products']) ? '1' : '0');
        self::globalUpdate('LLMSCATALOG_INCLUDE_PRICE', !empty($data['include_price']) ? '1' : '0');
        self::globalUpdate('LLMSCATALOG_INCLUDE_AVAILABILITY', !empty($data['include_availability']) ? '1' : '0');
        self::globalUpdate('LLMSCATALOG_INJECT_LINKS', !empty($data['inject_links']) ? '1' : '0');
        self::globalUpdate('LLMSCATALOG_COMPLETE_INDEXES', !empty($data['complete_indexes']) ? '1' : '0');
        self::globalUpdate('LLMSCATALOG_MAX_FEATURED_CATEGORIES', (string) min(250, max(1, (int) ($data['max_featured_categories'] ?? 50))));
        self::globalUpdate('LLMSCATALOG_RICH_PRODUCT', !empty($data['rich_product']) ? '1' : '0');
        foreach ([
            'bytes'=>['PRODUCT_MAX_BYTES','product_max_bytes',262144,262144], 'variants'=>['PRODUCT_MAX_VARIANTS','product_max_variants',50,200],
            'images'=>['PRODUCT_MAX_IMAGES','product_max_images',8,50], 'specs'=>['PRODUCT_MAX_SPECS','product_max_specs',50,200],
            'categories'=>['PRODUCT_MAX_CATEGORIES','product_max_categories',10,50], 'pack'=>['PRODUCT_MAX_PACK_ITEMS','product_max_pack_items',50,200],
            'custom'=>['PRODUCT_MAX_CUSTOM_FIELDS','product_max_custom_fields',25,100],
        ] as [$key,$field,$default,$max]) self::globalUpdate('LLMSCATALOG_' . $key, (string) min($max, max($key === 'PRODUCT_MAX_BYTES' ? 1024 : 1, (int) ($data[$field] ?? $default))));
        $providerIds = array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($data['product_providers'] ?? ''))), static function($id){ return preg_match('/^[a-z0-9_.-]{1,64}$/i',$id)===1; }))), 0, 10);
        sort($providerIds, SORT_STRING);
        self::globalUpdate('LLMSCATALOG_PRODUCT_PROVIDERS', json_encode($providerIds));
        $sections=[]; foreach (['ratings','shipping','returns','warranty'] as $section) $sections[$section]=!empty($data['product_'.$section]);
        self::globalUpdate('LLMSCATALOG_PRODUCT_SECTIONS', json_encode($sections));

        $titles = $this->localized('LLMSCATALOG_TITLES');
        $summaries = $this->localized('LLMSCATALOG_SUMMARIES');
        foreach (Language::getLanguages(true) as $language) {
            $id = (int) $language['id_lang'];
            if (array_key_exists('title_' . $id, $data)) $titles[$id] = trim((string) $data['title_' . $id]);
            if (array_key_exists('summary_' . $id, $data)) $summaries[$id] = trim((string) $data['summary_' . $id]);
        }
        self::globalUpdate('LLMSCATALOG_TITLES', json_encode($titles));
        self::globalUpdate('LLMSCATALOG_SUMMARIES', json_encode($summaries));
        $this->bumpConfigVersion();
    }

    private function localized(string $key): array
    {
        $value = self::global($key);
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function global(string $key)
    {
        return Configuration::get($key, null, 0, 0);
    }

    private static function globalUpdate(string $key, $value): bool
    {
        return (bool) Configuration::updateValue($key, $value, false, 0, 0);
    }
}
