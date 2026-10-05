<?php

namespace LlmsCatalog\Service;

use Context;
use LlmsCatalog\ValueObject\LocalizedField;
use LlmsCatalog\ValueObject\ResolvedLanguage;
use Tools;

/** Renders the public product Markdown representation in basic or rich form. */
class ProductDocumentRenderer
{
    public function __construct(private Settings $settings, private MarkdownRenderer $markdown, private UrlBuilder $urls, private Context $context)
    {
    }

    public function renderBasic(array $product, int $idProduct, int $langId, TranslationResolver $t, ResolvedLanguage $resolved): string
    {
        $lines = [
            '# ' . $this->markdown->text((string) $product['name']),
            '',
            '> ' . $t->shop('Public product information from %shop_name%.', ['%shop_name%' => $this->markdown->text((string) $this->context->shop->name)]),
            '',
            '- ' . $t->shop('Canonical URL') . ': ' . $this->urls->productHtmlUrl($idProduct, (string) $product['link_rewrite'], $langId),
        ];

        $fallback = new FallbackTracker();
        $this->observeFallback($fallback, $product, $langId);
        if ($fallback->hasFallback()) {
            array_splice($lines, 4, 0, ['> ' . $fallback->disclosure($t, $resolved)]);
        }

        if ($this->markdown->text((string) $product['description_short']) !== '') {
            $lines[] = '';
            $lines[] = $this->markdown->text((string) $product['description_short']);
        }

        if ($this->settings->includesPrice() && $product['show_price'] && $product['price_formatted'] !== null) {
            $lines[] = '';
            $lines[] = '- ' . $t->shop('Price') . ': ' . $product['price_formatted'] . ' (' . $product['currency'] . ')';
        }

        if ($this->settings->includesAvailability()) {
            $availability = $product['availability'];
            $lines[] = '- ' . $t->shop('Availability') . ': ' . $this->stockTerm((string) $availability['state'], $t);
            $lines[] = '- ' . $t->shop('Availability schema') . ': ' . $availability['schema_url'];
            $lines[] = '- ' . $t->shop('Availability label') . ': ' . $this->stockTerm((string) $availability['state'], $t);
            if ($availability['date'] !== null) $lines[] = '- ' . $t->shop('Availability date') . ': ' . $availability['date'];
            if ($availability['other_variants_orderable']) $lines[] = '- ' . $t->shop('Other variants orderable') . ': ' . $t->shop('Yes');
        }

        $body = implode("\n", $lines) . "\n";
        return $body;
    }

