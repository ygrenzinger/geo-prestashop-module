<?php

namespace LlmsCatalog\Service;

use Context;
use LlmsCatalog\ValueObject\PublicDocument;

/** Coordinates public route decisions and maps their outcomes to response documents. */
final class PublicDocumentApplicationService
{
    private const TEXT = 'text/plain';
    private const MARKDOWN = 'text/markdown';
    private const CATALOG_MARKDOWN = 'text/markdown; charset=utf-8';

    public function index(Context $context, $module): PublicDocument
    {
        if (!$this->allowsReadMethod()) return $this->methodNotAllowed(self::TEXT, true);

        $urls = new UrlBuilder($context);
        $language = (new LanguageResolver())->resolveRequest($context, $this->routeLocale());
        $languageId = $language?->languageId ?? 0;
        $settings = new Settings();
        if (!$module || !$module->active || !$settings->isEnabled() || $language === null) {
            $message = $module ? $this->translatedError($module, $context, 'Not found') : 'Not found';
            return new PublicDocument($message . "\n", self::TEXT, 0, 404, false);
        }
        if ($language->needsCanonicalRedirect || !$urls->isCanonicalRequest($language->locale, 'catalogindex')) {
            $canonical = $urls->indexUrl($languageId);
            return new PublicDocument('', self::TEXT, $languageId, 301, false, 0, [
                'Location' => $canonical . $urls->redirectQuery(),
                'Link' => '<' . $canonical . '>; rel="canonical"',
            ]);
        }

        try {
            $generator = $module->getGenerator();
            $body = $generator->generateIndex($languageId);
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.index.generate', (int) ($context->shop->id ?? 0), $exception);
            return $this->generationFailure(self::TEXT, $languageId, $exception);
        }

        $status = $body === '' ? 404 : 200;
        if ($status === 404) $body = (new TranslationResolver($module, $language->locale, $language->defaultLocale))->shop('Not found') . "\n";
        $headers = $status === 200 ? (new LinkHeaderBuilder())->index($urls, $languageId) : [];
        return new PublicDocument($body, self::TEXT, $languageId, $status, $status === 200 && !$generator->isDegraded(), $generator->originExpiry(), $headers, $generator->generatedAt());
    }

    public function product(Context $context, $module): PublicDocument
    {
        if (!$this->allowsReadMethod()) return $this->methodNotAllowed(self::MARKDOWN, true);

        $urls = new UrlBuilder($context);
        $language = (new LanguageResolver())->resolveRequest($context, $this->routeLocale());
        $languageId = $language?->languageId ?? 0;
        $settings = new Settings();
        if (!$module || !$module->active || !$settings->isEnabled() || $language === null) {
            $message = $module ? $this->translatedError($module, $context, 'Product not found') : 'Product not found';
            return new PublicDocument($message . "\n", self::MARKDOWN, 0, 404, false);
        }

        $rawId = $urls->requestedProductId();
        if (!(new CanonicalRequestResolver())->validPositiveInteger($rawId)) {
            return new PublicDocument("Product not found\n", self::MARKDOWN, $languageId, 404, false);
        }
        $productId = (int) $rawId;
        try {
            // Check eligibility before disclosing the canonical product URL.
            $product = $urls->productData($productId, $languageId);
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.product.lookup', (int) ($context->shop->id ?? 0), $exception);
            $product = null;
        }
        if ($product === null) return new PublicDocument("Product not found\n", self::MARKDOWN, $languageId, 404, false);

        $rewrite = \Tools::str2url((string) $product['link_rewrite']);
        if ($rewrite === '' || !\Validate::isLinkRewrite($rewrite)) {
            return new PublicDocument("Product not found\n", self::MARKDOWN, $languageId, 404, false);
        }
        $canonical = $urls->productMarkdownUrl($productId, $rewrite, $languageId);
        if ($language->needsCanonicalRedirect || !$urls->isCanonicalRequest($language->locale, 'product', $productId, $rewrite)) {
            return new PublicDocument('', self::MARKDOWN, $languageId, 301, false, 0, [
                'Location' => $canonical . $urls->redirectQuery(),
                'Link' => '<' . $canonical . '>; rel="canonical"',
            ]);
        }

        try {
            $generator = $module->getGenerator();
            $body = $generator->generateProduct($productId, $languageId);
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.product.generate', (int) ($context->shop->id ?? 0), $exception);
            return $this->generationFailure(self::MARKDOWN, $languageId, $exception);
        }
        if ($body === null) {
            $message = (new TranslationResolver($module, $language->locale, $language->defaultLocale))->shop('Product not found');
            return new PublicDocument($message . "\n", self::MARKDOWN, $languageId, 404, false);
        }

        $rich = $settings->richProductEnabled();
        return new PublicDocument(
            $body,
            self::MARKDOWN,
            $languageId,
            200,
            !$generator->isDegraded(),
            $generator->originExpiry(),
            (new LinkHeaderBuilder())->product($urls, $productId, $rewrite, $languageId),
            $generator->generatedAt(),
            !$rich
        );
    }

