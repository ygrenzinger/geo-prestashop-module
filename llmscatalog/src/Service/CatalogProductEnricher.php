<?php

namespace LlmsCatalog\Service;

use Context;
use Product;
use LlmsCatalog\ValueObject\PublicPriceContext;

/** Adds anonymous public price and availability facts to catalog query rows. */
class CatalogProductEnricher
{
    public function __construct(private Context $context)
    {
    }

    public function enrich(array &$product, PublicPriceContext $priceContext, bool $detailed, int $langId): void
    {
        $product['id_product'] = (int) $product['id_product'];
        $product['show_price'] = (bool) $product['show_price'] && (bool) $priceContext->get('show_price');
        $this->attachPrice($product, $priceContext);
        $product['availability'] = $this->availability($product, $detailed, $langId);
    }

    private function attachPrice(array &$product, PublicPriceContext $priceContext): void
    {
        $product['price'] = null;
        $product['price_formatted'] = null;
        $product['currency'] = (string) $priceContext->get('currency_iso');
        if (!$product['show_price']) {
            return;
        }

        try {
            $result = (new PublicPriceCalculator($this->context, new AnonymousContextRunner()))
                ->calculate((int) $product['id_product'], $priceContext);
            $product['price'] = $result['amount'];
            $product['price_formatted'] = $result['formatted'];
            $product['tax_mode'] = $result['tax_mode'];
            $product['price_expiry'] = $result['promotion_expiry'];
        } catch (\Throwable $exception) {
            $product['price_degraded'] = true;
            SafeDiagnosticLogger::log('public.price.calculate', (int) $priceContext->get('shop_id'), $exception);
        }
    }

    private function availability(array $row, bool $detailed, int $langId): array
    {
        $shopId = (int) $this->context->shop->id;
        $product = new Product((int) $row['id_product'], false, $langId, $shopId);
        $mode = (bool) \Configuration::get('PS_CATALOG_MODE', null, (int) $this->context->shop->id_shop_group, $shopId);
        $result = (new AvailabilityProvider())->forProduct($product, $shopId, $mode, (bool) $row['show_price'], $detailed, $langId);

        return [
            'state' => $result->state,
            'schema_url' => $result->schemaUrl,
            'label' => (new MarkdownRenderer())->text($result->localizedLabel),
            'date' => $result->availabilityDate,
            'combination_id' => $result->selectedCombinationId,
            'other_variants_orderable' => $result->otherVariantsOrderable,
            'reason' => $result->reason,
        ];
    }
}
