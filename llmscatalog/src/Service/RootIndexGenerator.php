<?php

namespace LlmsCatalog\Service;

use Configuration;
use Context;
use Language;

/** Generates the shop-scoped locale directory without duplicating catalog content. */
final class RootIndexGenerator
{
    public function __construct(private Context $context, private Settings $settings, private CacheManager $cache)
    {
    }

    public function fingerprint(): string
    {
        $rows = Language::getLanguages(true, (int) $this->context->shop->id);
        $resolver = new LanguageResolver();
        $languages = [];
        foreach ($rows as $row) {
            $languages[] = [(int) $row['id_lang'], $resolver->canonicalLocaleForRow($row)];
        }
        usort($languages, static fn ($left, $right) => $left[0] <=> $right[0]);
        $base = (new UrlBuilder($this->context))->baseLink();
        $default = (int) Configuration::get('PS_LANG_DEFAULT', null, (int) $this->context->shop->id_shop_group, (int) $this->context->shop->id);
        return hash('sha256', json_encode([$languages, $default, $base], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function generate(): array
    {
        $urls = new UrlBuilder($this->context);
        $languages = $urls->localeAlternates();
        $fingerprint = $this->fingerprint();
        $key = $this->cache->key('root', $urls->defaultLanguageId(), $this->settings, 'root-directory', 0, $fingerprint);
        $cached = $this->cache->get($key, 'root', $urls->defaultLanguageId());
        if ($cached !== null) return [$cached['body'], $cached['expires_at'], $cached['generated_at']];

        $entries = [];
        foreach ($languages as $locale => $languageId) {
            $row = null;
            foreach (Language::getLanguages(true, (int) $this->context->shop->id) as $candidate) {
                if ((int) $candidate['id_lang'] === $languageId) { $row = $candidate; break; }
            }
            if ($row === null) continue;
            $label = trim(strip_tags((string) ($row['name'] ?? '')));
            if ($label === '') $label = $locale;
            $url = $urls->indexUrl($languageId);
            $entries[] = [
                'id' => $languageId,
                'locale' => $locale,
                'label' => $label,
                'url' => $url,
                'default' => $languageId === $urls->defaultLanguageId(),
            ];
        }
        $body = self::renderDirectory((string) $this->context->shop->name, $entries);
        $ttl = $this->settings->cacheTtl();
        $record = ['body' => $body, 'etag' => '"' . hash('sha256', $body) . '"', 'expires_at' => time() + $ttl];
        $this->cache->put($key, $record, $ttl, 'root', $urls->defaultLanguageId(), 0, $fingerprint);
        $stored = $this->cache->get($key, 'root', $urls->defaultLanguageId());
        return [$body, (int) ($stored['expires_at'] ?? $record['expires_at']), (int) ($stored['generated_at'] ?? time())];
    }

    /** Deterministic pure renderer; entries have already been scoped to one shop. */
    public static function renderDirectory(string $shopName, array $entries): string
    {
        usort($entries, static function ($left, $right) {
            if ((bool) $left['default'] !== (bool) $right['default']) return $left['default'] ? -1 : 1;
            $localeOrder = strcmp((string) $left['locale'], (string) $right['locale']);
            return $localeOrder !== 0 ? $localeOrder : ((int) $left['id'] <=> (int) $right['id']);
        });
        $renderer = new MarkdownRenderer();
        $shopName = $renderer->text($shopName) ?: 'Shop';
        $safeShopName = preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $shopName) ?: 'Shop';
        $lines = [
            '# ' . $safeShopName,
            '',
            '> Language-specific llms.txt catalog indexes for ' . $safeShopName . '.',
            '',
            'Select the index matching the language and locale of the requested catalog.',
            '',
            '## Languages',
            '',
        ];
        foreach ($entries as $entry) {
            $locale = preg_replace('/[^A-Za-z0-9-]/', '', (string) $entry['locale']);
            $lines[] = $renderer->link((string) $entry['label'], (string) $entry['url'], 'Catalog index in ' . $locale . '.');
        }
        return implode("\n", $lines) . "\n";
    }
}