    public function catalog(Context $context, $module): PublicDocument
    {
        if (!$this->allowsReadMethod()) return $this->methodNotAllowed(self::TEXT, false);

        $settings = new Settings();
        $kind = (string) \Tools::getValue('catalog_kind');
        $rawCursor = (string) \Tools::getValue('cursor');
        $cursor = ctype_digit($rawCursor) ? (int) $rawCursor : -1;
        $categoryId = (int) \Tools::getValue('id_category');
        $rewrite = (string) \Tools::getValue('rewrite');
        if ($kind === 'category') $cursor = 0;
        $language = (new LanguageResolver())->resolveRequest($context, $this->routeLocale());
        $languageId = $language?->languageId ?? 0;
        if (!$module || !$module->active || !$settings->isEnabled() || !$settings->completeIndexes() || $language === null
            || !in_array($kind, ['products', 'categories', 'category', 'category-products'], true)
            || ($kind !== 'category' && $cursor < 0)
            || (($kind === 'category' || $kind === 'category-products') && $categoryId < 1)) {
            return new PublicDocument("Not found\n", self::TEXT, 0, 404, false);
        }

        try {
            $urls = new UrlBuilder($context);
            if ($language->needsCanonicalRedirect) {
                $target = $this->catalogUrl($urls, $kind, $languageId, $categoryId, $rewrite, $cursor);
                return new PublicDocument('', self::TEXT, $languageId, 301, false, 0, ['Location' => $target]);
            }

            $cache = new CacheManager();
            $cacheKind = $kind === 'category' ? 'category' : ($kind === 'category-products' ? 'category-products' : 'catalog-' . $kind);
            $cacheKey = $cache->key($cacheKind, $languageId, $settings, 'catalog-v1', 0, $rewrite, $cursor, $categoryId);
            $cached = $cache->get($cacheKey, 'index', $languageId);
            if ($cached !== null) {
                $root = $urls->indexUrl($languageId);
                return new PublicDocument($cached['body'], self::CATALOG_MARKDOWN, $languageId, 200, true, (int) $cached['expires_at'], ['Link' => '<' . $root . '>; rel="describedby"']);
            }

            $repository = new CatalogRepository($context);
            $category = null;
            if ($kind === 'category' || $kind === 'category-products') {
                $category = $repository->category($categoryId, $languageId);
                if ($category === null) return new PublicDocument("Not found\n", self::TEXT, $languageId, 404, false);
                $canonicalRewrite = trim((string) ($category['link_rewrite'] ?? '')) ?: 'category-' . $categoryId;
                if ($rewrite !== $canonicalRewrite) {
                    $target = $kind === 'category'
                        ? $urls->categoryMarkdownUrl($languageId, $categoryId, $canonicalRewrite)
                        : $urls->categoryProductsUrl($languageId, $categoryId, $canonicalRewrite, $cursor);
                    return new PublicDocument('', self::TEXT, $languageId, 301, false, 0, ['Location' => $target]);
                }
            }

            $generator = new CatalogPageGenerator($repository, $urls, $settings);
            $body = $kind === 'category'
                ? $generator->categoryOverview($languageId, $categoryId, $category)
                : ($kind === 'category-products'
                    ? $generator->categoryProducts($languageId, $categoryId, $cursor, (string) $category['link_rewrite'])
                    : $generator->{$kind}($languageId, $cursor));
            if ($body === null) return new PublicDocument("Not found\n", self::TEXT, $languageId, 404, false);

            $expiry = time() + $settings->cacheTtl();
            $cache->put($cacheKey, ['body' => $body, 'etag' => '"' . hash('sha256', $body) . '"', 'expires_at' => $expiry], $settings->cacheTtl(), 'index', $languageId);
            $root = $urls->indexUrl($languageId);
            return new PublicDocument($body, self::CATALOG_MARKDOWN, $languageId, 200, true, $expiry, ['Link' => '<' . $root . '>; rel="describedby"']);
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.catalog.generate', (int) ($context->shop->id ?? 0), $exception);
            return new PublicDocument('', self::TEXT, $languageId, 503, false, 0, ['Retry-After' => '5']);
        }
    }

