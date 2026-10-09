<?php

namespace LlmsCatalog\Service;

use Context;

/** Compact, deterministic cursor documents. These pages never hydrate Product objects. */
final class CatalogPageGenerator
{
    private string $version;
    public function __construct(private CatalogRepository $catalog, private UrlBuilder $urls, private Settings $settings, ?string $catalogVersion = null) { $this->version=$catalogVersion ?? (new CacheManager())->catalogVersion(); }

    public function products(int $langId, int $cursor): ?string
    {
        $counts=$this->catalog->productCounts($langId,$cursor);
        $rows = $this->catalog->productsAfter($langId, $cursor, 101);
        if($cursor>0 && !$rows)return null;
        return $this->render($rows,$counts['total'],$counts['remaining'],$cursor,$langId,false);
    }

    public function categories(int $langId, int $cursor): ?string
    {
        $counts=$this->catalog->categoryCounts($cursor);
        $rows = $this->catalog->categoriesAfter($langId, $cursor, 101);
        if($cursor>0 && !$rows)return null;
        return $this->render($rows,$counts['total'],$counts['remaining'],$cursor,$langId,true);
    }

    public function categoryProducts(int $langId, int $categoryId, int $cursor, string $rewrite=''): ?string
    {
        $rewrite=$rewrite?:'category-'.$categoryId;
        $counts=$this->catalog->productCounts($langId,$cursor,$categoryId);
        $rows=$this->catalog->categoryProductsAfter($categoryId,$langId,$cursor,101);
        if($cursor>0 && !$rows)return null;
        return $this->render($rows,$counts['total'],$counts['remaining'],$cursor,$langId,false,$categoryId,$rewrite);
    }

    public function categoryOverview(int $langId,int $categoryId,?array $category=null): string
    {
        $category=$category??$this->catalog->category($categoryId,$langId); if($category===null)return '';
        $renderer=new MarkdownRenderer(); $name=$this->unicodeLimit(trim((string)($category['name']??''))?:'Category #'.$categoryId,200);
        $rewrite=(string)($category['link_rewrite']??'category-'.$categoryId);
        $html=Context::getContext()->link->getCategoryLink($categoryId,\Tools::link_rewrite($rewrite),$langId);
        $count=$this->catalog->categoryProductCount($categoryId,$langId);
        $lines=['# '.$renderer->text($name),'','- Canonical URL: '.$html];
        if((int)($category['id_parent']??0)>2 && trim((string)($category['parent_name']??''))!==''){
            $parent=Context::getContext()->link->getCategoryLink((int)$category['id_parent'],\Tools::link_rewrite((string)($category['parent_rewrite']??'')),$langId);
            $lines[]='- Parent category: '.$renderer->link((string)$category['parent_name'],$parent);
        }
        $lines[]='- Eligible products: '.$count; $lines[]=''; $lines[]='[Products in this category]('.$this->urls->categoryProductsUrl($langId,$categoryId,$rewrite).')'; $lines[]='';
        $body=implode("\n",$lines)."\n";
        return strlen($body)<=$this->settings->maxBytes()?$body:$renderer->truncate($body,$this->settings->maxBytes(),'Category details exceed the configured document limit.');
    }

    private function render(array $rows, int $total, int $remainingAtCursor, int $cursor, int $langId, bool $categories, int $categoryId=0, string $categoryRewrite=''): string
    {
        $type = $categories ? 'categories' : 'products';
        $title = $categories ? 'Catalog category directory' : 'Complete product catalog';
        $root = $this->urls->indexUrl($langId);
        $base = rtrim($this->urls->baseLink(), '/') . '/' . rawurlencode($this->urls->canonicalLocale($langId)) . '/llms/catalog/' . $type . '/after/';
        $version=$this->version;
        $lines = ['# ' . ($categoryId ? 'Products in category' : $title), '', '[Back to llms.txt](' . $root . ')', '', 'Catalog version: ' . $version . '.', 'Eligible records: ' . $total . '.', ''];
        if (count($rows) > 100) array_pop($rows);
        $rendered = [];
        foreach ($rows as $row) {
            $id = (int) ($categories ? $row['id_category'] : $row['id_product']);
            $name = (new MarkdownRenderer())->text((string) ($row['name'] ?? ''));
            if ($name === '') $name = ($categories ? 'Category #' : 'Product #') . $id;
            $name = $this->unicodeLimit($name,200);
            $url = $categories ? $this->urls->categoryMarkdownUrl($langId,$id,(string)($row['link_rewrite']??'')) : $this->urls->productMarkdownUrl($id, (string) ($row['link_rewrite'] ?? ''), $langId);
            $rendered[] = [$id, '[' . str_replace([']', '['], ['\\]', '\\['], $name) . '](' . $url . ')'];
        }
        $used = [];
        foreach ($rendered as $entry) {
            $candidate = $lines;
            foreach ($used as $prior) $candidate[] = $prior[1];
            $candidate[] = $entry[1];
            $candidate[] = '';
            $candidate[] = 'Rendered on this page: ' . (count($used) + 1) . '.';
            $candidate[] = 'Records remaining after this page: ' . max(0, $remainingAtCursor - count($used) - 1) . '.';
            $candidate[] = 'Catalog version: ' . $version . '.';
            if ($remainingAtCursor > count($used) + 1) $candidate[] = '[Next page](' . ($categoryId ? $this->urls->categoryProductsUrl($langId,$categoryId,$categoryRewrite,$entry[0]) : $base . $entry[0] . '.md') . ')';
            if (strlen(implode("\n", $candidate) . "\n") > $this->settings->maxBytes()) break;
            $used[] = $entry;
        }
        foreach ($used as $entry) $lines[] = $entry[1];
        $last = $used ? (int) end($used)[0] : $cursor;
        $remaining = max(0,$remainingAtCursor-count($used));
        $lines[] = '';
        $lines[] = 'Rendered on this page: ' . count($used) . '.';
        $lines[] = 'Records remaining after this page: ' . $remaining . '.';
        $lines[] = 'Catalog version: ' . $version . '.';
        if ($remaining > 0 && $last > $cursor) $lines[] = '[Next page](' . ($categoryId ? $this->urls->categoryProductsUrl($langId,$categoryId,$categoryRewrite,$last) : $base . $last . '.md') . ')';
        elseif ($remaining > 0) $lines[] = 'Increase the configured byte limit to continue this index.';
        return implode("\n", $lines) . "\n";
    }

    private function unicodeLimit(string $value,int $limit): string
    {
        if(function_exists('mb_substr')) return mb_substr($value,0,$limit,'UTF-8');
        if(preg_match_all('/./us',$value,$matches)) return implode('',array_slice($matches[0],0,$limit));
        return substr($value,0,$limit);
    }

}
