<?php
namespace LlmsCatalog\Audit;

/** Checks public Product entities for a complete, parseable offer. */
final class ProductOfferAuditor
{
    public function audit(array $products,string $url,int $shopId,string $language,string $now,bool $hasVariants=false): array
    {
        if (!$products) return [$this->finding('PRODUCT_SCHEMA_MISSING',$url,$shopId,$language,null,'No Product or ProductGroup entity was found.','Product markup with a public offer',true,$now)];
        $matched=array_values(array_filter($products,fn(array $entity):bool=>$this->matchesUrl($entity,$url)));
        if (!$matched && count($products)===1) $matched=$products;
        foreach ($matched as $entity) if ($this->hasCompleteOffer($entity,$hasVariants)) return [];
        if (count($matched)!==1) return [$this->finding('PRODUCT_SCHEMA_AMBIGUOUS',$url,$shopId,$language,null,count($matched).' matching Product entities were found.','One unambiguous canonical Product entity',false,$now)];

        $offers=$matched[0]['offers']??[];
        if (is_array($offers) && !array_is_list($offers) && (isset($offers['@type']) || isset($offers['price']) || isset($offers['lowPrice']))) $offers=[$offers];
        if (!is_array($offers) || !$offers) return [$this->finding($hasVariants?'PRODUCT_VARIANT_RANGE_MISSING':'PRODUCT_OFFER_MISSING',$url,$shopId,$language,(string)($matched[0]['@id']??''),'The canonical Product entity has no Offer.',$hasVariants?'AggregateOffer with complete public variant price range':'Offer or AggregateOffer with public price data',true,$now)];

        if ($hasVariants && !$this->hasCompleteAggregateOffer($offers)) return [$this->finding('PRODUCT_VARIANT_RANGE_MISSING',$url,$shopId,$language,(string)($matched[0]['@id']??''),'Variants are present but no complete AggregateOffer was found.','AggregateOffer lowPrice, highPrice, priceCurrency and availability',true,$now)];

        $missing=[];
        foreach ($offers as $offer) {
            if (!is_array($offer)) { $missing[]='offer'; continue; }
            $aggregate=$this->hasType($offer['@type']??null,'AggregateOffer');
            $offerType=$aggregate||$this->hasType($offer['@type']??null,'Offer');
            if (!$offerType) $missing[]='offer type';
            foreach ($aggregate?['lowPrice','highPrice','priceCurrency','availability']:['price','priceCurrency','availability'] as $field) {
                $value=$offer[$field]??null;
                if ($field==='availability') {
                    if (!$this->validAvailability($value)) $missing[]=$field;
                } elseif ($field==='priceCurrency') {
                    if (!is_string($value) || preg_match('/^[A-Z]{3}$/',$value)!==1) $missing[]=$field;
                } elseif (!is_numeric($value) || (float)$value<0) $missing[]=$field;
            }
        }
        $missing=array_values(array_unique($missing));
        if (!$this->validAggregateRanges($offers)) $missing[]='price range';
        $missing=array_values(array_unique($missing));
        if (!$missing) return [];
        return [$this->finding('PRODUCT_OFFER_FIELDS_MISSING',$url,$shopId,$language,(string)($matched[0]['@id']??''),$missing,'Offer price, currency and availability; AggregateOffer low/high price, currency and availability',true,$now)];
    }

    private function validAggregateRanges(array $offers): bool
    {
        foreach ($offers as $offer) {
            if (!is_array($offer) || !$this->hasType($offer['@type']??null,'AggregateOffer')) continue;
            if (is_numeric($offer['lowPrice']??null) && is_numeric($offer['highPrice']??null)
                && (float)$offer['lowPrice']>=0 && (float)$offer['highPrice'] >= (float)$offer['lowPrice']) return true;
        }
        return true;
    }

    private function hasCompleteOffer(array $entity,bool $requireAggregate): bool
    {
        $offers=$entity['offers']??[];
        if (is_array($offers) && !array_is_list($offers) && (isset($offers['@type']) || isset($offers['price']) || isset($offers['lowPrice']))) $offers=[$offers];
        if (!is_array($offers)) return false;
        foreach ($offers as $offer) {
            if (!is_array($offer)) continue;
            $aggregate=$this->hasType($offer['@type']??null,'AggregateOffer');
            if (!$aggregate&&!$this->hasType($offer['@type']??null,'Offer')) continue;
            if ($requireAggregate && !$aggregate) continue;
            $fields=$aggregate?['lowPrice','highPrice','priceCurrency','availability']:['price','priceCurrency','availability'];
            $valid=true;
            foreach ($fields as $field) {
                $value=$offer[$field]??null;
                if ($field==='availability') $valid=$valid&&$this->validAvailability($value);
                elseif ($field==='priceCurrency') $valid=$valid&&is_string($value)&&preg_match('/^[A-Z]{3}$/',$value)===1;
                else $valid=$valid&&is_numeric($value)&&(float)$value>=0;
            }
            if ($aggregate && is_numeric($offer['highPrice']??null) && is_numeric($offer['lowPrice']??null)
                && (float)$offer['highPrice'] < (float)$offer['lowPrice']) $valid=false;
            if ($valid) return true;
        }
        return false;
    }

    private function hasCompleteAggregateOffer(array $offers): bool
    {
        foreach ($offers as $offer) {
            if (!is_array($offer) || !$this->hasType($offer['@type']??null,'AggregateOffer')) continue;
            $low=$offer['lowPrice']??null;$high=$offer['highPrice']??null;$currency=$offer['priceCurrency']??null;
            if (is_numeric($low)&&is_numeric($high)&&(float)$low>=0&&(float)$high>=(float)$low
                &&is_string($currency)&&preg_match('/^[A-Z]{3}$/',$currency)===1
                &&$this->validAvailability($offer['availability']??null)) return true;
        }
        return false;
    }

    private function matchesUrl(array $entity,string $url): bool
    {
        foreach (['url','@id','itemid'] as $field) {
            $value=$entity[$field]??null;
            if (is_array($value)) $value=$value['@id']??null;
            if (!is_string($value) || $value==='') continue;
            if ($value===$url || str_starts_with($value,$url.'#')) return true;
        }
        return false;
    }

    private function hasType(mixed $value,string $expected): bool
    {
        foreach ((array)$value as $type) { $parts=explode('/',(string)$type); if (end($parts)===$expected) return true; }
        return false;
    }

    private function validAvailability(mixed $value): bool
    {
        if (is_array($value)) $value=$value['@id']??$value['@type']??null;
        if (!is_string($value)) return false;
        return preg_match('~^https://schema\\.org/(?:InStock|OutOfStock|BackOrder|Discontinued)$~',$value)===1;
    }

    private function finding(string $code,string $url,int $shopId,string $language,mixed $entityId,mixed $observed,string $expected,bool $augmentable,string $now): AuditFinding
    {
        return new AuditFinding($code,'medium','structured-data',$shopId,$language,$url,$entityId,$observed,$expected,[],'Theme or SEO module','Add or complete the canonical product offer markup, then rerun the audit.','product-offer-v1',$augmentable,$now);
    }
}
