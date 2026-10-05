<?php

namespace LlmsCatalog\Service;


/** Fixed module-owned wording with explicit requested/default/source locale order. */
final class TranslationResolver
{
    public function __construct(private object $module, private string $requestedLocale, private string $defaultLocale)
    {
    }

    public function shop(string $message, array $parameters = []): string
    {
        return $this->resolve($message, $parameters, 'Modules.Llmscatalog.Shop');
    }

    public function admin(string $message, array $parameters = []): string
    {
        return $this->resolve($message, $parameters, 'Modules.Llmscatalog.Admin');
    }

    private function resolve(string $message, array $parameters, string $domain): string
    {
        foreach (array_unique([$this->requestedLocale, $this->defaultLocale, 'en-US']) as $locale) {
            $translated = $this->translateAtLocale($message, $parameters, $domain, $locale);
            if ($translated !== $message) {
                return $translated;
            }
            if ($locale === 'en-US') return strtr($message, $parameters);
        }
        return strtr($message, $parameters);
    }

    private function translateAtLocale(string $message, array $parameters, string $domain, string $locale): string
    {
        if ($domain === 'Modules.Llmscatalog.Shop') {
            switch ($message) {
                case 'This index describes the public catalog of this shop. Product links point to concise Markdown representations.': return $this->module->trans('This index describes the public catalog of this shop. Product links point to concise Markdown representations.', $parameters, $domain, $locale);
                case 'Categories': return $this->module->trans('Categories', $parameters, $domain, $locale);
                case 'Public catalog category': return $this->module->trans('Public catalog category', $parameters, $domain, $locale);
                case 'Products': return $this->module->trans('Products', $parameters, $domain, $locale);
                case 'Public product information from %shop_name%.': return $this->module->trans('Public product information from %shop_name%.', $parameters, $domain, $locale);
                case 'Canonical URL': return $this->module->trans('Canonical URL', $parameters, $domain, $locale);
                case 'Price': return $this->module->trans('Price', $parameters, $domain, $locale);
                case 'Availability': return $this->module->trans('Availability', $parameters, $domain, $locale);
                case 'In stock': return $this->module->trans('In stock', $parameters, $domain, $locale);
                case 'Out of stock': return $this->module->trans('Out of stock', $parameters, $domain, $locale);
                case 'In stock.': return $this->module->trans('In stock.', $parameters, $domain, $locale);
                case 'Currently out of stock.': return $this->module->trans('Currently out of stock.', $parameters, $domain, $locale);
                case 'Price: %price%.': return $this->module->trans('Price: %price%.', $parameters, $domain, $locale);
                case 'This index is truncated by the configured output limit. Use the linked product endpoints or the shop catalog for additional items.': return $this->module->trans('This index is truncated by the configured output limit. Use the linked product endpoints or the shop catalog for additional items.', $parameters, $domain, $locale);
                case 'Some catalog content is shown in %fallback_locale% because it is unavailable in %requested_locale%.': return $this->module->trans('Some catalog content is shown in %fallback_locale% because it is unavailable in %requested_locale%.', $parameters, $domain, $locale);
                case 'llms.txt is disabled': return $this->module->trans('llms.txt is disabled', $parameters, $domain, $locale);
                case 'Product not found': return $this->module->trans('Product not found', $parameters, $domain, $locale);
                case 'Not found': return $this->module->trans('Not found', $parameters, $domain, $locale);
                case 'Available on backorder': return $this->module->trans('Available on backorder', $parameters, $domain, $locale);
                case 'Not available for order': return $this->module->trans('Not available for order', $parameters, $domain, $locale);
                case 'This index describes the public catalog of this shop.': return $this->module->trans('This index describes the public catalog of this shop.', $parameters, $domain, $locale);
                case 'Public prices use %currency% and the shop default tax rules.': return $this->module->trans('Public prices use %currency% and the shop default tax rules.', $parameters, $domain, $locale);
                case 'Availability schema': return $this->module->trans('Availability schema', $parameters, $domain, $locale);
                case 'Availability label': return $this->module->trans('Availability label', $parameters, $domain, $locale);
                case 'Availability date': return $this->module->trans('Availability date', $parameters, $domain, $locale);
                case 'Other variants orderable': return $this->module->trans('Other variants orderable', $parameters, $domain, $locale);
                case 'Yes': return $this->module->trans('Yes', $parameters, $domain, $locale);
            }
        }
        if ($domain === 'Modules.Llmscatalog.Admin') {
            switch ($message) {
                case 'LLMs.txt for PrestaShop': return $this->module->trans('LLMs.txt for PrestaShop', $parameters, $domain, $locale);
                case 'Publishes a localized, machine-readable catalog index for generative engines.': return $this->module->trans('Publishes a localized, machine-readable catalog index for generative engines.', $parameters, $domain, $locale);
                case 'Publish llms.txt': return $this->module->trans('Publish llms.txt', $parameters, $domain, $locale);
                case 'Maximum products': return $this->module->trans('Maximum products', $parameters, $domain, $locale);
                case 'Maximum output bytes': return $this->module->trans('Maximum output bytes', $parameters, $domain, $locale);
                case 'Cache TTL in seconds': return $this->module->trans('Cache TTL in seconds', $parameters, $domain, $locale);
                case 'Include categories': return $this->module->trans('Include categories', $parameters, $domain, $locale);
                case 'Include products': return $this->module->trans('Include products', $parameters, $domain, $locale);
                case 'Include public prices': return $this->module->trans('Include public prices', $parameters, $domain, $locale);
                case 'Include availability': return $this->module->trans('Include availability', $parameters, $domain, $locale);
                case 'Inject HTML discovery links': return $this->module->trans('Inject HTML discovery links', $parameters, $domain, $locale);
                case 'Title (%locale%)': return $this->module->trans('Title (%locale%)', $parameters, $domain, $locale);
                case 'Summary (%locale%)': return $this->module->trans('Summary (%locale%)', $parameters, $domain, $locale);
                case 'Save': return $this->module->trans('Save', $parameters, $domain, $locale);
                case 'LLMs.txt settings updated.': return $this->module->trans('LLMs.txt settings updated.', $parameters, $domain, $locale);
                case 'These settings apply to all shops.': return $this->module->trans('These settings apply to all shops.', $parameters, $domain, $locale);
                case 'This module publishes deterministic public catalog information. It does not guarantee AI-search inclusion or ranking.': return $this->module->trans('This module publishes deterministic public catalog information. It does not guarantee AI-search inclusion or ranking.', $parameters, $domain, $locale);
                case 'Enter a valid number.': return $this->module->trans('Enter a valid number.', $parameters, $domain, $locale);
            }
        }
        if ($domain === 'Modules.Llmscatalog.Shop') return $this->module->trans($message, $parameters, $domain, $locale);
        return $message;
    }
}
