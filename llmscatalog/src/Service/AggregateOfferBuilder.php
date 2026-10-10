<?php
namespace LlmsCatalog\Service;

/** Builds a complete public price and availability range from every shop variant. */
final class AggregateOfferBuilder
{
    public function build(string $url,array $variants,string $currency,int $precision,int $limit): ?array
    {
        $precision=max(0,min(6,$precision));
        if ($url==='' || preg_match('/^[A-Z]{3}$/',$currency)!==1 || !$variants || count($variants)>$limit) return null;
        $minimum=null;$maximum=null;$states=[];
        foreach ($variants as $variant) {
            $amount=$variant['amount']??null;$variantCurrency=$variant['currency']??null;$state=$variant['availability']??null;
            if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount<0 || $variantCurrency!==$currency
                || !in_array($state,['in_stock','backorder','out_of_stock','not_for_sale'],true)) return null;
            $amount=(float)$amount;
            $minimum=$minimum===null?$amount:min($minimum,$amount);
            $maximum=$maximum===null?$amount:max($maximum,$amount);
            $states[]=$state;
        }
        if ($minimum===null || $maximum===null) return null;
        $availability=in_array('in_stock',$states,true)?'https://schema.org/InStock'
            :(in_array('backorder',$states,true)?'https://schema.org/BackOrder':'https://schema.org/OutOfStock');
        return ['@type'=>'AggregateOffer','url'=>$url,
            'lowPrice'=>number_format(round($minimum,$precision),$precision,'.',''),
            'highPrice'=>number_format(round($maximum,$precision),$precision,'.',''),
            'priceCurrency'=>$currency,'availability'=>$availability,'offerCount'=>count($variants)];
    }
}
