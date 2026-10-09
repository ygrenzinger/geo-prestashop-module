<?php

use PHPUnit\Framework\TestCase;

final class TranslationCatalogTest extends TestCase
{
    private const SHOP = [
        'This index describes the public catalog of this shop. Product links point to concise Markdown representations.',
        'Categories', 'Public catalog category', 'Products', 'Public product information from %shop_name%.',
        'Canonical URL', 'Price', 'Availability', 'In stock', 'Out of stock', 'In stock.',
        'Currently out of stock.', 'Price: %price%.',
        'This index is truncated by the configured output limit. Use the linked product endpoints or the shop catalog for additional items.',
        'Some catalog content is shown in %fallback_locale% because it is unavailable in %requested_locale%.',
        'llms.txt is disabled', 'Product not found', 'Not found',
    ];

    private const ADMIN = [
        'LLMs.txt for PrestaShop', 'Publishes a localized, machine-readable catalog index for generative engines.',
        'Publish llms.txt', 'Maximum products', 'Maximum output bytes', 'Cache TTL in seconds',
        'Include categories', 'Include products', 'Include public prices', 'Include availability',
        'Publish discovery metadata on catalog pages', 'Title (%locale%)', 'Summary (%locale%)', 'Save',
        'LLMs.txt settings updated.', 'These settings apply to all shops.',
        'This module publishes deterministic public catalog information. It does not guarantee AI-search inclusion or ranking.',
    ];

    public function testRequiredSourcesAndPlaceholdersExistInReviewedDictionaries(): void
    {
        foreach ([
            [__DIR__ . '/../../llmscatalog/translations/en-US/ModulesLlmscatalogShop.en-US.xlf', self::SHOP],
            [__DIR__ . '/../../llmscatalog/translations/fr-FR/ModulesLlmscatalogShop.fr-FR.xlf', self::SHOP],
            [__DIR__ . '/../../llmscatalog/translations/en-US/ModulesLlmscatalogAdmin.en-US.xlf', self::ADMIN],
            [__DIR__ . '/../../llmscatalog/translations/fr-FR/ModulesLlmscatalogAdmin.fr-FR.xlf', self::ADMIN],
        ] as [$path, $required]) {
            $xml = simplexml_load_file($path);
            self::assertNotFalse($xml, $path);
            $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
            $units = $xml->xpath('//x:trans-unit') ?: [];
            $sources = [];
            foreach ($units as $unit) {
                $namespaced = $unit->children('urn:oasis:names:tc:xliff:document:1.2');
                $source = (string) $namespaced->source;
                $target = (string) $namespaced->target;
                self::assertNotSame('', trim($target), $path . ' has an empty target');
                self::assertSame($this->placeholders($source), $this->placeholders($target), $source);
                $sources[] = $source;
            }
            foreach ($required as $source) self::assertContains($source, $sources, $path . ' is missing ' . $source);
        }
    }

    private function placeholders(string $value): array
    {
        preg_match_all('/%[A-Za-z0-9_]+%/', $value, $matches);
        sort($matches[0]);
        return $matches[0];
    }
}
