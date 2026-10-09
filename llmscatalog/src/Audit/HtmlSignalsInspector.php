<?php
namespace LlmsCatalog\Audit;

/** Deterministic checks over rendered HTML; emits evidence and never mutates input. */
final class HtmlSignalsInspector
{
    public function inspect(string $html, string $expectedCanonical, string $language): array
    {
        if (strlen($html) > 2 * 1024 * 1024) return ['parseable'=>false,'findings'=>[['code'=>'HTML_LIMIT','severity'=>'blocker']]];
        $dom = new \DOMDocument(); $prior = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return ['parseable'=>false,'findings'=>[['code'=>'HTML_PARSE','severity'=>'blocker']]];
            $xpath = new \DOMXPath($dom); $findings=[];
            $canonicals=$xpath->query('//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " "), " canonical ")]');
            if (!$canonicals || $canonicals->length !== 1) $findings[]=['code'=>'CANONICAL_COUNT','severity'=>'high','observed'=>$canonicals ? $canonicals->length : 0,'expected'=>'exactly one canonical'];
            elseif ($canonicals->item(0)->getAttribute('href') !== $expectedCanonical) $findings[]=['code'=>'CANONICAL_MISMATCH','severity'=>'high','observed'=>$canonicals->item(0)->getAttribute('href'),'expected'=>$expectedCanonical];
            $alternates=$xpath->query('//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " "), " alternate ") and @hreflang]');
            $locales=[]; foreach ($alternates ?: [] as $link) { $code=$link->getAttribute('hreflang'); if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $code)) $findings[]=['code'=>'HREFLANG_INVALID','severity'=>'medium','observed'=>$code]; $locales[$code]=$link->getAttribute('href'); }
            if ($alternates && $alternates->length > 0 && !isset($locales[$language])) $findings[]=['code'=>'HREFLANG_SELF_MISSING','severity'=>'medium','expected'=>$language];
            $images=$xpath->query('//img');$seenAlt=[];foreach ($images ?: [] as $img) { $alt=trim($img->getAttribute('alt'));$src=$img->getAttribute('src');if(!$img->hasAttribute('alt'))$findings[]=['code'=>'IMAGE_ALT_MISSING','severity'=>'medium','observed'=>$src,'expected'=>'alt attribute present; review whether informative, decorative, or functional'];elseif($alt==='')$findings[]=['code'=>'IMAGE_ALT_EMPTY_REVIEW','severity'=>'info','observed'=>$src,'expected'=>'Empty alt is appropriate only for decorative imagery'];else{if(preg_match('/(?:^|\/)[^\/]+\.(?:jpe?g|png|webp|gif|avif)$/i',$alt))$findings[]=['code'=>'IMAGE_ALT_FILENAME_REVIEW','severity'=>'info','observed'=>$alt,'expected'=>'Meaningful purpose-based alternative text'];$normalized=mb_strtolower(preg_replace('/\s+/u',' ',$alt));if(isset($seenAlt[$normalized]))$findings[]=['code'=>'IMAGE_ALT_DUPLICATE_REVIEW','severity'=>'info','observed'=>[$seenAlt[$normalized],$src],'expected'=>'Review repetitive alternatives in context'];else$seenAlt[$normalized]=$src;}}
            return ['parseable'=>true,'canonical_count'=>$canonicals ? $canonicals->length : 0,'hreflang'=>$locales,'image_count'=>$images ? $images->length : 0,'findings'=>$findings];
        } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    }
}
