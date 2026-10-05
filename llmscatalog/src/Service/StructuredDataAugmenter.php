<?php

namespace LlmsCatalog\Service;

use LlmsCatalog\Audit\StructuredDataInspector;

/** Pure, fail-closed HTML mutation. It never performs network requests. */
final class StructuredDataAugmenter
{
    public function augment(string $html, array $candidate, string $type, bool $enabled): string
    {
        if (!$enabled || strlen($html) > 2 * 1024 * 1024
            || !in_array($type, ['Product', 'Organization', 'BreadcrumbList'], true)) {
            return $html;
        }

        $inspector = new StructuredDataInspector();
        $inspection = $inspector->inspect($html);
        if (!$inspection['parseable']) {
            return $html;
        }

        $conflicts = [
            'Product' => ['Product', 'ProductGroup'],
            'Organization' => ['Organization', 'OnlineStore', 'OnlineBusiness', 'LocalBusiness'],
            'BreadcrumbList' => ['BreadcrumbList'],
        ];
        if ($inspector->hasType($inspection['entities'], $conflicts[$type])
            || ($candidate['@type'] ?? null) !== $type) {
            return $html;
        }

        $dom = new \DOMDocument();
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return $html;
            }

            $xpath = new \DOMXPath($dom);
            $canonicalLinks = $xpath->query(
                '//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " "), " canonical ")]'
            );
            if (!$canonicalLinks || $canonicalLinks->length !== 1) {
                return $html;
            }

            $canonicalUrl = $canonicalLinks->item(0)->getAttribute('href');
            $lastItemIndex = array_key_last($candidate['itemListElement'] ?? []);
            $target = $candidate['url']
                ?? $candidate['offers']['url']
                ?? ($candidate['itemListElement'][$lastItemIndex]['item'] ?? null);
            if (!is_string($target)
                || $target !== $canonicalUrl
                || strtolower((string) parse_url($target, PHP_URL_SCHEME)) !== 'https') {
                return $html;
            }

            $noindexMeta = $xpath->query(
                '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="googlebot"]'
            );
            foreach ($noindexMeta ?: [] as $meta) {
                if (preg_match('/(?:^|[,\\s])noindex(?:$|[,\\s])/i', $meta->getAttribute('content'))) {
                    return $html;
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        try {
            $json = json_encode(
                $candidate,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.structured-data.encode', 0, $exception);
            return $html;
        }

        if (strlen($json) > 16384
            || !preg_match('/<head(?:\s[^>]*)?>/i', $html)
            || !preg_match('/<\/head\s*>/i', $html)) {
            return $html;
        }

        $script = '<script type="application/ld+json">' . $json . '</script>';
        return preg_replace('/<\/head\s*>/i', $script . '</head>', $html, 1) ?? $html;
    }
}
