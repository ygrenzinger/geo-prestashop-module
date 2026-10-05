<?php

namespace LlmsCatalog\Service;

use Context;
use LlmsCatalog\ValueObject\PublicPriceContext;
use Tools;

final class PriceFormatter
{
    private ?\Closure $formatter;

    public function __construct(?callable $formatter = null)
    {
        $this->formatter = $formatter === null ? null : \Closure::fromCallable($formatter);
    }

    public function format(float $amount, PublicPriceContext $context): string
    {
        if ($this->formatter !== null) {
            return (string) ($this->formatter)($amount, (string) $context->get('locale'), (string) $context->get('currency_iso'));
        }
        $request = Context::getContext();
        $original = $request->language;
        try {
            $request->language = new \Language((int) $context->get('language_id'));
            return Tools::getContextLocale($request)->formatPrice($amount, (string) $context->get('currency_iso'));
        } finally {
            $request->language = $original;
        }
    }
}
