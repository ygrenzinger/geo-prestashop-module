<?php

namespace LlmsCatalog\Service;

use Configuration;
use Context;
use Hook;

/** Fail-open, deterministic bridge for explicitly enabled provider modules. */
class ProductProviderRegistry
{
    private const ALLOWED = ['ratings', 'shipping', 'returns', 'warranty'];

    public function contributions(int $productId, int $languageId, array $enabledIds): array
    {
        $enabledIds = array_values(array_unique(array_filter(
            array_map('strval', $enabledIds),
            static fn ($id) => preg_match('/^[a-z0-9_.-]{1,64}$/i', $id) === 1
        )));
        sort($enabledIds, SORT_STRING);
        if (!$enabledIds) {
            return [];
        }

        $context = Context::getContext();
        $shopId = (int) $context->shop->id;
        $defaultLanguageId = (int) Configuration::get(
            'PS_LANG_DEFAULT',
            null,
            (int) $context->shop->id_shop_group,
            $shopId
        );

        try {
            $provided = Hook::exec(
                'actionLlmsCatalogProductContributions',
                [
                    'shop_id' => $shopId,
                    'id_lang' => $languageId,
                    'id_product' => $productId,
                    'enabled_provider_ids' => $enabledIds,
                ],
                null,
                true,
                true
            );
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.provider.contributions', $shopId, $exception);
            return [];
        }

        if (!is_array($provided)) {
            return [];
        }

        ksort($provided, SORT_STRING);
        $candidates = [];
        foreach ($provided as $moduleKey => $items) {
            if (!is_array($items)) {
                continue;
            }

            $module = is_numeric($moduleKey) ? \Module::getInstanceById((int) $moduleKey) : null;
            $moduleTechnicalId = is_object($module)
                ? strtolower((string) $module->name)
                : strtolower((string) $moduleKey);
            foreach ($items as $item) {
                $normalized = self::validateContribution(
                    $item,
                    $shopId,
                    $languageId,
                    $productId,
                    $enabledIds,
                    $defaultLanguageId
                );
                if ($normalized === null || $normalized['provider'] !== $moduleTechnicalId) {
                    continue;
                }
                $candidates[] = $normalized;
            }
        }

        usort($candidates, static function (array $first, array $second): int {
            return [$first['provider'], $first['section'], $first['url'], $first['text']]
                <=> [$second['provider'], $second['section'], $second['url'], $second['text']];
        });

        $accepted = [];
        $providerBytes = [];
        $providerSet = [];
        $totalBytes = 0;
        foreach ($candidates as $item) {
            $providerId = $item['provider'];
            $bytes = strlen($item['text']) + strlen($item['url']);
            if (!isset($providerSet[$providerId]) && count($providerSet) >= 10) {
                continue;
            }
            if (($providerBytes[$providerId] ?? 0) + $bytes > 16384
                || $totalBytes + $bytes > 16384 * 10) {
                continue;
            }

            $providerSet[$providerId] = true;
            $providerBytes[$providerId] = ($providerBytes[$providerId] ?? 0) + $bytes;
            $totalBytes += $bytes;
            $accepted[] = $item;
        }

        return $accepted;
    }

    public static function validateContribution(
        $item,
        int $shopId,
        int $languageId,
        int $productId,
        array $enabledIds,
        int $defaultLanguageId = 0
    ): ?array {
        if (!is_array($item)) {
            return null;
        }

        $allowed = [
            'provider_id', 'schema_version', 'section', 'shop_id', 'language_id',
            'product_id', 'public', 'text', 'url', 'modified_at',
        ];
        if (array_diff(array_keys($item), $allowed) || (string) ($item['schema_version'] ?? '') !== 'v1') {
            return null;
        }

        $providerId = (string) ($item['provider_id'] ?? '');
        $section = (string) ($item['section'] ?? '');
        if (!in_array($providerId, $enabledIds, true)
            || !in_array($section, self::ALLOWED, true)
            || ($item['public'] ?? false) !== true) {
            return null;
        }

        $sourceLanguage = (int) ($item['language_id'] ?? 0);
        $allowedLanguages = array_unique([$languageId, $defaultLanguageId ?: $languageId]);
        if ((int) ($item['shop_id'] ?? 0) !== $shopId
            || !in_array($sourceLanguage, $allowedLanguages, true)
            || (int) ($item['product_id'] ?? 0) !== $productId) {
            return null;
        }

        $text = trim(strip_tags((string) ($item['text'] ?? '')));
        $url = trim((string) ($item['url'] ?? ''));
        if ($text === '' || strlen($text) > 16000
            || ($url !== '' && !preg_match('#^https?://#i', $url))) {
            return null;
        }

        $modified = trim((string) ($item['modified_at'] ?? ''));
        if ($modified !== '' && strtotime($modified) === false) {
            return null;
        }

        return [
            'provider' => $providerId,
            'section' => $section,
            'text' => $text,
            'url' => $url,
            'modified_at' => $modified,
            'used_fallback' => $sourceLanguage !== $languageId,
        ];
    }
}
