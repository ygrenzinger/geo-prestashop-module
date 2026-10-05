<?php
namespace LlmsCatalog\Audit;

/** Parses JSON-LD without executing page scripts; malformed data blocks augmentation. */
final class StructuredDataInspector
{
    public function inspect(string $html): array
    {
        if (strlen($html) > 2 * 1024 * 1024) return ['parseable'=>false, 'entities'=>[], 'error'=>'document_limit'];
        $dom = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return ['parseable'=>false, 'entities'=>[], 'error'=>'html_parse'];
            $entities = [];
            foreach ($dom->getElementsByTagName('script') as $script) {
                if (strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') continue;
                try { $decoded = json_decode($script->textContent, true, 64, JSON_THROW_ON_ERROR); }
                catch (\Throwable $e) { return ['parseable'=>false, 'entities'=>[], 'error'=>'malformed_json_ld']; }
                $this->flatten($decoded, $entities);
            }
            return ['parseable'=>true, 'entities'=>$entities, 'error'=>null];
        } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    }
    private function flatten(mixed $value, array &$out): void
    {
        if (!is_array($value)) return;
        if (array_is_list($value)) { foreach ($value as $item) $this->flatten($item, $out); return; }
        if (isset($value['@graph']) && is_array($value['@graph'])) $this->flatten($value['@graph'], $out);
        if (isset($value['@type']) || isset($value['@id'])) $out[] = $value;
    }
    public function hasType(array $entities, array $types): bool
    {
        foreach ($entities as $entity) {
            $actual = (array) ($entity['@type'] ?? []);
            if (array_intersect($actual, $types)) return true;
        }
        return false;
    }
}
