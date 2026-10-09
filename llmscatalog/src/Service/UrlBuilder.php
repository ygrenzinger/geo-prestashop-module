<?php

namespace LlmsCatalog\Service;

use Context;
use Language;
use Tools;
use Configuration;

class UrlBuilder
{
    private Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function languageIdFromCode(string $code): int
    {
        return (new LanguageResolver())->resolve($code, $this->context)?->languageId ?? 0;
    }

    public function indexUrl(int $langId): string
    {
        return $this->moduleUrl('catalogindex', $langId);
    }

    public function rootIndexUrl(): string
    {
        return rtrim($this->baseLink(), '/') . '/llms.txt';
    }

    public function catalogProductsUrl(int $langId, int $cursor = 0): string { return $this->catalogPath($langId, 'catalog/products/after/' . max(0, $cursor) . '.md'); }
    public function catalogCategoriesUrl(int $langId, int $cursor = 0): string { return $this->catalogPath($langId, 'catalog/categories/after/' . max(0, $cursor) . '.md'); }
    public function categoryMarkdownUrl(int $langId, int $id, string $rewrite): string { return $this->catalogPath($langId, 'category/' . (int)$id . '/' . rawurlencode(Tools::str2url($rewrite ?: 'category-' . $id)) . '.md'); }
    public function categoryProductsUrl(int $langId, int $id, string $rewrite, int $cursor = 0): string { return $this->catalogPath($langId, 'category/' . (int)$id . '/' . rawurlencode(Tools::str2url($rewrite ?: 'category-' . $id)) . '/products/after/' . max(0,$cursor) . '.md'); }
    private function catalogPath(int $langId, string $tail): string
    {
        $base=rtrim($this->baseLink(),'/'); $locale=$this->canonicalLocale($langId);
        if ((bool)Configuration::get('PS_REWRITING_SETTINGS',null,(int)$this->context->shop->id_shop_group,(int)$this->context->shop->id)) return $base.'/'.rawurlencode($locale).'/llms/'.$tail;
        $query=['fc'=>'module','module'=>'llmscatalog','controller'=>'catalog','llms_locale'=>$locale];
        if(preg_match('~^catalog/(products|categories)/after/(\d+)\.md$~',$tail,$m)){$query['catalog_kind']=$m[1];$query['cursor']=(int)$m[2];}
        elseif(preg_match('~^category/(\d+)/([^/]+)\.md$~',$tail,$m)){$query['catalog_kind']='category';$query['id_category']=(int)$m[1];$query['rewrite']=$m[2];}
        elseif(preg_match('~^category/(\d+)/([^/]+)/products/after/(\d+)\.md$~',$tail,$m)){$query['catalog_kind']='category-products';$query['id_category']=(int)$m[1];$query['rewrite']=$m[2];$query['cursor']=(int)$m[3];}
        return $base.'/index.php?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }

    public function baseLink(): string
    {
        $shopId = (int) $this->context->shop->id;
        $groupId = (int) $this->context->shop->id_shop_group;
        $ssl = (bool) Configuration::get('PS_SSL_ENABLED', null, $groupId, $shopId)
            || (bool) Configuration::get('PS_SSL_ENABLED_EVERYWHERE', null, $groupId, $shopId);
        return (string) $this->context->link->getBaseLink($shopId, $ssl);
    }

    public function productMarkdownUrl(int $idProduct, string $rewrite, int $langId): string
    {
        return $this->moduleUrl('product', $langId, $idProduct, $rewrite);
    }

    public function productHtmlUrl(int $idProduct, string $rewrite, int $langId): string
    {
        return $this->context->link->getProductLink(
            $idProduct,
            Tools::str2url($rewrite),
            null,
            null,
            $langId,
            (int) $this->context->shop->id
        );
    }

    public function productData(int $idProduct, int $langId): ?array
    {
        return (new CatalogRepository($this->context))->product($idProduct, $langId);
    }

    public function canonicalLocale(int $langId): string
    {
        $language = new Language($langId);
        return (new LanguageResolver())->canonicalLocaleForRow([
            'locale' => $language->locale, 'language_code' => $language->language_code, 'iso_code' => $language->iso_code,
        ]);
    }

    public function localeAlternates(): array
    {
        $candidates = [];
        foreach (Language::getLanguages(true, (int) $this->context->shop->id) as $row) {
            $locale = (new LanguageResolver())->canonicalLocaleForRow($row);
            if ($locale !== '') $candidates[$locale][] = (int) $row['id_lang'];
        }
        $result = [];
        foreach ($candidates as $locale => $ids) if (count($ids) === 1) $result[$locale] = $ids[0];
        $defaultLocale = $this->canonicalLocale($this->defaultLanguageId());
        uksort($result, static function ($left, $right) use ($defaultLocale) {
            if ($left === $defaultLocale) return -1;
            if ($right === $defaultLocale) return 1;
            return strcmp($left, $right);
        });
        return $result;
    }

    public function defaultLanguageId(): int
    {
        return (int) Configuration::get('PS_LANG_DEFAULT', null, (int) $this->context->shop->id_shop_group, (int) $this->context->shop->id);
    }

    public function redirectQuery(): string
    {
        $query = (new CanonicalRequestResolver())->preservedQuery($_GET);
        return $query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
    }

    public function requestedProductId(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $basePath = (string) parse_url($this->context->link->getBaseLink((int) $this->context->shop->id, true), PHP_URL_PATH);
        $basePath = '/' . trim($basePath, '/');
        if ($basePath !== '/' && strpos($path, $basePath) === 0) $path = substr($path, strlen($basePath));
        $segments = explode('/', trim(rawurldecode($path), '/'));
        $index = array_search('llms', $segments, true);
        if ($index !== false && ($segments[$index + 1] ?? '') === 'product') return (string) ($segments[$index + 2] ?? '');
        return (string) Tools::getValue('id_product');
    }

    public function isCanonicalRequest(string $locale, string $controller, int $idProduct = 0, string $rewrite = ''): bool
    {
        $shopId = (int) $this->context->shop->id;
        $friendly = (bool) Configuration::get('PS_REWRITING_SETTINGS', null, (int) $this->context->shop->id_shop_group, $shopId);
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (!$friendly) {
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            return isset($query['fc'], $query['module'], $query['controller'], $query['llms_locale'])
                && $query['fc'] === 'module' && $query['module'] === 'llmscatalog' && $query['controller'] === $controller
                && $query['llms_locale'] === $locale
                && ($controller !== 'product' || ((string) ($query['id_product'] ?? '') === (string) $idProduct && (string) ($query['rewrite'] ?? '') === $rewrite));
        }
        $rawPath = (string) parse_url($uri, PHP_URL_PATH);
        $basePath = (string) parse_url($this->context->link->getBaseLink((int) $this->context->shop->id, true), PHP_URL_PATH);
        $expected = '/' . $locale . '/llms' . ($controller === 'catalogindex' ? '.txt' : '/product/' . $idProduct . '/' . $rewrite . '.md');
        return (new CanonicalRequestResolver())->canonicalPath($rawPath, $basePath, $expected);
    }

    private function moduleUrl(string $controller, int $langId, int $idProduct = 0, string $rewrite = ''): string
    {
        $locale = $this->canonicalLocale($langId);
        if ($locale === '') throw new \RuntimeException('Language has no canonical full locale');
        $shopId = (int) $this->context->shop->id;
        $base = rtrim($this->baseLink(), '/');
        $friendly = (bool) \Configuration::get('PS_REWRITING_SETTINGS', null, (int) $this->context->shop->id_shop_group, $shopId);
        if ($friendly) {
            $route = $controller === 'catalogindex' ? 'module-llmscatalog-index' : 'module-llmscatalog-product';
            $params = ['llms_lang' => $locale];
            if ($controller === 'product') {
                $params['id_product'] = $idProduct;
                $params['rewrite'] = rawurlencode(Tools::str2url($rewrite));
            }
            $path = \Dispatcher::getInstance()->createUrl($route, $langId, $params, false, '', $shopId);
            return $base . '/' . ltrim($path, '/');
        }
        $route = $controller === 'catalogindex' ? 'module-llmscatalog-index' : 'module-llmscatalog-product';
        $params = ['llms_lang' => $locale];
        if ($controller === 'product') {
            $params['id_product'] = $idProduct;
            $params['rewrite'] = Tools::str2url($rewrite);
        }
        // Ask Dispatcher for its native query-form controller route, then
        // replace its ISO language identity with the module-owned full locale.
        $dispatcherUrl = \Dispatcher::getInstance()->createUrl($route, $langId, $params, false, '', $shopId);
        parse_str((string) parse_url($dispatcherUrl, PHP_URL_QUERY), $dispatcherQuery);
        $query = [
            'fc' => (string) ($dispatcherQuery['fc'] ?? 'module'),
            'module' => (string) ($dispatcherQuery['module'] ?? 'llmscatalog'),
            'controller' => (string) ($dispatcherQuery['controller'] ?? $controller),
            'llms_locale' => $locale,
        ];
        if ($controller === 'product') {
            $query['id_product'] = $idProduct;
            $query['rewrite'] = Tools::str2url($rewrite);
        }
        return $base . '/index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
