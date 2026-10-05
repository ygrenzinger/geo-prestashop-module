<?php

namespace LlmsCatalog\Service;

use Context;
use LlmsCatalog\Service\PublicPriceContextFactory;
use LlmsCatalog\Service\LanguageResolver;
use LlmsCatalog\ValueObject\ResolvedLanguage;
use LlmsCatalog\ValueObject\LocalizedField;

class LlmsGenerator
{
    private bool $degraded = false;
    private int $originExpiry = 0;
    private int $generatedAt = 0;
    private DocumentCachePolicy $cachePolicy;
    private IndexDocumentRenderer $indexRenderer;
    private ProductDocumentRenderer $productRenderer;
    public function __construct(
        private Settings $settings,
        private CatalogRepository $catalog,
        private UrlBuilder $urls,
        private MarkdownRenderer $markdown,
        private CacheManager $cache,
        private Context $context,
        ?DocumentCachePolicy $cachePolicy = null
    ) {
        $this->cachePolicy = $cachePolicy ?? new DocumentCachePolicy($cache, $settings);
        $this->indexRenderer = new IndexDocumentRenderer($settings, $urls, $markdown, $cache, $context);
        $this->productRenderer = new ProductDocumentRenderer($settings, $markdown, $urls, $context);
    }

    public function generateIndex(int $langId): string
    {
        $this->degraded = false;
        $this->originExpiry = 0;
        if (!$this->settings->isEnabled()) {
            return '';
        }

        $priceContext = (new PublicPriceContextFactory())->create($this->context, $langId);
        $resolved = $this->resolvedLanguage($langId);
        $t = $this->translations($resolved);
        $key = $this->cache->key('index', $langId, $this->settings, $priceContext->fingerprint(), 0, $this->translationFingerprint($t, $resolved));
        $cached = $this->cache->get($key, 'index', $langId);
        if ($cached !== null) {
            $this->originExpiry = (int) $cached['expires_at'];
            $this->generatedAt = (int) ($cached['generated_at'] ?? time());
            return (string) $cached['body'];
        }
        $stale = $this->cache->getStale($key, 'index', $langId);
        if (!$this->cache->acquireGenerationLock($key)) {
            if ($stale !== null) { $this->originExpiry = time(); $this->generatedAt = (int) $stale['generated_at']; return (string) $stale['body']; }
            throw new GenerationBusyException('Document generation is already in progress');
        }
        try {
        $cached = $this->cache->get($key, 'index', $langId);
        if ($cached !== null) { $this->originExpiry = (int) $cached['expires_at']; $this->generatedAt = (int) ($cached['generated_at'] ?? time()); return (string) $cached['body']; }
        $revisionToken = $this->cache->revisionToken('index');

        $categories = $this->settings->includesCategories() ? $this->catalog->categories($langId, $this->settings->completeIndexes() ? $this->settings->maxFeaturedCategories() : 0) : [];
        $products = $this->settings->includesProducts() ? $this->catalog->products($langId, $this->settings->completeIndexes() ? $this->settings->maxProducts() : $this->settings->configuredMaxProducts()) : [];
        $productTotal = $this->settings->includesProducts() ? $this->catalog->eligibleProductCount($langId) : 0;
        $categoryTotal = $this->settings->includesCategories() ? $this->catalog->activeCategoryCount() : 0;
        foreach ($products as $product) {
            $this->degraded = $this->degraded || !empty($product['price_degraded']);
        }
        $content = $this->indexRenderer->render($langId, $categories, $products, $productTotal, $categoryTotal, $t, $resolved, $priceContext);
        $promotionExpiry = null;
        foreach ($products ?? [] as $product) {
            if (!empty($product['price_expiry'])) {
                $promotionExpiry = $promotionExpiry === null ? (int) $product['price_expiry'] : min($promotionExpiry, (int) $product['price_expiry']);
            }
        }
        if (!$this->degraded) {
            $ttl = $this->cachePolicy->ttl($promotionExpiry);
            $this->originExpiry = time() + $ttl;
            $stored = $this->cachePolicy->persist($key, $content, $ttl, $this->originExpiry, $revisionToken, 'index', $langId, 0, $priceContext->fingerprint());
            if ($stored !== null) {
                $this->originExpiry = (int) ($stored['expires_at'] ?? $this->originExpiry);
                $this->generatedAt = (int) ($stored['generated_at'] ?? time());
            } else { $this->degraded = true; $this->originExpiry = time(); }
        }

        return $content;
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.index.stale-fallback', (int) ($this->context->shop->id ?? 0), $exception);
            if ($stale !== null) {
                $this->originExpiry = time();
                $this->generatedAt = (int) $stale['generated_at'];
                return (string) $stale['body'];
            }
            throw $exception;
        } finally {
            $this->cache->releaseGenerationLock($key);
        }
    }

