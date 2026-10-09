<?php

namespace LlmsCatalog\Service;

use Context;
use LlmsCatalog\ValueObject\LocalizedField;
use LlmsCatalog\ValueObject\PublicPriceContext;
use LlmsCatalog\ValueObject\ResolvedLanguage;
use Tools;

/** Renders the localized root catalog document from prepared application data. */
class IndexDocumentRenderer
{
    public function __construct(
        private Settings $settings,
        private UrlBuilder $urls,
        private MarkdownRenderer $markdown,
        private CacheManager $cache,
        private Context $context
    ) {
    }

    public function render(int $langId, array $categories, array $products, int $productTotal, int $categoryTotal, TranslationResolver $t, ResolvedLanguage $resolved, PublicPriceContext $priceContext): string
    {
        $fallback = new FallbackTracker();
        foreach ($categories as $row) $this->observeFallback($fallback, $row, $langId);
        foreach ($products as $row) $this->observeFallback($fallback, $row, $langId);
        $fallback->observeConfiguredFallback($this->settings->titleUsesFallback($langId, $resolved->defaultLanguageId));
        $fallback->observeConfiguredFallback($this->settings->summaryUsesFallback($langId, $resolved->defaultLanguageId));
        $summary = $this->settings->summary($langId, $resolved->defaultLanguageId);
        if ($summary === '') $summary = $t->shop('This index describes the public catalog of this shop. Product links point to concise Markdown representations.');
        $lines = [
            '# ' . $this->markdown->text($this->settings->title($langId, $resolved->defaultLanguageId)),
            '',
            '> ' . $this->markdown->text($summary),
            '',
            $t->shop('This index describes the public catalog of this shop. Product links point to concise Markdown representations.'),
            '',
        ];
        if ($fallback->hasFallback()) {
            $lines[] = '> ' . $fallback->disclosure($t, $resolved);
            $lines[] = '';
        }

        if ($this->settings->completeIndexes()) {
            $lines[] = 'Catalog version: ' . $this->cache->catalogVersion() . '.';
            if ($this->settings->includesProducts()) {
                $lines[] = 'Eligible products: ' . $productTotal . '.';
                $lines[] = 'Featured on this page: ' . count($products) . '.';
                $lines[] = 'Products omitted from this root page: ' . max(0, $productTotal - count($products)) . '.';
                $lines[] = '[Complete product catalog](' . $this->urls->catalogProductsUrl($langId) . ')';
                $lines[] = 'All eligible products are reachable through the complete catalog.';
            } else { $lines[] = 'Eligible products are omitted because product indexing is disabled.'; }
            if ($this->settings->includesCategories()) {
                $lines[] = 'Active categories: ' . $categoryTotal . '.';
                $lines[] = 'Featured categories: ' . count($categories) . '.';
                $lines[] = 'Categories omitted from this root page: ' . max(0, $categoryTotal - count($categories)) . '.';
                $lines[] = '[Complete category directory](' . $this->urls->catalogCategoriesUrl($langId) . ')';
                $lines[] = 'All active categories are reachable through the category directory.';
            }
            $lines[] = '';
        }

        if ($this->settings->includesCategories()) {
            $lines[] = '## ' . $t->shop('Categories');
            foreach ($categories as $category) {
                $categoryUrl = $this->categoryUrl((int) $category['id_category'], (string) $category['link_rewrite'], $langId);
                $lines[] = $this->markdown->link((string) $category['name'], $categoryUrl, $t->shop('Public catalog category'));
            }
            $lines[] = '';
        }

        if ($this->settings->includesProducts()) {
            if ($this->settings->includesPrice()) {
                $lines[] = $t->shop('Public prices use %currency% and the shop default tax rules.', ['%currency%' => $priceContext->get('currency_iso')]);
                $lines[] = '';
            }
            $lines[] = '## ' . $t->shop('Products');
            foreach ($products as $product) {
                $url = $this->urls->productMarkdownUrl((int) $product['id_product'], (string) $product['link_rewrite'], $langId);
                $lines[] = $this->markdown->link((string) $product['name'], $url, $this->productDescription($product, $t));
            }
            $lines[] = '';
        }

        $content = $this->markdown->truncate(implode("\n", $lines), $this->settings->maxBytes(), $t->shop('This index is truncated by the configured output limit. Use the linked product endpoints or the shop catalog for additional items.'));
        if ($this->settings->completeIndexes()) {
            $shownProducts = 0;
            foreach ($products as $product) if (strpos($content, $this->urls->productMarkdownUrl((int) $product['id_product'], (string) $product['link_rewrite'], $langId)) !== false) $shownProducts++;
            $content = str_replace('Featured on this page: ' . count($products) . '.', 'Featured on this page: ' . $shownProducts . '.', $content);
            $content = str_replace('Products omitted from this root page: ' . max(0, $productTotal - count($products)) . '.', 'Products omitted from this root page: ' . max(0, $productTotal - $shownProducts) . '.', $content);
            $shownCategories = 0;
            foreach ($categories as $category) if (strpos($content, $this->categoryUrl((int) $category['id_category'], (string) $category['link_rewrite'], $langId)) !== false) $shownCategories++;
            $content = str_replace('Featured categories: ' . count($categories) . '.', 'Featured categories: ' . $shownCategories . '.', $content);
            $content = str_replace('Categories omitted from this root page: ' . max(0, $categoryTotal - count($categories)) . '.', 'Categories omitted from this root page: ' . max(0, $categoryTotal - $shownCategories) . '.', $content);
            $content = $this->markdown->truncate($content, $this->settings->maxBytes(), $t->shop('This index is truncated by the configured output limit. Use the linked product endpoints or the shop catalog for additional items.'));
        }

        return $content;
    }

    private function productDescription(array $product, TranslationResolver $t): string
    {
        $description = $this->markdown->text((string) $product['description_short']);
        if ($this->settings->includesPrice() && !empty($product['show_price']) && !empty($product['price_formatted'])) {
            $description .= ($description === '' ? '' : ' ') . $t->shop('Price: %price%.', ['%price%' => $product['price_formatted'] . ' (' . $product['currency'] . ')']);
        }
        if ($this->settings->includesAvailability()) {
            $description .= ' ' . $t->shop('Availability') . ': ' . $this->stockTerm((string) $product['availability']['state'], $t) . '.';
        }
        return trim($description);
    }

    private function categoryUrl(int $idCategory, string $rewrite, int $langId): string
    {
        return $this->context->link->getCategoryLink($idCategory, Tools::str2url($rewrite), $langId);
    }

    private function observeFallback(FallbackTracker $tracker, array $row, int $requestedLanguageId): void
    {
        foreach (['name_source_lang' => 'name', 'description_short_source_lang' => 'description_short', 'link_rewrite_source_lang' => 'link_rewrite'] as $field => $valueField) {
            $tracker->observe(new LocalizedField((string) ($row[$valueField] ?? ''), (int) ($row[$field] ?? 0)), $requestedLanguageId);
        }
    }

    private function stockTerm(string $state, TranslationResolver $translations): string
    {
        if ($state === 'in_stock') return $translations->shop('In stock');
        if ($state === 'backorder') return $translations->shop('Available on backorder');
        if ($state === 'not_for_sale') return $translations->shop('Not available for order');
        return $translations->shop('Out of stock');
    }
}
