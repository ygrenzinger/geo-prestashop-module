<?php

namespace LlmsCatalog\Service;

use Context;

/** Shared source for HTML and RFC 8288 page-discovery relations. */
final class DiscoveryLinkBuilder
{
    public function pageLinks(Context $context, $controller): array
    {
        $settings = new Settings();
        if (!$settings->isEnabled() || !$settings->isDiscoveryMetadataEnabled() || !$this->isPublicCorePage($controller)) return [];
        $page = $this->pageName($controller);
        if (!in_array($page, ['index', 'category', 'product'], true)) return [];
        $languageId = (int) ($context->language->id ?? 0);
        $urls = new UrlBuilder($context);
        if ($languageId <= 0 || $urls->canonicalLocale($languageId) === '') return [];
        $links = [];
        if ($page === 'product') {
            $idProduct = (int) \Tools::getValue('id_product');
            if ($idProduct <= 0) return [];
            $product = (new CatalogRepository($context))->product($idProduct, $languageId);
            if ($product === null) return [];
            $rewrite = \Tools::link_rewrite((string) $product['link_rewrite']);
            if ($rewrite === '' || !\Validate::isLinkRewrite($rewrite)) return [];
            try {
                $module = \Module::getInstanceByName('llmscatalog');
                if (!$module || $module->getGenerator()->generateProduct($idProduct, $languageId) === null) return [];
            } catch (\Throwable $exception) {
                SafeDiagnosticLogger::log('public.discovery.product-link', (int) ($context->shop->id ?? 0), $exception);
                return [];
            }
            $links[] = ['rel' => 'alternate', 'type' => 'text/markdown', 'href' => $urls->productMarkdownUrl($idProduct, $rewrite, $languageId)];
        }
        $links[] = ['rel' => 'describedby', 'href' => $urls->indexUrl($languageId)];
        return $links;
    }

    public function html(array $links): string
    {
        $result = '';
        foreach ($links as $link) {
            $result .= '<link rel="' . $link['rel'] . '"';
            if (isset($link['type'])) $result .= ' type="' . $link['type'] . '"';
            $result .= ' href="' . htmlspecialchars($link['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        }
        return $result;
    }

    public function http(array $links): array
    {
        $fields = [];
        foreach ($links as $link) {
            $href = (string) $link['href'];
            if ($href === '' || preg_match('/[\x00-\x1F\x7F<>]/', $href)) continue;
            $field = '<' . $href . '>; rel="' . $link['rel'] . '"';
            if (isset($link['type'])) $field .= '; type="' . $link['type'] . '"';
            $fields[] = $field;
        }
        return array_values(array_unique($fields));
    }

    private function isPublicCorePage($controller): bool
    {
        if (!is_object($controller)) return false;
        if (isset($controller->module) && is_object($controller->module) && (string) ($controller->module->name ?? '') === 'llmscatalog') return false;
        if (isset($controller->errors) && is_array($controller->errors) && $controller->errors !== []) return false;
        if (isset($controller->getPageName) && is_callable([$controller, 'getPageName'])) {
            try {
                if (in_array((string) $controller->getPageName(), ['pagenotfound', 'authentication', 'order', 'search'], true)) {
                    return false;
                }
            } catch (\Throwable $exception) {
                $context = \Context::getContext();
                SafeDiagnosticLogger::log('public.discovery.page-classification', (int) ($context->shop->id ?? 0), $exception);
                return false;
            }
        }
        return true;
    }

    private function pageName($controller): string
    {
        foreach (['php_self', 'page_name'] as $property) {
            if (isset($controller->{$property}) && is_string($controller->{$property})) return strtolower($controller->{$property});
        }
        return strtolower((string) \Tools::getValue('controller'));
    }
}
