<?php

namespace LlmsCatalog\Service;

use Context;

/** Serves the existing localized catalog document as a homepage representation. */
final class HomepageMarkdownNegotiator
{
    public function handle(array &$params, Context $context, $module): bool
    {
        try {
            if (!$this->isHomepage($params, $context) || !(new Settings())->isEnabled() || headers_sent()) return false;
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.homepage.classify', (int) ($context->shop->id ?? 0), $exception);
            return false;
        }

        self::varyAccept();
        if (!self::wantsMarkdown((string) ($_SERVER['HTTP_ACCEPT'] ?? ''))) return false;

        $languageId = (int) ($context->language->id ?? 0);
        if ($languageId < 1) return false;

        try {
            $body = $module->getGenerator()->generateIndex($languageId);
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.homepage.markdown', (int) ($context->shop->id ?? 0), $exception);
            return false;
        }
        if (trim($body) === '') return false;

        // The storefront has already rendered HTML. Replace its body and representation
        // headers together, without allowing a shared cache to reuse an HTML validator.
        $params['html'] = $body;
        header_remove('Content-Length');
        header_remove('ETag');
        header_remove('Last-Modified');
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        return true;
    }

    public static function wantsMarkdown(string $accept): bool
    {
        $preferences = [
            'text/markdown' => ['quality' => 0.0, 'specificity' => -1],
            'text/html' => ['quality' => 0.0, 'specificity' => -1],
        ];
        $explicitMarkdown = false;
        foreach (explode(',', strtolower($accept)) as $range) {
            $parts = array_map('trim', explode(';', $range));
            $type = array_shift($parts);
            if (!in_array($type, ['text/markdown', 'text/html', 'text/*', '*/*'], true)) continue;
            if ($type === 'text/markdown') $explicitMarkdown = true;
            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/^q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/', $parameter, $matches)) {
                    $quality = (float) $matches[1];
                    break;
                }
                if (preg_match('/^q\s*=/', $parameter)) {
                    $quality = 0.0;
                    break;
                }
            }
            $specificity = $type === '*/*' ? 0 : ($type === 'text/*' ? 1 : 2);
            foreach ($preferences as $representation => &$preference) {
                if ($type !== $representation && $specificity === 2) continue;
                if ($specificity > $preference['specificity']) {
                    $preference = ['quality' => $quality, 'specificity' => $specificity];
                } elseif ($specificity === $preference['specificity']) {
                    $preference['quality'] = max($preference['quality'], $quality);
                }
            }
            unset($preference);
        }
        return $explicitMarkdown
            && $preferences['text/markdown']['quality'] > 0
            && $preferences['text/markdown']['quality'] > $preferences['text/html']['quality'];
    }

    private function isHomepage(array $params, Context $context): bool
    {
        if (!isset($params['html']) || !is_string($params['html'])) return false;
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return false;
        if (http_response_code() !== 200) return false;
        $controller = $context->controller ?? null;
        if (!is_object($controller) || ($controller->php_self ?? '') !== 'index'
            || ($controller->controller_type ?? '') !== 'front' || !empty($controller->ajax)
            || !empty($controller->module) || !empty($controller->errors)) return false;

        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($requestPath === '') return false;
        $languageId = (int) ($context->language->id ?? 0);
        $homepagePath = (string) parse_url($context->link->getPageLink('index', true, $languageId), PHP_URL_PATH);
        $basePath = (string) parse_url($context->link->getBaseLink((int) $context->shop->id, true), PHP_URL_PATH);

        return self::samePath($requestPath, $homepagePath)
            || self::samePath($requestPath, $basePath)
            || self::samePath($requestPath, rtrim($basePath, '/') . '/index.php');
    }

    private static function samePath(string $left, string $right): bool
    {
        return '/' . trim($left, '/') === '/' . trim($right, '/');
    }

    private static function varyAccept(): void
    {
        $values = [];
        foreach (headers_list() as $header) {
            if (stripos($header, 'Vary:') !== 0) continue;
            foreach (explode(',', substr($header, 5)) as $value) {
                $value = trim($value);
                if ($value !== '') $values[strtolower($value)] = $value;
            }
        }
        if (!isset($values['accept'])) $values['accept'] = 'Accept';
        header('Vary: ' . implode(', ', array_values($values)));
    }
}
