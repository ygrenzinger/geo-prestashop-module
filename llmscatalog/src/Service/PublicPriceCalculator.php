<?php

namespace LlmsCatalog\Service;

use Context;
use LlmsCatalog\ValueObject\PublicPriceContext;
use Product;

class PublicPriceCalculator
{
    public function __construct(private Context $context, private AnonymousContextRunner $runner)
    {
    }

    public function calculate(int $productId, PublicPriceContext $priceContext, int $productAttributeId = 0): array
    {
        return $this->runner->run($this->context, $priceContext, function () use ($productId, $priceContext, $productAttributeId) {
            $specificPrice = null;
            $taxIncluded = $priceContext->get('tax_mode') === 'included';
            // Keep the sole full positional core pricing call isolated here (PS 9.0.2 through 9.1.x).
            $amount = Product::getPriceStatic(
                $productId, $taxIncluded, $productAttributeId > 0 ? $productAttributeId : null, (int) $priceContext->get('currency_precision'), null,
                false, true, 1, false, 0, 0, (int) $priceContext->get('address_id'), $specificPrice, true, true,
                $this->context, false, null
            );
            $currency = $this->context->currency;
            $formatted = (new PriceFormatter())->format((float) $amount, $priceContext);
            $promotionExpiry = null;
            if (is_array($specificPrice) && !empty($specificPrice['to']) && $specificPrice['to'] !== '0000-00-00 00:00:00') {
                $timestamp = strtotime((string) $specificPrice['to']);
                if ($timestamp !== false && $timestamp > time()) {
                    $promotionExpiry = $timestamp;
                }
            }
            // Include future starts and generated catalog-rule rows. This query
            // is intentionally conservative: an unrelated public boundary may
            // shorten freshness, but a relevant price transition cannot be missed.
            $boundary = \Db::getInstance()->getValue('SELECT MIN(boundary) FROM (SELECT CASE WHEN `from` > UTC_TIMESTAMP() THEN `from` ELSE `to` END AS boundary FROM `' . _DB_PREFIX_ . 'specific_price` WHERE (`from` > UTC_TIMESTAMP() OR `to` > UTC_TIMESTAMP()) AND (`id_product`=0 OR `id_product`=' . (int) $productId . ') AND (`id_shop`=0 OR `id_shop`=' . (int) $priceContext->get('shop_id') . ') AND (`id_currency`=0 OR `id_currency`=' . (int) $priceContext->get('currency_id') . ') AND (`id_country`=0 OR `id_country`=' . (int) $priceContext->get('country_id') . ') AND (`id_group`=0 OR `id_group`=' . (int) $priceContext->get('group_id') . ') AND `id_customer`=0 AND `id_cart`=0) transitions');
            if ($boundary) { $timestamp = strtotime((string) $boundary . ' UTC'); if ($timestamp !== false && $timestamp > time()) $promotionExpiry = $promotionExpiry === null ? $timestamp : min($promotionExpiry, $timestamp); }
            return ['amount' => (float) $amount, 'formatted' => $formatted,
                'currency' => (string) $priceContext->get('currency_iso'),
                'tax_mode' => (string) $priceContext->get('tax_mode'),
                'specific_price' => is_array($specificPrice) ? $specificPrice : null,
                'promotion_expiry' => $promotionExpiry];
        });
    }
}
