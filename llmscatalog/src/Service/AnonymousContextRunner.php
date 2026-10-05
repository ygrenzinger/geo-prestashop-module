<?php

namespace LlmsCatalog\Service;

use Context;
use Currency;
use Customer;
use Language;
use Cart;
use LlmsCatalog\ValueObject\PublicPriceContext;

/** Temporarily scopes core pricing hooks to the canonical anonymous context. */
class AnonymousContextRunner
{
    public function run(Context $context, PublicPriceContext $priceContext, callable $callback)
    {
        $fields = ['customer', 'cart', 'currency', 'country', 'language'];
        $snapshot = [];
        foreach ($fields as $field) {
            $snapshot[$field] = $context->$field;
        }
        $taxMethod = \Product::$_taxCalculationMethod;
        try {
            $context->customer = new Customer(0);
            $context->cart = new Cart(0);
            $context->currency = new Currency((int) $priceContext->get('currency_id'));
            $context->country = new \Country((int) $priceContext->get('country_id'));
            $context->language = new Language((int) $priceContext->get('language_id'));
            \Product::$_taxCalculationMethod = (int) $priceContext->get('display_method');
            \Product::flushPriceCache();
            return $callback();
        } finally {
            foreach ($snapshot as $field => $value) {
                $context->$field = $value;
            }
            \Product::$_taxCalculationMethod = $taxMethod;
            \Product::flushPriceCache();
        }
    }
}
