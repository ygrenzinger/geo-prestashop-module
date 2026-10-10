<?php

namespace LlmsCatalog\Service;

use Configuration;
use Context;
use Image;
use Product;

/** Builds opt-in, missing-only JSON-LD for the current public page. */
final class StructuredDataPageAugmentationService
{
    public function augment(array &$params): void
    {
        if (!isset($params['html']) || !is_string($params['html'])) {
            return;
        }

        $originalHtml = $params['html'];
        $shopId = 0;

        try {
            $context = Context::getContext();
            $shopId = (int) ($context->shop->id ?? 0);
            $groupId = (int) ($context->shop->id_shop_group ?? 0);
            $controller = $context->controller ?? null;
            if ($shopId < 1 || !$controller) {
                return;
            }

            $settings = new Settings();
            $augmenter = new StructuredDataAugmenter();
            $pageType = (string) ($controller->php_self ?? '');

            if ($pageType === 'index'
                && $this->enabled('LLMSCATALOG_AUGMENT_ORGANIZATION', $groupId, $shopId)) {
                $candidate = $this->organizationCandidate($context, $groupId, $shopId);
                if ($candidate !== null) {
                    $params['html'] = $augmenter->augment($params['html'], $candidate, 'Organization', true);
                }
                return;
            }

            if ($pageType !== 'product') {
                return;
            }

            $productEnabled = $this->enabled('LLMSCATALOG_AUGMENT_PRODUCT', $groupId, $shopId);
            $breadcrumbEnabled = $this->enabled('LLMSCATALOG_AUGMENT_BREADCRUMB', $groupId, $shopId);
            if (!$productEnabled && !$breadcrumbEnabled) {
                return;
            }

            $languageId = (int) ($context->language->id ?? 0);
            $productId = (int) \Tools::getValue('id_product');
            if ($languageId < 1 || $productId < 1) {
                return;
            }

            $repository = new CatalogRepository($context);
            $product = $repository->product($productId, $languageId);
            if ($product === null || !$this->isCanonicalProductRequest($context, $productId, $product, $languageId)) {
                return;
            }

            $productCandidateAllowed = $productEnabled && $this->hasPublicOffer($product);
            if (!$productCandidateAllowed && !$breadcrumbEnabled) {
                return;
            }

            $details = $repository->richProductDetails($productId, $languageId, $settings, 0);
            if (!$details) {
                return;
            }

            if ($productCandidateAllowed) {
                $candidate = $this->productCandidate($context, $productId, $product, $details, $languageId);
                if ($candidate !== null) {
                    $params['html'] = $augmenter->augment($params['html'], $candidate, 'Product', true);
                }
            }

            if ($breadcrumbEnabled) {
                $candidate = $this->breadcrumbCandidate($context, $productId, $product, $details, $languageId, $groupId, $shopId);
                if ($candidate !== null) {
                    $params['html'] = $augmenter->augment($params['html'], $candidate, 'BreadcrumbList', true);
                }
            }
        } catch (\Throwable $exception) {
            // A failed augmentation must not prevent the storefront from serving its HTML.
            $params['html'] = $originalHtml;
            $this->logFailure($shopId, $exception);
        }
    }

    private function organizationCandidate(Context $context, int $groupId, int $shopId): ?array
    {
        $languageId = (int) ($context->language->id ?? 0);
        $name = trim((string) Configuration::get('PS_SHOP_NAME', $languageId, $groupId, $shopId));
        $urls = new UrlBuilder($context);
        $baseUrl = rtrim($urls->baseLink(), '/') . '/';
        if ($name === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        $description = (new OrganizationDescriptionResolver())->resolve(
            (string) Configuration::get('PS_META_DESCRIPTION', $languageId, $groupId, $shopId),
            (string) Configuration::get('PS_META_DESCRIPTION', $urls->defaultLanguageId(), $groupId, $shopId)
        );

        $candidate = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => rtrim($baseUrl, '/') . '#organization',
            'name' => $name,
            'url' => $baseUrl,
        ];
        if ($description !== null) {
            $candidate['description'] = $description;
        }

        return $candidate;
    }

