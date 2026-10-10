<?php

namespace LlmsCatalog\Service;

use LlmsCatalog\Audit\StructuredDataInspector;

/** Pure, fail-closed HTML mutation. It never performs network requests. */
final class StructuredDataAugmenter
{
    /** Adds missing offer fields to one canonical Product or supplements Microdata with JSON-LD. */
    public function augmentProduct(string $html, array $candidate, bool $enabled): string
    {
        if (!$enabled || strlen($html) > 2 * 1024 * 1024 || ($candidate['@type'] ?? null) !== 'Product') return $html;
        $canonical = $candidate['url'] ?? null;
        if (!is_string($canonical) || !$this->canonicalPageAllows($html, $canonical)) return $html;
        $inspection = (new StructuredDataInspector())->inspect($html);
        if (!$inspection['parseable']) return $html;
        $products = $inspection['products'];
        $matched = array_values(array_filter($products, fn (array $entity): bool => $this->matchesCanonical($entity, $canonical)));
        if (!$matched && count($products) === 1) $matched = $products;

        if (count($matched) === 1 && ($matched[0]['_format'] ?? '') === 'jsonld') {
            return $this->patchJsonLdProduct($html, $candidate, $canonical) ?? $html;
        }
        $desiredType=(string)($candidate['offers']['@type']??'Offer');
        if ($matched && $this->hasCompleteOffer($matched,$desiredType==='AggregateOffer')) return $html;
        if ($matched && $this->hasConflictingOffer($matched, $candidate['offers'] ?? [])) return $html;
        if ($matched && count($matched) > 1) return $html;

        // Existing Microdata remains owned by its template; the stable @id and canonical URL
        // let consumers join this complete JSON-LD node with the page's product identity.
        return $this->appendProductJsonLd($html, $candidate);
    }

    public function augment(string $html, array $candidate, string $type, bool $enabled): string
    {
        if (!$enabled || strlen($html) > 2 * 1024 * 1024
            || !in_array($type, ['Product', 'Organization', 'BreadcrumbList'], true)) {
            return $html;
        }

        if ($type === 'Organization') {
            return $this->augmentOrganization($html, $candidate);
        }

        $inspector = new StructuredDataInspector();
        $inspection = $inspector->inspect($html);
        if (!$inspection['parseable']) {
            return $html;
        }

        $conflicts = [
            'Product' => ['Product', 'ProductGroup'],
            'Organization' => ['Organization', 'OnlineStore', 'OnlineBusiness', 'LocalBusiness'],
            'BreadcrumbList' => ['BreadcrumbList'],
        ];
        if ($inspector->hasType($inspection['entities'], $conflicts[$type])
            || ($candidate['@type'] ?? null) !== $type) {
            return $html;
        }

        $dom = new \DOMDocument();
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return $html;
            }

            $xpath = new \DOMXPath($dom);
            $canonicalLinks = $xpath->query(
                '//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " "), " canonical ")]'
            );
            if (!$canonicalLinks || $canonicalLinks->length !== 1) {
                return $html;
            }

            $canonicalUrl = $canonicalLinks->item(0)->getAttribute('href');
            $lastItemIndex = array_key_last($candidate['itemListElement'] ?? []);
            $target = $candidate['url']
                ?? $candidate['offers']['url']
                ?? ($candidate['itemListElement'][$lastItemIndex]['item'] ?? null);
            if (!is_string($target)
                || $target !== $canonicalUrl
                || strtolower((string) parse_url($target, PHP_URL_SCHEME)) !== 'https') {
                return $html;
            }