    public function render(array $product, array $details, int $idProduct, int $langId, $t, ResolvedLanguage $resolved): string
    {
        $name = $this->markdown->safeText((string)$product['name']);
        $lines = ['# ' . $name, '', 'Document profile: llmscatalog/product-markdown/v1', '', '- ' . $t->shop('Canonical URL') . ': ' . $this->urls->productHtmlUrl($idProduct, (string)$product['link_rewrite'], $langId), '- ' . $t->shop('Product type') . ': ' . $t->shop(ucfirst((string)$details['type']))];
        $fallback = new FallbackTracker(); $this->observeFallback($fallback, $product, $langId);
        $fallback->observeConfiguredFallback(!empty($details['used_fallback']));
        if ($fallback->hasFallback()) $lines[] = '> ' . $fallback->disclosure($t, $resolved);
        $fallbackFields=$details['fallback_fields'] ?? [];
        foreach (['name_source_lang'=>'product name','description_short_source_lang'=>'short description','link_rewrite_source_lang'=>'product URL rewrite'] as $source=>$label) if ((int)($product[$source]??0)>0 && (int)$product[$source]!==$langId) $fallbackFields[]=$label;
        $fallbackFields=array_values(array_unique($fallbackFields));
        if ($fallbackFields) $lines[] = '- '.$t->shop('Fields using the shop default language').': '.$this->markdown->safeText(implode(', ', $fallbackFields)).' ('.$this->markdown->safeText($resolved->defaultLocale).')';
        $identity = [];
        foreach (['reference'=>'Reference','ean13'=>'EAN','upc'=>'UPC','isbn'=>'ISBN','mpn'=>'MPN','manufacturer'=>'Manufacturer'] as $key=>$label) if (trim((string)($details[$key] ?? '')) !== '') $identity[] = '- ' . $t->shop($label) . ': ' . $this->markdown->safeText($details[$key]);
        if (trim((string)($details['date_upd'] ?? '')) !== '') $identity[] = '- ' . $t->shop('Core product record modified') . ': ' . $this->markdown->safeText((string)$details['date_upd']);
        if (in_array((string)$details['condition'], ['new','used','refurbished'], true)) $identity[] = '- ' . $t->shop('Condition') . ': ' . $t->shop(ucfirst((string)$details['condition']));
        $lines = array_merge($lines, $identity);
        if (trim((string)$product['description_short']) !== '') $lines = array_merge($lines, ['', $this->markdown->htmlToMarkdown((string)$product['description_short'], 12000)]);
        if (trim((string)$details['description']) !== '') $lines = array_merge($lines, ['', '## '.$t->shop('Description'), '', $this->markdown->htmlToMarkdown((string)$details['description'], 98304)]);
        if ($this->settings->includesPrice() && !empty($product['show_price']) && !empty($product['price_formatted'])) $lines = array_merge($lines, ['', '## '.$t->shop('Offer'), '', '- '.$t->shop('Price').': ' . $product['price_formatted'] . ' ' . $this->markdown->safeText((string)$product['currency']), '- '.$t->shop('Tax context').': ' . $this->markdown->safeText((string)($product['tax_mode'] ?? 'shop default'))]);
        if ($this->settings->includesAvailability()) {
            $a=$product['availability']; $lines[]='- '.$t->shop('Availability').': '.$this->stockTerm((string)$a['state'],$t);
            if (!empty($a['date'])) $lines[]='- '.$t->shop('Available from').': '.$this->markdown->safeText((string)$a['date']);
        }
        if ($details['categories']) { $lines=array_merge($lines,['','## '.$t->shop('Categories')]); foreach ($details['categories'] as $category) { $url=$this->urls->categoryMarkdownUrl($langId,(int)$category['id'],(string)$category['rewrite']); $lines[]='- ['.$this->markdown->safeText($category['name']).']('.$url.')'; } }
        if ($details['features']) { $lines=array_merge($lines,['','## '.$t->shop('Specifications')]); foreach ($details['features'] as $feature) $lines[]='- '.$this->markdown->safeText($feature['name']).': '.$this->markdown->safeText($feature['value']); }
        if ($details['images']) { $lines=array_merge($lines,['','## '.$t->shop('Images')]); foreach ($details['images'] as $image) { $url=$this->contextImageUrl($details,$image,$product); $alt=$image['legend'] !== '' ? $image['legend'] : $name; $lines[]='- ['.$this->markdown->safeText($alt).']('.$url.')'; } }
        if ($details['variants']) { $lines=array_merge($lines,['','## '.$t->shop('Variants')]); foreach ($details['variants'] as $variant) { $label=implode(', ',array_map([$this->markdown,'safeText'],$variant['attributes'] ?? [])); $line='- '.$label; if (!empty($variant['reference'])) $line.=' ('.$t->shop('Reference').': '.$this->markdown->safeText($variant['reference']).')'; if ((int)$variant['id']===(int)($product['availability']['combination_id'] ?? 0)) $line.=' — '.$t->shop('Default offer'); $lines[]=$line; } }
        if ($details['pack']) { $lines=array_merge($lines,['','## '.$t->shop('Pack contents')]); foreach($details['pack'] as $item) $lines[]='- '.$this->markdown->safeText($item['name']).' × '.(int)$item['quantity']; }
        if ($details['custom']) { $lines=array_merge($lines,['','## '.$t->shop('Customization')]); foreach($details['custom'] as $field) $lines[]='- '.$this->markdown->safeText($field); }
        $omitted=[]; foreach ($details['omitted'] as $section=>$count) if ((int)$count>0) $omitted[]='- '.$t->shop(ucfirst($section)).' '.$t->shop('omitted').': '.(int)$count;
        if ($omitted) $lines=array_merge($lines,['','## '.$t->shop('Coverage')], $omitted);
        $providerItems = (new ProductProviderRegistry())->contributions($idProduct, $langId, $this->settings->enabledProductProviders());
        $providerHeadings = ['ratings'=>'Ratings','shipping'=>'Shipping','returns'=>'Returns','warranty'=>'Warranty'];
        foreach ($providerItems as $item) {
            if (!$this->settings->productSectionEnabled($item['section'])) continue;
            $heading=$t->shop($providerHeadings[$item['section']]);
            if (!in_array('## '.$heading,$lines,true)) $lines=array_merge($lines,['','## '.$heading]);
            $value=$this->markdown->safeText($item['text']);
            $providerUrl=str_replace([' ',')','('],['%20','%29','%28'],$item['url']);
            $lines[]=$item['url']!==''?'- ['.$value.']('.$providerUrl.')':'- '.$value;
            $lines[]='  - '.$t->shop('Source provider').': '.$this->markdown->safeText($item['provider']);
            if ($item['modified_at']!=='') $lines[]='  - '.$t->shop('Provider record modified').': '.$this->markdown->safeText($item['modified_at']);
            if (!empty($item['used_fallback'])) $lines[]='  - '.$t->shop('Provider content uses the shop default language').': '.$this->markdown->safeText($resolved->defaultLocale);
        }
        $body=implode("\n",$lines)."\n"; $limit=$this->settings->productLimit('bytes');
        if (strlen($body)>$limit) {
            $notice="\n\n> Some optional product details were omitted to fit the configured document size limit.\n";
            $body=$this->markdown->truncate($body,$limit,$this->markdown->safeText($t->shop('Some optional product details were omitted to fit the configured document size limit.')));
        }
        return $body;
    }

    private function contextImageUrl(array $details, array $image, array $product): string
    {
        $context=$this->context; $id=(int)$image['id'];
        $link=$context->link->getImageLink(Tools::link_rewrite((string)$product['link_rewrite']), (string)$id, 'large_default');
        if (strpos($link,'http://')===0 || strpos($link,'https://')===0) return $link;
        return rtrim($this->urls->baseLink(),'/').'/img/p/'.implode('/',str_split((string)$id)).'/'.$id.'-large_default.jpg';
    }

    private function observeFallback(FallbackTracker $tracker, array $row, int $requestedLanguageId): void
    {
        foreach (['name_source_lang' => 'name', 'description_short_source_lang' => 'description_short', 'link_rewrite_source_lang' => 'link_rewrite'] as $field => $valueField) {
            $tracker->observe(new LocalizedField((string) ($row[$valueField] ?? ''), (int) ($row[$field] ?? 0)), $requestedLanguageId);
        }
    }

    private function stockTerm(string $state, TranslationResolver $translations): string
    {
        if ($state === 'in_stock') return $translations->shop('In stock');
        if ($state === 'backorder') return $translations->shop('Available on backorder');
        if ($state === 'not_for_sale') return $translations->shop('Not available for order');
        return $translations->shop('Out of stock');
    }
}
