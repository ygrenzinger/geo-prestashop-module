<?php

namespace LlmsCatalog\Service;

/** Builds deterministic RFC 8288 Link fields for public catalog representations. */
final class LinkHeaderBuilder
{
    public function index(UrlBuilder $urls, int $languageId): array
    {
        $canonical = $urls->indexUrl($languageId);
        $links = ['<' . $canonical . '>; rel="canonical"'];
        $alternates = $urls->localeAlternates();
        foreach ($alternates as $locale => $id) {
            $links[] = '<' . $urls->indexUrl($id) . '>; rel="alternate"; hreflang="' . $locale . '"; type="text/plain"';
        }
        if (isset($alternates[$urls->canonicalLocale($urls->defaultLanguageId())])) {
            $links[] = '<' . $urls->indexUrl($urls->defaultLanguageId()) . '>; rel="alternate"; hreflang="x-default"; type="text/plain"';
        }
        return ['Link' => $links, 'Content-Location' => $canonical];
    }

    public function product(UrlBuilder $urls, int $productId, string $rewrite, int $languageId): array
    {
        $canonical = $urls->productMarkdownUrl($productId, $rewrite, $languageId);
        $links = ['<' . $canonical . '>; rel="canonical"; type="text/markdown"'];
        $links[] = '<' . $urls->productHtmlUrl($productId, $rewrite, $languageId) . '>; rel="alternate"; type="text/html"';
        $links[] = '<' . $urls->indexUrl($languageId) . '>; rel="describedby"; type="text/plain"';
        $alternates = $urls->localeAlternates();
        $productByLanguage = [];
        foreach ($alternates as $locale => $id) {
            $product = $urls->productData($productId, $id);
            if ($product === null) continue;
            $rewrite = \Tools::link_rewrite((string) $product['link_rewrite']);
            if ($rewrite === '' || !\Validate::isLinkRewrite($rewrite)) continue;
            $productByLanguage[$id] = $rewrite;
            $links[] = '<' . $urls->productMarkdownUrl($productId, $rewrite, $id) . '>; rel="alternate"; hreflang="' . $locale . '"; type="text/markdown"';
        }
        $defaultLocale = $urls->canonicalLocale($urls->defaultLanguageId());
        $defaultId = $urls->defaultLanguageId();
        if (isset($alternates[$defaultLocale], $productByLanguage[$defaultId])) {
            $links[] = '<' . $urls->productMarkdownUrl($productId, $productByLanguage[$defaultId], $defaultId) . '>; rel="alternate"; hreflang="x-default"; type="text/markdown"';
        }
        return ['Link' => $links, 'Content-Location' => $canonical];
    }
}
