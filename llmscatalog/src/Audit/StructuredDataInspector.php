<?php
namespace LlmsCatalog\Audit;

/** Parses JSON-LD and Microdata without executing page scripts. */
final class StructuredDataInspector
{
    public function inspect(string $html): array
    {
        if (strlen($html) > 2 * 1024 * 1024) return ['parseable'=>false, 'entities'=>[], 'products'=>[], 'error'=>'document_limit'];
        $dom = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return ['parseable'=>false, 'entities'=>[], 'products'=>[], 'error'=>'html_parse'];
            $entities = [];
            foreach ($dom->getElementsByTagName('script') as $script) {
                if (strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') continue;
                try { $decoded = json_decode($script->textContent, true, 64, JSON_THROW_ON_ERROR); }
                catch (\Throwable $e) { return ['parseable'=>false, 'entities'=>[], 'products'=>[], 'error'=>'malformed_json_ld']; }
                $this->flatten($decoded, $entities);
            }

            $products = [];
            foreach ($entities as $entity) {
                if ($this->hasEntityType($entity, ['Product', 'ProductGroup'])) {
                    $entity['_format'] = 'jsonld';
                    $products[] = $entity;
                }
            }
            foreach ($dom->getElementsByTagName('*') as $node) {
                if (!$node->hasAttribute('itemscope') || !$this->isType($node->getAttribute('itemtype'), ['Product', 'ProductGroup'])) continue;
                $products[] = $this->microdataProduct($node);
            }
            return ['parseable'=>true, 'entities'=>array_merge($entities, $products), 'products'=>$products, 'error'=>null];
        } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    }

    private function microdataProduct(\DOMElement $product): array
    {
        $offers = [];
        foreach ($product->getElementsByTagName('*') as $node) {
            if (!$node->hasAttribute('itemscope') || !$this->hasProperty($node->getAttribute('itemprop'), 'offers')
                || !$this->isType($node->getAttribute('itemtype'), ['Offer', 'AggregateOffer'])) continue;
            $aggregate = $this->isType($node->getAttribute('itemtype'), ['AggregateOffer']);
            $offers[] = [
                '@type' => $aggregate ? 'AggregateOffer' : 'Offer',
                'price' => $aggregate ? null : $this->microdataProperty($node, 'price'),
                'lowPrice' => $aggregate ? $this->microdataProperty($node, 'lowPrice') : null,
                'highPrice' => $aggregate ? $this->microdataProperty($node, 'highPrice') : null,
                'priceCurrency' => $this->microdataProperty($node, 'priceCurrency'),
                'availability' => $this->microdataProperty($node, 'availability'),
            ];
        }
        return [
            '@type' => $this->types($product->getAttribute('itemtype')),
            '@id' => $product->getAttribute('itemid'),
            'url' => $this->microdataProperty($product, 'url'),
            'offers' => $offers,
            '_format' => 'microdata',
        ];
    }

    private function microdataProperty(\DOMElement $scope, string $property): ?string
    {
        foreach ($scope->getElementsByTagName('*') as $node) {
            if (!$this->hasProperty($node->getAttribute('itemprop'), $property)) continue;
            foreach (['content', 'href', 'src', 'value'] as $attribute) {
                if ($node->hasAttribute($attribute)) return trim($node->getAttribute($attribute));
            }
            $value = trim((string) $node->textContent);
            if ($value !== '') return $value;
        }
        return null;
    }

    private function hasProperty(string $properties, string $property): bool
    {
        return in_array(strtolower($property), array_map('strtolower',preg_split('/\s+/', trim($properties)) ?: []), true);
    }

    private function isType(string $types, array $expected): bool
    {
        foreach ($this->types($types) as $type) if (in_array($type, $expected, true)) return true;
        return false;
    }

    private function types(string $types): array
    {
        return array_values(array_filter(array_map(static function (string $type): string {
            $parts = explode('/', trim($type));
            return (string) end($parts);
        }, preg_split('/\s+/', trim($types)) ?: [])));
    }

    private function flatten(mixed $value, array &$out): void
    {
        if (!is_array($value)) return;
        if (array_is_list($value)) { foreach ($value as $item) $this->flatten($item, $out); return; }
        if (isset($value['@graph']) && is_array($value['@graph'])) $this->flatten($value['@graph'], $out);
        if (isset($value['@type']) || isset($value['@id'])) $out[] = $value;
    }

    private function hasEntityType(array $entity, array $types): bool
    {
        foreach ((array) ($entity['@type'] ?? []) as $type) {
            $parts = explode('/', (string) $type);
            if (in_array((string) end($parts), $types, true)) return true;
        }
        return false;
    }

    public function hasType(array $entities, array $types): bool
    {
        foreach ($entities as $entity) {
            if ($this->hasEntityType($entity, $types)) return true;
        }
        return false;
    }
}