    public function generateProduct(int $idProduct, int $langId): ?string
    {
        $this->degraded = false;
        $this->originExpiry = 0;
        if (!$this->settings->isEnabled()) {
            return null;
        }

        $priceContext = (new PublicPriceContextFactory())->create($this->context, $langId);
        $resolved = $this->resolvedLanguage($langId);
        $t = $this->translations($resolved);
        $key = $this->cache->key('product', $langId, $this->settings, $priceContext->fingerprint(), $idProduct, hash('sha256', $this->translationFingerprint($t, $resolved) . ':' . $this->productProfileFingerprint()));
        $cached = $this->cache->get($key, 'product', $langId, $idProduct);
        if ($cached !== null) {
            $this->originExpiry = (int) $cached['expires_at'];
            $this->generatedAt = (int) ($cached['generated_at'] ?? time());
            return (string) $cached['body'];
        }
        $stale = $this->cache->getStale($key, 'product', $langId, $idProduct);
        if (!$this->cache->acquireGenerationLock($key)) {
            if ($stale !== null) { $this->originExpiry = time(); $this->generatedAt = (int) $stale['generated_at']; return (string) $stale['body']; }
            throw new GenerationBusyException('Document generation is already in progress');
        }
        try {
        $cached = $this->cache->get($key, 'product', $langId, $idProduct);
        if ($cached !== null) { $this->originExpiry = (int) $cached['expires_at']; $this->generatedAt = (int) ($cached['generated_at'] ?? time()); return (string) $cached['body']; }
        $revisionToken = $this->cache->revisionToken('product', $idProduct);
        $product = $this->catalog->product($idProduct, $langId);
        if ($product === null) {
            return null;
        }

        if ($this->settings->richProductEnabled()) {
            $details = $this->catalog->richProductDetails($idProduct, $langId, $this->settings, (int)($product['availability']['combination_id'] ?? 0));
            if ($details === []) return null;
            $body = $this->productRenderer->render($product, $details, $idProduct, $langId, $t, $resolved);
            $degraded = !empty($product['price_degraded']);
            $this->degraded = $degraded;
            if (!$degraded) {
                $ttl = $this->cachePolicy->ttl($product['price_expiry'] ?? null);
                $this->originExpiry = time() + $ttl;
                $stored = $this->cachePolicy->persist($key, $body, $ttl, $this->originExpiry, $revisionToken, 'product', $langId, $idProduct, $priceContext->fingerprint());
                if ($stored !== null) {
                    $this->originExpiry = (int) ($stored['expires_at'] ?? $this->originExpiry);
                    $this->generatedAt = (int) ($stored['generated_at'] ?? time());
                } else { $this->degraded = true; $this->originExpiry = time(); }
            }
            return $body;
        }

        $body = $this->productRenderer->renderBasic($product, $idProduct, $langId, $t, $resolved);
        $degraded = !empty($product['price_degraded']);
        $this->degraded = $degraded;
        if (!$degraded) {
            $ttl = $this->cachePolicy->ttl($product['price_expiry'] ?? null);
            $this->originExpiry = time() + $ttl;
            $stored = $this->cachePolicy->persist($key, $body, $ttl, $this->originExpiry, $revisionToken, 'product', $langId, $idProduct, $priceContext->fingerprint());
            if ($stored !== null) {
                $this->generatedAt = (int) ($stored['generated_at'] ?? time());
            } else { $this->degraded = true; $this->originExpiry = time(); }
        }
        return $body;
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.product.stale-fallback', (int) ($this->context->shop->id ?? 0), $exception);
            if ($stale !== null) {
                $this->originExpiry = time();
                $this->generatedAt = (int) $stale['generated_at'];
                return (string) $stale['body'];
            }
            throw $exception;
        } finally {
            $this->cache->releaseGenerationLock($key);
        }
    }

    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    public function originExpiry(): int
    {
        return $this->originExpiry;
    }

    public function generatedAt(): int { return $this->generatedAt ?: time(); }

    private function productProfileFingerprint(): string
    {
        return implode(':', [$this->settings->configVersion(), (int)$this->settings->richProductEnabled(), $this->settings->productLimit('bytes'), $this->settings->productLimit('variants'), $this->settings->productLimit('images'), $this->settings->productLimit('specs'), $this->settings->productLimit('categories'), $this->settings->productLimit('pack'), $this->settings->productLimit('custom')]);
    }

    private function resolvedLanguage(int $langId): ResolvedLanguage
    {
        $language = new \Language($langId);
        $resolved = (new LanguageResolver())->resolve((string) $language->locale, $this->context);
        if ($resolved === null) throw new \RuntimeException('Requested language is not active for this shop');
        return $resolved;
    }

    private function translations(ResolvedLanguage $language): TranslationResolver
    {
        $module = \Module::getInstanceByName('llmscatalog');
        if (!$module) throw new \RuntimeException('Module translation context unavailable');
        return new TranslationResolver($module, $language->locale, $language->defaultLocale);
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

    private function translationFingerprint(TranslationResolver $translations, ResolvedLanguage $language): string
    {
        $messages = [
            'This index describes the public catalog of this shop. Product links point to concise Markdown representations.', 'Categories', 'Public catalog category', 'Products',
            'Public product information from %shop_name%.', 'Canonical URL', 'Price', 'Availability', 'In stock', 'Out of stock', 'Available on backorder', 'Not available for order',
            'Price: %price%.', 'Public prices use %currency% and the shop default tax rules.', 'Availability schema', 'Availability label', 'Availability date', 'Other variants orderable', 'Yes',
            'Some catalog content is shown in %fallback_locale% because it is unavailable in %requested_locale%.',
        ];
        $resolved = array_map(static fn ($message) => $translations->shop($message), $messages);
        return hash('sha256', json_encode([$language->locale, $language->defaultLocale, $resolved], JSON_UNESCAPED_UNICODE));
    }
}