    public function root(Context $context, $module): PublicDocument
    {
        if (!$this->allowsReadMethod(true)) return $this->methodNotAllowed(self::TEXT, true);

        $settings = new Settings();
        if (!$module || !$module->active || !$settings->isEnabled()) return new PublicDocument("Not found\n", self::TEXT, 0, 404, false);

        $resolver = new LanguageResolver();
        $legacyLanguage = $resolver->resolveRequest($context);
        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $basePath = (string) parse_url($context->link->getBaseLink((int) $context->shop->id, true), PHP_URL_PATH);
        $basePath = '/' . trim($basePath, '/');
        if ($basePath !== '/' && strpos($requestPath, $basePath) === 0) $requestPath = substr($requestPath, strlen($basePath));
        $firstSegment = rawurldecode(explode('/', trim($requestPath, '/'))[0] ?? '');
        $hasLocalePrefix = $firstSegment !== '' && strcasecmp($firstSegment, 'llms.txt') !== 0;
        if ($hasLocalePrefix && $legacyLanguage === null) return new PublicDocument("Not found\n", self::TEXT, 0, 404, false);
        if ($legacyLanguage !== null) {
            $urls = new UrlBuilder($context);
            $canonical = $urls->indexUrl($legacyLanguage->languageId);
            return new PublicDocument('', self::TEXT, 0, 301, false, 0, [
                'Location' => $canonical . $urls->redirectQuery(),
                'Link' => '<' . $canonical . '>; rel="canonical"',
            ]);
        }

        try {
            [$body, $expiry, $generated] = (new RootIndexGenerator($context, $settings, new CacheManager()))->generate();
            return new PublicDocument($body, self::TEXT, 0, 200, true, $expiry, [], $generated);
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.root.generate', (int) ($context->shop->id ?? 0), $exception);
            return new PublicDocument('', self::TEXT, 0, 503, false, 0, ['Retry-After' => '5']);
        }
    }

    private function allowsReadMethod(bool $normalizeMethod = false): bool
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($normalizeMethod) $method = strtoupper($method);
        return in_array($method, ['GET', 'HEAD'], true);
    }

    private function methodNotAllowed(string $contentType, bool $allowHeader): PublicDocument
    {
        $headers = $allowHeader ? ['Allow' => 'GET, HEAD'] : [];
        return new PublicDocument('', $contentType, 0, 405, false, 0, $headers);
    }

    private function routeLocale(): string
    {
        return (string) (\Tools::getValue('llms_locale') ?: \Tools::getValue('llms_lang'));
    }

    private function translatedError($module, Context $context, string $wording): string
    {
        $shop = $context->shop;
        $languageId = (int) \Configuration::get('PS_LANG_DEFAULT', null, (int) $shop->id_shop_group, (int) $shop->id);
        $locale = (new LanguageResolver())->normalizeLocale((string) (new \Language($languageId))->locale) ?: 'en-US';
        return (new TranslationResolver($module, $locale, $locale))->shop($wording);
    }

    private function generationFailure(string $contentType, int $languageId, \Throwable $exception): PublicDocument
    {
        $delay = $exception instanceof GenerationBusyException ? '1' : '5';
        return new PublicDocument('', $contentType, $languageId, 503, false, 0, ['Retry-After' => $delay]);
    }

    private function catalogUrl(UrlBuilder $urls, string $kind, int $languageId, int $categoryId, string $rewrite, int $cursor): string
    {
        if ($kind === 'category') return $urls->categoryMarkdownUrl($languageId, $categoryId, $rewrite);
        if ($kind === 'category-products') return $urls->categoryProductsUrl($languageId, $categoryId, $rewrite, $cursor);
        if ($kind === 'products') return $urls->catalogProductsUrl($languageId, $cursor);
        return $urls->catalogCategoriesUrl($languageId, $cursor);
    }
}