    private function productCandidate(Context $context, int $productId, array $product, array $details, int $languageId): ?array
    {
        if (($details['type'] ?? '') !== 'physical'
            || !empty($product['availability']['combination_id'])
            || !empty($product['availability']['other_variants_orderable'])
            || empty($details['images'])) {
            return null;
        }

        $canonical = (new UrlBuilder($context))->productHtmlUrl($productId, (string) $product['link_rewrite'], $languageId);
        $images = [];
        foreach ($details['images'] as $imageRow) {
            $image = new Image((int) ($imageRow['id'] ?? 0));
            if (\Validate::isLoadedObject($image)) {
                $images[] = $context->link->getImageLink((string) $product['link_rewrite'], (int) $image->id, 'large_default');
            }
        }
        if (!$images) {
            return null;
        }

        $availability = (string) ($product['availability']['schema_url'] ?? '');
        if (!in_array($availability, [
            'https://schema.org/InStock',
            'https://schema.org/OutOfStock',
            'https://schema.org/BackOrder',
            'https://schema.org/Discontinued',
        ], true)) {
            return null;
        }

        $condition = [
            'new' => 'NewCondition',
            'used' => 'UsedCondition',
            'refurbished' => 'RefurbishedCondition',
        ][strtolower((string) ($details['condition'] ?? 'new'))] ?? null;
        $candidate = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $canonical . '#product',
            'name' => (string) $product['name'],
            'url' => $canonical,
            'image' => $images,
            'offers' => [
                '@type' => 'Offer',
                'url' => $canonical,
                'price' => (string) $product['price'],
                'priceCurrency' => (string) $product['currency'],
                'availability' => $availability,
            ],
        ];
        if ($condition !== null) {
            $candidate['offers']['itemCondition'] = 'https://schema.org/' . $condition;
        }

        foreach (['reference' => 'sku'] as $source => $target) {
            if (!empty($product[$source])) {
                $candidate[$target] = (string) $product[$source];
            }
        }
        foreach (['ean13' => 'gtin13', 'mpn' => 'mpn'] as $source => $target) {
            if (!empty($details[$source])) {
                $candidate[$target] = (string) $details[$source];
            }
        }
        if (!empty($details['manufacturer'])) {
            $candidate['brand'] = ['@type' => 'Brand', 'name' => (string) $details['manufacturer']];
        }

        return $candidate;
    }

    private function breadcrumbCandidate(
        Context $context,
        int $productId,
        array $product,
        array $details,
        int $languageId,
        int $groupId,
        int $shopId
    ): ?array {
        if (empty($details['categories'])) {
            return null;
        }

        $baseUrl = rtrim((new UrlBuilder($context))->baseLink(), '/') . '/';
        $shopName = trim((string) Configuration::get('PS_SHOP_NAME', $languageId, $groupId, $shopId));
        if ($shopName === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => $shopName, 'item' => $baseUrl]];
        $position = 2;
        $productObject = new Product($productId, false, $languageId, $shopId);
        $categoryId = (int) ($productObject->id_category_default ?? 0);
        $defaultCategory = new \Category($categoryId, $languageId, $shopId);
        $parents = \Validate::isLoadedObject($defaultCategory) && $defaultCategory->active
            ? $defaultCategory->getParentsCategories($languageId)
            : [];

        foreach (array_slice((array) $parents, 0, 9) as $parentRow) {
            $parentId = (int) ($parentRow['id_category'] ?? 0);
            if ($parentId < 1) {
                continue;
            }
            $category = new \Category($parentId, $languageId, $shopId);
            if (!\Validate::isLoadedObject($category) || !$category->active) {
                continue;
            }
            $name = trim((string) ($parentRow['name'] ?? $category->name));
            if ($name !== '') {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $name,
                    'item' => $context->link->getCategoryLink($category),
                ];
            }
        }

        $canonical = (new UrlBuilder($context))->productHtmlUrl($productId, (string) $product['link_rewrite'], $languageId);
        $items[] = ['@type' => 'ListItem', 'position' => $position, 'name' => (string) $product['name'], 'item' => $canonical];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    private function isCanonicalProductRequest(Context $context, int $productId, array $product, int $languageId): bool
    {
        $urls = new UrlBuilder($context);
        $canonical = $urls->productHtmlUrl($productId, (string) ($product['link_rewrite'] ?? ''), $languageId);
        $expectedPath = (string) parse_url($canonical, PHP_URL_PATH);
        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return $expectedPath !== '' && $requestPath === $expectedPath;
    }

    private function hasPublicOffer(array $product): bool
    {
        return !empty($product['show_price'])
            && array_key_exists('price', $product)
            && is_numeric($product['price'])
            && empty($product['price_degraded'])
            && !empty($product['currency'])
            && !empty($product['availability']['schema_url']);
    }

    private function enabled(string $key, int $groupId, int $shopId): bool
    {
        return (bool) Configuration::get($key, null, $groupId, $shopId);
    }

    private function logFailure(int $shopId, \Throwable $exception): void
    {
        SafeDiagnosticLogger::log('public.structured-data.augment', $shopId, $exception);
    }
}
