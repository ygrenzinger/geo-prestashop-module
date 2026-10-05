<?php

namespace LlmsCatalog\Service;

use Context;
use Language;
use LlmsCatalog\ValueObject\ResolvedLanguage;

final class LanguageResolver
{
    public function resolve(string $requested, Context $context): ?ResolvedLanguage
    {
        $shopId = (int) $context->shop->id;
        $languages = Language::getLanguages(true, $shopId);
        $row = $this->matchRequestedLanguage($languages, $requested);
        if ($row === null) return null;
        $defaultId = (int) \Configuration::get('PS_LANG_DEFAULT', null, (int) $context->shop->id_shop_group, $shopId);
        $default = null;
        foreach ($languages as $candidate) {
            if ((int) $candidate['id_lang'] === $defaultId) {
                $default = $candidate;
                break;
            }
        }
        $locale = $this->canonicalLocaleForRow($row);
        if ($locale === '') return null;
        $defaultLanguage = $default ?? new Language($defaultId);
        $defaultLocale = $default ? $this->canonicalLocaleForRow($default) : $this->canonicalLocaleForRow([
            'locale' => $defaultLanguage->locale, 'language_code' => $defaultLanguage->language_code, 'iso_code' => $defaultLanguage->iso_code,
        ]);
        return new ResolvedLanguage(
            $shopId,
            (int) $row['id_lang'],
            $locale,
            (string) $row['iso_code'],
            $defaultId,
            $defaultLocale,
            $requested !== $locale,
            $defaultId !== (int) $row['id_lang']
        );
    }

    /** Resolve every language signal in the raw route; nested aliases must agree. */
    public function resolveRequest(Context $context, string $routeLocale = ''): ?ResolvedLanguage
    {
        $rawPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!(new CanonicalRequestResolver())->validEncodedPath($rawPath)) return null;
        $basePath = (string) parse_url($context->link->getBaseLink((int) $context->shop->id, true), PHP_URL_PATH);
        $basePath = '/' . trim($basePath, '/');
        if ($basePath !== '/' && strpos($rawPath, $basePath) === 0) $rawPath = substr($rawPath, strlen($basePath));
        $path = rawurldecode($rawPath);
        $segments = explode('/', trim($path, '/'));
        $llmsAt = array_search('llms.txt', $segments, true);
        if ($llmsAt === false) $llmsAt = array_search('llms', $segments, true);
        $signals = [];
        if ($llmsAt !== false) {
            for ($i = 0; $i < $llmsAt; $i++) if ($segments[$i] !== '') $signals[] = $segments[$i];
        }
        $routeLocale = $routeLocale !== '' ? $routeLocale : (string) (\Tools::getValue('llms_locale') ?: \Tools::getValue('llms_lang'));
        if ($signals === [] && $routeLocale !== '') $signals[] = $routeLocale;
        if ($signals === []) return null;
        $languages = Language::getLanguages(true, (int) $context->shop->id);
        $resolvedRows = [];
        foreach ($signals as $signal) {
            $row = $this->matchRequestedLanguage($languages, $signal);
            if ($row === null) return null;
            $resolvedRows[(int) $row['id_lang']] = $row;
        }
        if (count($resolvedRows) !== 1) return null;
        return $this->resolve($this->canonicalLocaleForRow(reset($resolvedRows)), $context);
    }

    /** Pure matching step, also used to test ambiguity independently of PrestaShop. */
    public function matchRequestedLanguage(array $languages, string $requested): ?array
    {
        $matches = [];
        foreach ($languages as $row) {
            $locale = $this->canonicalLocaleForRow($row);
            if ($locale === '') continue;
            $iso = (string) ($row['iso_code'] ?? '');
            if ($locale !== '' && strcasecmp($locale, $requested) === 0) {
                $matches[] = $row;
                continue;
            }
            $languageCode = $this->normalizeLocale((string) ($row['language_code'] ?? ''));
            if ($languageCode !== '' && strcasecmp($languageCode, $requested) === 0) {
                $matches[] = $row;
                continue;
            }
            if (preg_match('/^[A-Za-z]{2}$/', $requested) && strcasecmp($iso, $requested) === 0) {
                $matches[] = $row;
            }
        }
        $unique = [];
        foreach ($matches as $match) $unique[(int) $match['id_lang']] = $match;
        return count($unique) === 1 ? reset($unique) : null;
    }

    public function normalizeLocale(string $locale): string
    {
        if (strpos($locale, '-') === false || !preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $locale)) return '';
        $parts = explode('-', $locale);
        $parts[0] = strtolower($parts[0]);
        for ($i = 1; $i < count($parts); $i++) {
            if (strlen($parts[$i]) === 4 && ctype_alpha($parts[$i])) $parts[$i] = ucfirst(strtolower($parts[$i]));
            elseif ((strlen($parts[$i]) === 2 && ctype_alpha($parts[$i])) || (strlen($parts[$i]) === 3 && ctype_digit($parts[$i]))) $parts[$i] = strtoupper($parts[$i]);
            else $parts[$i] = strtolower($parts[$i]);
        }
        return implode('-', $parts);
    }

    /** Full locale where available; compatibility token for incomplete legacy language rows. */
    public function canonicalLocaleForRow(array $row): string
    {
        $locale = $this->normalizeLocale((string) ($row['locale'] ?? ''));
        if ($locale !== '') return $locale;
        $languageCode = $this->normalizeLocale((string) ($row['language_code'] ?? ''));
        if ($languageCode !== '') return $languageCode;
        $iso = strtolower((string) ($row['iso_code'] ?? ''));
        return preg_match('/^[a-z]{2}$/', $iso) ? $iso : '';
    }
}
