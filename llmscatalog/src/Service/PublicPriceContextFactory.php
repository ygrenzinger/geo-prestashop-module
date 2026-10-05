<?php

namespace LlmsCatalog\Service;

use Configuration;
use Context;
use Currency;
use Country;
use Group;
use Language;
use LlmsCatalog\ValueObject\PublicPriceContext;
use Shop;

class PublicPriceContextFactory
{
    public function create(Context $context, int $languageId): PublicPriceContext
    {
        $shop = $context->shop;
        $shopId = (int) $shop->id;
        $shopGroupId = (int) $shop->id_shop_group;
        $currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, $shopGroupId, $shopId);
        $currency = new Currency($currencyId);
        $language = new Language($languageId);
        $address = new \Address((int) $shop->id_address);
        $countryId = (int) ($address->id_country ?? 0);
        if ($countryId <= 0) {
            $countryId = (int) Configuration::get('PS_COUNTRY_DEFAULT', null, $shopGroupId, $shopId);
        }
        $country = new Country($countryId);
        $groupId = (int) Configuration::get('PS_UNIDENTIFIED_GROUP', null, $shopGroupId, $shopId);
        $group = new Group($groupId);
        if (!\Validate::isLoadedObject($shop) || !\Validate::isLoadedObject($currency)
            || !\Validate::isLoadedObject($country) || !\Validate::isLoadedObject($language)
            || !\Validate::isLoadedObject($group) || !$language->active
            || !in_array($languageId, array_map(static fn ($row) => (int) $row['id_lang'], Language::getLanguages(true, $shopId)), true)
            || $currency->deleted || $country->deleted) {
            throw new \RuntimeException('Invalid public price context');
        }
        $postcode = $address->postcode ?? '';
        $stateId = (int) ($address->id_country ?? 0) === $countryId ? (int) ($address->id_state ?? 0) : 0;
        $taxEnabled = (bool) Configuration::get('PS_TAX', null, $shopGroupId, $shopId);
        $displayMethod = (int) $group->price_display_method;
        $taxMode = !$taxEnabled ? 'not_applied' : ($displayMethod === (int) PS_TAX_EXC ? 'excluded' : 'included');
        return new PublicPriceContext([
            'shop_id' => $shopId, 'shop_group_id' => $shopGroupId,
            'language_id' => $languageId, 'locale' => (new LanguageResolver())->normalizeLocale((string) $language->locale),
            'currency_id' => $currencyId, 'currency_iso' => (string) $currency->iso_code,
            'currency_precision' => (int) $currency->precision,
            'currency_conversion_rate' => (string) $currency->conversion_rate,
            'country_id' => $countryId, 'state_id' => $stateId,
            'address_id' => (int) ($shop->id_address ?? 0),
            'postcode' => strtoupper(preg_replace('/\s+/', '', (string) $postcode)),
            'group_id' => $groupId, 'display_method' => $displayMethod,
            'show_price' => (bool) ($group->show_prices ?? true),
            'group_reduction' => (float) ($group->reduction ?? 0),
            'tax_enabled' => $taxEnabled, 'tax_mode' => $taxMode,
            'quantity' => 1, 'customer_id' => 0, 'cart_id' => 0,
            'default_combination' => true, 'adapter_schema' => 1,
        ]);
    }
}