            $noindexMeta = $xpath->query(
                '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="googlebot"]'
            );
            foreach ($noindexMeta ?: [] as $meta) {
                if (preg_match('/(?:^|[,\\s])noindex(?:$|[,\\s])/i', $meta->getAttribute('content'))) {
                    return $html;
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        try {
            $json = json_encode(
                $candidate,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
        } catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.structured-data.encode', 0, $exception);
            return $html;
        }

        if (strlen($json) > 16384
            || !preg_match('/<head(?:\s[^>]*)?>/i', $html)
            || !preg_match('/<\/head\s*>/i', $html)) {
            return $html;
        }

        $script = '<script type="application/ld+json">' . $json . '</script>';
        return preg_replace('/<\/head\s*>/i', $script . '</head>', $html, 1) ?? $html;
    }

    private function canonicalPageAllows(string $html, string $canonical): bool
    {
        $dom = new \DOMDocument();
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return false;
            $xpath = new \DOMXPath($dom);
            $links = $xpath->query('//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " "), " canonical ")]');
            if (!$links || $links->length !== 1 || $links->item(0)->getAttribute('href') !== $canonical) return false;
            $noindex = $xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="googlebot"]');
            foreach ($noindex ?: [] as $meta) if (preg_match('/(?:^|[,\\s])noindex(?:$|[,\\s])/i', $meta->getAttribute('content'))) return false;
            return true;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previousErrorMode); }
    }

    private function matchesCanonical(array $entity, string $canonical): bool
    {
        foreach (['url','@id','itemid'] as $field) {
            $value=$entity[$field]??null;
            if (is_array($value)) $value=$value['@id']??null;
            if (is_string($value) && ($value===$canonical || str_starts_with($value,$canonical.'#'))) return true;
        }
        return false;
    }

    private function hasConflictingOffer(array $products, array $candidateOffer): bool
    {
        foreach ($products as $product) {
            $offers=$product['offers']??[];
            if (is_array($offers) && !array_is_list($offers) && (isset($offers['@type']) || isset($offers['price']) || isset($offers['lowPrice']))) $offers=[$offers];
            if (!is_array($offers)) continue;
            foreach ($offers as $offer) {
                if (!is_array($offer)) continue;
                $candidateType=(string)($candidateOffer['@type']??'Offer');
                $offerType=$this->isOfferType($offer['@type']??null,'AggregateOffer')?'AggregateOffer':'Offer';
                if ($candidateType!==$offerType) continue;
                foreach (['price','lowPrice','highPrice','priceCurrency','availability'] as $field) {
                    if (isset($offer[$field]) && isset($candidateOffer[$field]) && !$this->sameOfferValue($field,$offer[$field],$candidateOffer[$field])) return true;
                }
            }
        }
        return false;
    }

    private function sameOfferValue(string $field,mixed $existing,mixed $candidate): bool
    {
        if (is_array($existing)) $existing=$existing['@id']??$existing['@value']??$existing['@type']??null;
        if (is_array($candidate)) $candidate=$candidate['@id']??$candidate['@value']??$candidate['@type']??null;
        if (in_array($field,['price','lowPrice','highPrice'],true) && is_numeric($existing) && is_numeric($candidate)) return round((float)$existing,6)===round((float)$candidate,6);
        return (string)$existing===(string)$candidate;
    }

    private function hasCompleteOffer(array $products,bool $requireAggregate): bool
    {
        foreach ($products as $product) {
            $offers=$product['offers']??[];
            if (is_array($offers) && !array_is_list($offers) && (isset($offers['@type']) || isset($offers['price']) || isset($offers['lowPrice']))) $offers=[$offers];
            if (!is_array($offers)) continue;
            foreach ($offers as $offer) {
                if (!is_array($offer)) continue;
                $aggregate=$this->isOfferType($offer['@type']??null,'AggregateOffer');
                if ($requireAggregate && !$aggregate) continue;
                $priceFields=$aggregate?['lowPrice','highPrice']:['price'];
                $valid=true;
                foreach ($priceFields as $field) if (!is_numeric($offer[$field]??null)) $valid=false;
                if (!is_string($offer['priceCurrency']??null) || preg_match('/^[A-Z]{3}$/',$offer['priceCurrency'])!==1) $valid=false;
                $availability=$offer['availability']??null;
                if (is_array($availability)) $availability=$availability['@id']??$availability['@type']??null;
                if (!is_string($availability) || !preg_match('~^https://schema\\.org/(?:InStock|OutOfStock|BackOrder|Discontinued)$~',$availability)) $valid=false;
                if ($valid) return true;
            }
        }
        return false;
    }

    private function patchJsonLdProduct(string $html, array $candidate, string $canonical): ?string
    {
        $scripts=[];
        if (preg_match_all('/<script\\b([^>]*)>(.*?)<\\/script\\s*>/is',$html,$matches,PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!preg_match('/\\btype\\s*=\\s*(?:"application\\/ld\\+json"|\'application\\/ld\\+json\'|application\\/ld\\+json)/i',$match[1])) continue;
                try { $scripts[]=['whole'=>$match[0],'body'=>$match[2],'data'=>json_decode($match[2],true,64,JSON_THROW_ON_ERROR)]; }
                catch (\Throwable $exception) { return null; }
            }
        }
        $count=0;
        foreach ($scripts as $script) $count += $this->countMatchingProducts($script['data'],$canonical);
        $matchAnySingle=false;
        if ($count===0) {
            foreach ($scripts as $script) $count += $this->countProducts($script['data']);
            $matchAnySingle=$count===1;
        }
        if ($count!==1) return null;
        foreach ($scripts as $script) {
            $changed=false;
            if (!$this->patchProductTree($script['data'],$candidate,$canonical,$changed,$matchAnySingle)) continue;
            try { $json=json_encode($script['data'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); }
            catch (\Throwable $exception) { return null; }
            if (!$changed || strlen($json)>16384) return null;
            if (!preg_match('/^<script\\b[^>]*>/i',$script['whole'],$opening)) return null;
            $closingOffset=strripos($script['whole'],'</script');
            if ($closingOffset===false) return null;
            $replacement=$opening[0].$json.substr($script['whole'],$closingOffset);
            return str_replace($script['whole'],$replacement,$html);
        }
        return null;
    }

    private function countMatchingProducts(mixed $value,string $canonical): int
    {
        if (!is_array($value)) return 0;
        $count=0;
        if (!array_is_list($value) && $this->isProductType($value['@type']??null) && $this->matchesCanonical($value,$canonical)) $count++;
        foreach ($value as $child) if (is_array($child)) $count += $this->countMatchingProducts($child,$canonical);
        return $count;
    }

    private function countProducts(mixed $value): int
    {
        if (!is_array($value)) return 0;
        $count=!array_is_list($value)&&$this->isProductType($value['@type']??null)?1:0;
        foreach ($value as $child) if (is_array($child)) $count+=$this->countProducts($child);
        return $count;
    }

    private function patchProductTree(array &$value,array $candidate,string $canonical,bool &$changed,bool $matchAnySingle=false): bool
    {
        if (!array_is_list($value) && $this->isProductType($value['@type']??null) && ($this->matchesCanonical($value,$canonical)||$matchAnySingle)) {
            $offers=$value['offers']??null;
            $candidateOffer=$candidate['offers']??[];
            if (!is_array($candidateOffer) || $this->hasConflictingOffer([$value],$candidateOffer)) return false;
            if ($offers===null || $offers==='') {
                $value['offers']=$candidateOffer;
                $changed=true;
                return true;
            }
            if (!is_array($offers)) return false;
            $candidateIsAggregate=$this->isOfferType($candidateOffer['@type']??null,'AggregateOffer');
            if (array_is_list($offers)) {
                $aggregateIndexes=[];
                foreach ($offers as $index=>$offer) if (is_array($offer)&&$this->isOfferType($offer['@type']??null,'AggregateOffer')) $aggregateIndexes[]=$index;
                if ($candidateIsAggregate && !$aggregateIndexes) {
                    $offers[]=$candidateOffer;$value['offers']=$offers;$changed=true;return true;
                }
                if ($candidateIsAggregate) {
                    if (count($aggregateIndexes)!==1) return false;
                    $index=$aggregateIndexes[0];
                } else {
                    $offerIndexes=[];
                    foreach ($offers as $offerIndex=>$offer) if (is_array($offer)&&$this->isOfferType($offer['@type']??null,'Offer')) $offerIndexes[]=$offerIndex;
                    if ($aggregateIndexes || count($offerIndexes)!==1) return false;
                    $index=$offerIndexes[0];
                }
                $target=$offers[$index];
            } else {
                if (!isset($offers['@type'])) return false;
                $existingIsAggregate=$this->isOfferType($offers['@type'],'AggregateOffer');
                if ($candidateIsAggregate && !$existingIsAggregate) {
                    $value['offers']=[$offers,$candidateOffer];$changed=true;return true;
                }
                if (!$candidateIsAggregate && $existingIsAggregate) return false;
                $target=$offers;
                $index=null;
            }
            if (!$this->isOfferType($target['@type']??null,$candidateOffer['@type']??'Offer')) return false;
            foreach ($candidateOffer as $field=>$fieldValue) {
                if (!array_key_exists($field,$target) || $target[$field]===null || $target[$field]==='') {
                    $target[$field]=$fieldValue;$changed=true;
                }
            }
            if ($index===null) $value['offers']=$target; else {$offers[$index]=$target;$value['offers']=$offers;}
            return true;
        }
        foreach ($value as &$child) if (is_array($child) && $this->patchProductTree($child,$candidate,$canonical,$changed,$matchAnySingle)) { unset($child); return true; }
        unset($child);
        return false;
    }

    private function isProductType(mixed $types): bool
    {
        foreach ((array)$types as $type) { $parts=explode('/',(string)$type); if (in_array((string)end($parts),['Product','ProductGroup'],true)) return true; }
        return false;
    }

    private function isOfferType(mixed $types,mixed $expected): bool
    {
        $expectedTypes=(array)$expected;
        foreach ((array)$types as $type) { $parts=explode('/',(string)$type); if (in_array((string)end($parts),$expectedTypes,true)) return true; }
        return false;
    }

    private function appendProductJsonLd(string $html,array $candidate): string
    {
        try { $json=json_encode($candidate,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); }
        catch (\Throwable $exception) { return $html; }
        if (strlen($json)>16384 || !preg_match('/<head(?:\\s[^>]*)?>/i',$html) || !preg_match('/<\\/head\\s*>/i',$html)) return $html;
        $script='<script type="application/ld+json">'.$json.'</script>';
        return preg_replace('/<\\/head\\s*>/i',$script.'</head>',$html,1)??$html;
    }

    private function augmentOrganization(string $html, array $candidate): string
    {
        if (($candidate['@type'] ?? null) !== 'Organization'
            || !is_string($candidate['url'] ?? null)
            || !$this->canonicalPageAllows($html, $candidate['url'])) {
            return $html;
        }

        $inspection = (new StructuredDataInspector())->inspect($html);
        if (!$inspection['parseable']) {
            return $html;
        }

        $organizations = array_values(array_filter(
            $inspection['entities'],
            fn (array $entity): bool => $this->isOrganizationType($entity['@type'] ?? null)
        ));

        if (!$organizations) {
            return $this->appendJsonLdCandidate($html, $candidate);
        }

        $matched = array_values(array_filter(
            $organizations,
            fn (array $entity): bool => $this->matchesCanonical($entity, $candidate['url'])
        ));
        if (count($matched) !== 1) {
            return $html;
        }

        return $this->patchJsonLdOrganization($html, $candidate, $candidate['url']) ?? $html;
    }

    private function patchJsonLdOrganization(string $html, array $candidate, string $canonical): ?string
    {
        $scripts = $this->jsonLdScripts($html);
        if ($scripts === null) {
            return null;
        }

        $count = 0;
        foreach ($scripts as $script) {
            $count += $this->countMatchingOrganizations($script['data'], $canonical);
        }
        if ($count !== 1) {
            return null;
        }

        foreach ($scripts as $script) {
            $changed = false;
            if (!$this->patchOrganizationTree($script['data'], $candidate, $canonical, $changed)) {
                continue;
            }
            try {
                $json = json_encode(
                    $script['data'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                );
            } catch (\Throwable $exception) {
                return null;
            }
            if (!$changed || strlen($json) > 16384
                || !preg_match('/^<script\\b[^>]*>/i', $script['whole'], $opening)) {
                return null;
            }
            $closingOffset = strripos($script['whole'], '</script');
            if ($closingOffset === false) {
                return null;
            }
            $replacement = $opening[0] . $json . substr($script['whole'], $closingOffset);
            return str_replace($script['whole'], $replacement, $html);
        }

        return null;
    }

    private function patchOrganizationTree(array &$value, array $candidate, string $canonical, bool &$changed): bool
    {
        if (!array_is_list($value)
            && $this->isOrganizationType($value['@type'] ?? null)
            && $this->matchesCanonical($value, $canonical)) {
            if (is_array($candidate['contactPoint'] ?? null)) {
                $this->mergeContactPoint($value, $candidate['contactPoint'], $changed);
            }
            if (is_array($candidate['address'] ?? null)) {
                $this->mergePostalAddress($value, $candidate['address'], $changed);
            }

            return true;
        }

        foreach ($value as &$child) {
            if (is_array($child) && $this->patchOrganizationTree($child, $candidate, $canonical, $changed)) {
                unset($child);
                return true;
            }
        }
        unset($child);
        return false;
    }

    private function countMatchingOrganizations(mixed $value, string $canonical): int
    {
        if (!is_array($value)) {
            return 0;
        }
        $count = !array_is_list($value)
            && $this->isOrganizationType($value['@type'] ?? null)
            && $this->matchesCanonical($value, $canonical) ? 1 : 0;
        foreach ($value as $child) {
            if (is_array($child)) {
                $count += $this->countMatchingOrganizations($child, $canonical);
            }
        }

        return $count;
    }

    private function mergeContactPoint(array &$organization, array $candidate, bool &$changed): void
    {
        $existing = $organization['contactPoint'] ?? null;
        if ($existing === null || $existing === '') {
            $organization['contactPoint'] = $candidate;
            $changed = true;
            return;
        }

        if (is_string($existing) && trim($existing) !== '') {
            $organization['contactPoint'] = [$existing, $candidate];
            $changed = true;
            return;
        }
        if (!is_array($existing)) {
            return;
        }

        if (!array_is_list($existing)) {
            if (!$this->isContactPoint($existing)
                || $this->hasDifferentContactType($existing, $candidate)) {
                $organization['contactPoint'] = [$existing, $candidate];
                $changed = true;
                return;
            }
            $organization['contactPoint'] = $this->fillMissingFields($existing, $candidate, $changed);
            return;
        }

        $matchingIndexes = [];
        foreach ($existing as $index => $contactPoint) {
            if (is_array($contactPoint) && $this->isContactPoint($contactPoint)
                && !$this->hasDifferentContactType($contactPoint, $candidate)) {
                $matchingIndexes[] = $index;
            }
        }
        if (count($matchingIndexes) > 1) {
            return;
        }
        if (!$matchingIndexes) {
            $existing[] = $candidate;
            $changed = true;
        } else {
            $index = $matchingIndexes[0];
            $existing[$index] = $this->fillMissingFields($existing[$index], $candidate, $changed);
        }
        $organization['contactPoint'] = $existing;
    }

    private function mergePostalAddress(array &$organization, array $candidate, bool &$changed): void
    {
        $existing = $organization['address'] ?? null;
        if ($existing === null || $existing === '' || $existing === []) {
            $organization['address'] = $candidate;
            $changed = true;
            return;
        }

        if (is_string($existing) && trim($existing) !== '') {
            $organization['address'] = [$existing, $candidate];
            $changed = true;
            return;
        }
        if (!is_array($existing)) {
            return;
        }

        if (!array_is_list($existing)) {
            if (!$this->isPostalAddress($existing)) {
                return;
            }
            $organization['address'] = $this->fillMissingFields($existing, $candidate, $changed);
            return;
        }

        $matchingIndexes = [];
        foreach ($existing as $index => $address) {
            if (is_array($address) && $this->isPostalAddress($address)) {
                $matchingIndexes[] = $index;
            }
        }
        if (count($matchingIndexes) !== 1) {
            if (!$matchingIndexes) {
                $existing[] = $candidate;
                $organization['address'] = $existing;
                $changed = true;
            }
            return;
        }
        $index = $matchingIndexes[0];
        $existing[$index] = $this->fillMissingFields($existing[$index], $candidate, $changed);
        $organization['address'] = $existing;
    }

    private function fillMissingFields(array $target, array $candidate, bool &$changed): array
    {
        foreach ($candidate as $field => $value) {
            if (!array_key_exists($field, $target) || $target[$field] === null || $target[$field] === '') {
                $target[$field] = $value;
                $changed = true;
            }
        }

        return $target;
    }

    private function hasDifferentContactType(array $contactPoint, array $candidate): bool
    {
        $existing = trim((string) ($contactPoint['contactType'] ?? ''));
        $desired = trim((string) ($candidate['contactType'] ?? ''));

        return $existing !== '' && strcasecmp($existing, $desired) !== 0;
    }

    private function isOrganizationType(mixed $types): bool
    {
        return $this->isOneOfTypes($types, ['Organization', 'OnlineStore', 'OnlineBusiness', 'LocalBusiness']);
    }

    private function isContactPoint(array $value): bool
    {
        if (isset($value['@type'])) {
            return $this->isOneOfTypes($value['@type'], ['ContactPoint']);
        }

        return !$value || isset($value['contactType']) || isset($value['email']) || isset($value['telephone']);
    }

    private function isPostalAddress(array $value): bool
    {
        if (!isset($value['@type'])) {
            return !$value || isset($value['streetAddress']) || isset($value['postalCode'])
                || isset($value['addressLocality']) || isset($value['addressRegion'])
                || isset($value['addressCountry']);
        }

        return $this->isOneOfTypes($value['@type'], ['PostalAddress']);
    }

    private function isOneOfTypes(mixed $types, array $expected): bool
    {
        foreach ((array) $types as $type) {
            $parts = explode('/', (string) $type);
            if (in_array((string) end($parts), $expected, true)) {
                return true;
            }
        }

        return false;
    }

    private function jsonLdScripts(string $html): ?array
    {
        $scripts = [];
        if (!preg_match_all('/<script\\b([^>]*)>(.*?)<\\/script\\s*>/is', $html, $matches, PREG_SET_ORDER)) {
            return $scripts;
        }
        foreach ($matches as $match) {
            if (!preg_match('/\\btype\\s*=\\s*(?:"application\\/ld\\+json"|\'application\\/ld\\+json\'|application\\/ld\\+json)/i', $match[1])) {
                continue;
            }
            try {
                $scripts[] = [
                    'whole' => $match[0],
                    'data' => json_decode($match[2], true, 64, JSON_THROW_ON_ERROR),
                ];
            } catch (\Throwable $exception) {
                return null;
            }
        }

        return $scripts;
    }

    private function appendJsonLdCandidate(string $html, array $candidate): string
    {
        try {
            $json = json_encode(
                $candidate,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
        } catch (\Throwable $exception) {
            return $html;
        }
        if (strlen($json) > 16384 || !preg_match('/<head(?:\\s[^>]*)?>/i', $html)
            || !preg_match('/<\\/head\\s*>/i', $html)) {
            return $html;
        }

        return preg_replace('/<\\/head\\s*>/i', '<script type="application/ld+json">' . $json . '</script></head>', $html, 1) ?? $html;
    }
}
