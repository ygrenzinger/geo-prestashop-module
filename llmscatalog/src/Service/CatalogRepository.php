<?php

namespace LlmsCatalog\Service;

use Context;
use Db;
use DbQuery;
use Product;

class CatalogRepository
{
    private Context $context;
    private CatalogProductEnricher $enricher;

    public function __construct(Context $context, ?CatalogProductEnricher $enricher = null)
    {
        $this->context = $context;
        $this->enricher = $enricher ?? new CatalogProductEnricher($context);
    }

    public function products(int $langId, int $limit): array
    {
        $shopId = (int) $this->context->shop->id;
        $defaultLangId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $limit = max(1, $limit);

        $query = new DbQuery();
        $query->select('p.id_product, p.id_category_default, p.reference, p.price, ps.visibility, ps.active, ps.show_price');
        $query->select('COALESCE(NULLIF(pl.name, ""), pld.name) AS name');
        $query->select('CASE WHEN NULLIF(pl.name, "") IS NOT NULL THEN ' . (int) $langId . ' ELSE ' . $defaultLangId . ' END AS name_source_lang');
        $query->select('COALESCE(NULLIF(pl.description_short, ""), pld.description_short) AS description_short');
        $query->select('CASE WHEN NULLIF(pl.description_short, "") IS NOT NULL THEN ' . (int) $langId . ' ELSE ' . $defaultLangId . ' END AS description_short_source_lang');
        $query->select('COALESCE(NULLIF(pl.link_rewrite, ""), pld.link_rewrite) AS link_rewrite');
        $query->select('CASE WHEN NULLIF(pl.link_rewrite, "") IS NOT NULL THEN ' . (int) $langId . ' ELSE ' . $defaultLangId . ' END AS link_rewrite_source_lang');
        $query->from('product', 'p');
        $query->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . $shopId);
        $query->leftJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_shop = ' . $shopId . ' AND pl.id_lang = ' . (int) $langId);
        $query->leftJoin('product_lang', 'pld', 'pld.id_product = p.id_product AND pld.id_shop = ' . $shopId . ' AND pld.id_lang = ' . $defaultLangId);
        $query->where('ps.active = 1');
        $query->where("ps.visibility <> 'none'");
        $query->where('COALESCE(NULLIF(pl.name, ""), pld.name) IS NOT NULL');
        $query->where('COALESCE(NULLIF(pl.link_rewrite, ""), pld.link_rewrite) IS NOT NULL');
        $publicGroupId = $this->publicGroupId($shopId);
        $query->where('EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'category_product cp INNER JOIN ' . _DB_PREFIX_ . 'category cpub ON cpub.id_category = cp.id_category INNER JOIN ' . _DB_PREFIX_ . 'category_shop cspub ON cspub.id_category = cp.id_category AND cspub.id_shop = ' . $shopId . ' INNER JOIN ' . _DB_PREFIX_ . 'category_group cgp ON cgp.id_category = cp.id_category AND cgp.id_group = ' . $publicGroupId . ' WHERE cp.id_product = p.id_product AND cpub.active = 1)');
        $query->orderBy('ps.position ASC, p.id_product ASC');
        $query->limit($limit);

        $products = Db::getInstance()->executeS($query);
        if ($products === false) {
            throw new \RuntimeException('Catalog product query failed');
        }
        if (!is_array($products)) {
            throw new \RuntimeException('Catalog product query returned an invalid result');
        }

        $priceContext = (new PublicPriceContextFactory())->create($this->context, $langId);
        foreach ($products as &$product) {
            $this->enricher->enrich($product, $priceContext, false, $langId);
        }

        return $products;
    }

    /** Count eligible shop products without loading or enriching product objects. */
    public function eligibleProductCount(int $langId): int
    {
        return (int) Db::getInstance()->getValue($this->eligibleProductsQuery($langId, true));
    }

    public function activeCategoryCount(): int
    {
        $shopId = (int) $this->context->shop->id;
        $group = $this->publicGroupId($shopId);
        return (int) Db::getInstance()->getValue('SELECT COUNT(DISTINCT c.id_category) FROM `' . _DB_PREFIX_ . 'category` c INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.id_category=c.id_category AND cs.id_shop=' . $shopId . ' INNER JOIN `' . _DB_PREFIX_ . 'category_group` cg ON cg.id_category=c.id_category AND cg.id_group=' . $group . ' WHERE c.active=1 AND c.id_category>2');
    }

    public function categoryCounts(int $cursor): array
    {
        $shop=(int)$this->context->shop->id; $group=$this->publicGroupId($shop);
        $sql='SELECT COUNT(DISTINCT c.id_category) AS total, COUNT(DISTINCT CASE WHEN c.id_category>'.max(0,$cursor).' THEN c.id_category END) AS remaining FROM `'._DB_PREFIX_.'category` c INNER JOIN `'._DB_PREFIX_.'category_shop` cs ON cs.id_category=c.id_category AND cs.id_shop='.$shop.' INNER JOIN `'._DB_PREFIX_.'category_group` cg ON cg.id_category=c.id_category AND cg.id_group='.$group.' WHERE c.active=1 AND c.id_category>2';
        $row=Db::getInstance()->getRow($sql); return ['total'=>(int)($row['total']??0),'remaining'=>(int)($row['remaining']??0)];
    }

    public function productCounts(int $langId,int $cursor,int $categoryId=0): array
    {
        $shop=(int)$this->context->shop->id; $group=$this->publicGroupId($shop);
        $where="ps.active=1 AND ps.visibility<>'none' AND EXISTS (SELECT 1 FROM "._DB_PREFIX_."category_product cp INNER JOIN "._DB_PREFIX_."category c ON c.id_category=cp.id_category INNER JOIN "._DB_PREFIX_."category_shop cs ON cs.id_category=c.id_category AND cs.id_shop=".$shop." INNER JOIN "._DB_PREFIX_."category_group cg ON cg.id_category=c.id_category AND cg.id_group=".$group." WHERE cp.id_product=p.id_product AND c.active=1)";
        if($categoryId>0)$where.=' AND EXISTS (SELECT 1 FROM '._DB_PREFIX_.'category_product cpc WHERE cpc.id_product=p.id_product AND cpc.id_category='.(int)$categoryId.')';
        $sql='SELECT COUNT(DISTINCT p.id_product) AS total, COUNT(DISTINCT CASE WHEN p.id_product>'.max(0,$cursor).' THEN p.id_product END) AS remaining FROM `'._DB_PREFIX_.'product` p INNER JOIN `'._DB_PREFIX_.'product_shop` ps ON ps.id_product=p.id_product AND ps.id_shop='.$shop.' WHERE '.$where;
        $row=Db::getInstance()->getRow($sql); return ['total'=>(int)($row['total']??0),'remaining'=>(int)($row['remaining']??0)];
    }

    public function categoriesAfter(int $langId, int $cursor, int $limit = 100): array
    {
        $shopId = (int) $this->context->shop->id;
        $defaultLangId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $query = new DbQuery();
        $query->select('c.id_category, c.id_parent, COALESCE(NULLIF(cl.name, ""), cld.name) AS name, COALESCE(NULLIF(cl.link_rewrite, ""), cld.link_rewrite) AS link_rewrite');
        $query->from('category', 'c');
        $query->innerJoin('category_shop', 'cs', 'cs.id_category=c.id_category AND cs.id_shop=' . $shopId);
        $query->leftJoin('category_lang', 'cl', 'cl.id_category=c.id_category AND cl.id_shop=' . $shopId . ' AND cl.id_lang=' . (int) $langId);
        $query->leftJoin('category_lang', 'cld', 'cld.id_category=c.id_category AND cld.id_shop=' . $shopId . ' AND cld.id_lang=' . $defaultLangId);
        $query->where('c.active=1 AND c.id_category>2 AND c.id_category>' . max(0, $cursor));
        $query->where('EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'category_group cg WHERE cg.id_category=c.id_category AND cg.id_group=' . $this->publicGroupId($shopId) . ')');
        $query->orderBy('c.id_category ASC'); $query->limit(min(101, max(1, $limit)));
        $rows = Db::getInstance()->executeS($query);
        if (!is_array($rows)) throw new \RuntimeException('Category cursor query failed');
        return $rows;
    }

    public function countAfter(int $cursor, bool $categories = false, int $categoryId = 0, int $langId = 0): int
    {
        $shopId = (int) $this->context->shop->id;
        if (!$categories) return (int) Db::getInstance()->getValue($this->eligibleProductsQuery($langId ?: (int) \Configuration::get('PS_LANG_DEFAULT'), true, max(0, $cursor), 0, $categoryId));
        return (int) Db::getInstance()->getValue('SELECT COUNT(DISTINCT c.id_category) FROM `' . _DB_PREFIX_ . 'category` c INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.id_category=c.id_category AND cs.id_shop=' . $shopId . ' INNER JOIN `' . _DB_PREFIX_ . 'category_group` cg ON cg.id_category=c.id_category AND cg.id_group=' . $this->publicGroupId($shopId) . ' WHERE c.active=1 AND c.id_category>2 AND c.id_category>' . max(0, $cursor));
    }

    public function category(int $id, int $langId): ?array
    {
        $shopId=(int)$this->context->shop->id; $default=(int)\Configuration::get('PS_LANG_DEFAULT');
        $q=new DbQuery(); $q->select('c.id_category,c.id_parent,COALESCE(NULLIF(cl.name,""),cld.name) AS name,COALESCE(NULLIF(cl.link_rewrite,""),cld.link_rewrite) AS link_rewrite,COALESCE(NULLIF(clp.name,""),clpd.name) AS parent_name,COALESCE(NULLIF(clp.link_rewrite,""),clpd.link_rewrite) AS parent_rewrite');
        $q->from('category','c'); $q->innerJoin('category_shop','cs','cs.id_category=c.id_category AND cs.id_shop='.$shopId);
        $q->leftJoin('category_lang','cl','cl.id_category=c.id_category AND cl.id_shop='.$shopId.' AND cl.id_lang='.(int)$langId);
        $q->leftJoin('category_lang','cld','cld.id_category=c.id_category AND cld.id_shop='.$shopId.' AND cld.id_lang='.$default);
        $q->leftJoin('category','cp','cp.id_category=c.id_parent');
        $q->leftJoin('category_lang','clp','clp.id_category=cp.id_category AND clp.id_shop='.$shopId.' AND clp.id_lang='.(int)$langId);
        $q->leftJoin('category_lang','clpd','clpd.id_category=cp.id_category AND clpd.id_shop='.$shopId.' AND clpd.id_lang='.$default);
        $q->where('c.id_category='.(int)$id.' AND c.active=1 AND c.id_category>2');
        $q->where('EXISTS (SELECT 1 FROM '._DB_PREFIX_.'category_group cg WHERE cg.id_category=c.id_category AND cg.id_group='.$this->publicGroupId($shopId).')');
        $row=Db::getInstance()->getRow($q); return is_array($row)&&$row?$row:null;
    }

    public function categoryProductCount(int $categoryId, int $langId): int
    {
        $shopId=(int)$this->context->shop->id; $default=(int)\Configuration::get('PS_LANG_DEFAULT'); $q=new DbQuery();
        $q->select('COUNT(DISTINCT p.id_product)'); $q->from('product','p'); $q->innerJoin('product_shop','ps','ps.id_product=p.id_product AND ps.id_shop='.$shopId);
        $q->leftJoin('product_lang','pl','pl.id_product=p.id_product AND pl.id_shop='.$shopId.' AND pl.id_lang='.(int)$langId);
        $q->leftJoin('product_lang','pld','pld.id_product=p.id_product AND pld.id_shop='.$shopId.' AND pld.id_lang='.$default);
        $q->where("ps.active=1 AND ps.visibility<>'none' AND EXISTS (SELECT 1 FROM "._DB_PREFIX_."category_product cp INNER JOIN "._DB_PREFIX_."category c ON c.id_category=cp.id_category INNER JOIN "._DB_PREFIX_."category_shop cs ON cs.id_category=c.id_category AND cs.id_shop=".$shopId." INNER JOIN "._DB_PREFIX_."category_group cg ON cg.id_category=c.id_category AND cg.id_group=".$this->publicGroupId($shopId)." WHERE cp.id_product=p.id_product AND cp.id_category=".(int)$categoryId." AND c.active=1)");
        return (int)Db::getInstance()->getValue($q);
    }

    public function categoryProductsAfter(int $categoryId,int $langId,int $cursor,int $limit=100): array
    {
        $rows=Db::getInstance()->executeS($this->eligibleProductsQuery($langId,false,$cursor,min(101,max(1,$limit)),$categoryId));
        if(!is_array($rows)) throw new \RuntimeException('Category product cursor query failed');
        foreach($rows as &$row){$row['id_product']=(int)$row['id_product'];$row['name']=trim((string)($row['name']??''))?:'Product #'.$row['id_product'];$row['link_rewrite']=trim((string)($row['link_rewrite']??''))?:'product-'.$row['id_product'];}
        return $rows;
    }

    /** Compact ID-ordered cursor page; deliberately excludes price and stock work. */
    public function productsAfter(int $langId, int $cursor, int $limit = 100): array
    {
        $rows = Db::getInstance()->executeS($this->eligibleProductsQuery($langId, false, $cursor, min(101, max(1, $limit))));
        if (!is_array($rows)) throw new \RuntimeException('Catalog cursor query failed');
        foreach ($rows as &$row) {
            $row['id_product'] = (int) $row['id_product'];
            $row['name'] = trim((string) ($row['name'] ?? '')) ?: 'Product #' . $row['id_product'];
            $row['link_rewrite'] = trim((string) ($row['link_rewrite'] ?? '')) ?: 'product-' . $row['id_product'];
        }
        return $rows;
    }

    private function eligibleProductsQuery(int $langId, bool $count, int $cursor = 0, int $limit = 0, int $categoryId = 0): DbQuery
    {
        $shopId = (int) $this->context->shop->id;
        $defaultLangId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $query = new DbQuery();
        $query->select($count ? 'COUNT(DISTINCT p.id_product)' : 'p.id_product');
        if (!$count) {
            $query->select('COALESCE(NULLIF(pl.name, ""), pld.name) AS name');
            $query->select('COALESCE(NULLIF(pl.link_rewrite, ""), pld.link_rewrite) AS link_rewrite');
        }
        $query->from('product', 'p');
        $query->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . $shopId);
        $query->leftJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_shop = ' . $shopId . ' AND pl.id_lang = ' . (int) $langId);
        $query->leftJoin('product_lang', 'pld', 'pld.id_product = p.id_product AND pld.id_shop = ' . $shopId . ' AND pld.id_lang = ' . $defaultLangId);
        $query->where('ps.active = 1');
        $query->where("ps.visibility <> 'none'");
        $publicGroupId = $this->publicGroupId($shopId);
        $query->where('EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'category_product cp INNER JOIN ' . _DB_PREFIX_ . 'category c ON c.id_category = cp.id_category INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs ON cs.id_category = cp.id_category AND cs.id_shop = ' . $shopId . ' INNER JOIN ' . _DB_PREFIX_ . 'category_group cg ON cg.id_category = cp.id_category AND cg.id_group = ' . $publicGroupId . ' WHERE cp.id_product = p.id_product AND c.active = 1)');
        if ($categoryId > 0) $query->where('EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'category_product cpc WHERE cpc.id_product=p.id_product AND cpc.id_category=' . (int)$categoryId . ')');
        if ($cursor > 0) $query->where('p.id_product > ' . $cursor);
        if (!$count) { $query->orderBy('p.id_product ASC'); $query->limit(min(101, max(1, $limit))); }
        return $query;
    }

    public function product(int $idProduct, int $langId): ?array
    {
        $products = $this->productsByIds([$idProduct], $langId, true);
        return $products[0] ?? null;
    }

    /** Bounded, product-scoped detail reads used only by the rich endpoint. */
    public function richProductDetails(int $idProduct, int $langId, Settings $settings, int $defaultCombinationId = 0): array
    {
        $shopId = (int) $this->context->shop->id;
        $product = new Product($idProduct, false, $langId, $shopId);
        if (!\Validate::isLoadedObject($product)) return [];
        $defaultLang=(int)\Configuration::get('PS_LANG_DEFAULT', null, (int)$this->context->shop->id_shop_group, $shopId); $usedFallback=false; $fallbackFields=[];
        $imageRows=Db::getInstance()->executeS('SELECT i.id_image, COALESCE(NULLIF(il.legend,""),ild.legend) AS legend, CASE WHEN NULLIF(il.legend,"") IS NOT NULL THEN 0 ELSE 1 END AS used_fallback FROM `'._DB_PREFIX_.'image` i LEFT JOIN `'._DB_PREFIX_.'image_lang` il ON il.id_image=i.id_image AND il.id_lang='.(int)$langId.' LEFT JOIN `'._DB_PREFIX_.'image_lang` ild ON ild.id_image=i.id_image AND ild.id_lang='.(int)$defaultLang.' WHERE i.id_product='.(int)$idProduct.' ORDER BY i.cover DESC,i.position ASC,i.id_image ASC LIMIT '.(int)($settings->productLimit('images')+1));
        if (!is_array($imageRows)) $imageRows=[]; $imageCount=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'image` WHERE id_product='.(int)$idProduct); $images = [];
        foreach (array_slice($imageRows, 0, $settings->productLimit('images')) as $image) {
            if ($langId!==$defaultLang && !empty($image['used_fallback'])) { $usedFallback=true; $fallbackFields['image legends']=true; }
            $idImage = (int) ($image['id_image'] ?? 0);
            if ($idImage > 0) $images[] = ['id' => $idImage, 'legend' => trim((string) ($image['legend'] ?? ''))];
        }
        $categorySql='SELECT c.id_category, COALESCE(NULLIF(cl.name,""),cld.name) AS name, COALESCE(NULLIF(cl.link_rewrite,""),cld.link_rewrite) AS link_rewrite, CASE WHEN NULLIF(cl.name,"") IS NOT NULL THEN 0 ELSE 1 END AS used_fallback FROM `'._DB_PREFIX_.'category_product` cp INNER JOIN `'._DB_PREFIX_.'category` c ON c.id_category=cp.id_category INNER JOIN `'._DB_PREFIX_.'category_shop` cs ON cs.id_category=c.id_category AND cs.id_shop='.(int)$shopId.' INNER JOIN `'._DB_PREFIX_.'category_group` cg ON cg.id_category=c.id_category AND cg.id_group='.(int)$this->publicGroupId($shopId).' LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON cl.id_category=c.id_category AND cl.id_shop='.(int)$shopId.' AND cl.id_lang='.(int)$langId.' LEFT JOIN `'._DB_PREFIX_.'category_lang` cld ON cld.id_category=c.id_category AND cld.id_shop='.(int)$shopId.' AND cld.id_lang='.(int)$defaultLang.' WHERE cp.id_product='.(int)$idProduct.' AND c.active=1 ORDER BY c.level_depth ASC,c.position ASC,c.id_category ASC LIMIT '.(int)($settings->productLimit('categories')+1);
        $allCategories=Db::getInstance()->executeS($categorySql); if (!is_array($allCategories)) $allCategories=[];
        $categories=[]; foreach (array_slice($allCategories,0,$settings->productLimit('categories')) as $row) { if($langId!==$defaultLang && !empty($row['used_fallback'])){$usedFallback=true;$fallbackFields['categories']=true;} if(trim((string)($row['name']??''))!=='')$categories[]=['id'=>(int)$row['id_category'],'name'=>(string)$row['name'],'rewrite'=>(string)($row['link_rewrite']??'')]; }
        $featureRows=Db::getInstance()->executeS('SELECT COALESCE(NULLIF(fl.name,""),fld.name) AS name,COALESCE(NULLIF(fvl.value,""),fvld.value) AS value,CASE WHEN NULLIF(fl.name,"") IS NOT NULL AND NULLIF(fvl.value,"") IS NOT NULL THEN 0 ELSE 1 END AS used_fallback FROM `'._DB_PREFIX_.'feature_product` fp INNER JOIN `'._DB_PREFIX_.'feature_value` fv ON fv.id_feature_value=fp.id_feature_value LEFT JOIN `'._DB_PREFIX_.'feature_lang` fl ON fl.id_feature=fp.id_feature AND fl.id_lang='.(int)$langId.' LEFT JOIN `'._DB_PREFIX_.'feature_lang` fld ON fld.id_feature=fp.id_feature AND fld.id_lang='.(int)$defaultLang.' LEFT JOIN `'._DB_PREFIX_.'feature_value_lang` fvl ON fvl.id_feature_value=fv.id_feature_value AND fvl.id_lang='.(int)$langId.' LEFT JOIN `'._DB_PREFIX_.'feature_value_lang` fvld ON fvld.id_feature_value=fv.id_feature_value AND fvld.id_lang='.(int)$defaultLang.' WHERE fp.id_product='.(int)$idProduct.' ORDER BY fp.id_feature ASC LIMIT '.(int)($settings->productLimit('specs')+1));
        if (!is_array($featureRows)) $featureRows=[]; $featureCount=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'feature_product` WHERE id_product='.(int)$idProduct); $features = [];
        foreach (array_slice($featureRows, 0, $settings->productLimit('specs')) as $row) {
            if ($langId!==$defaultLang && !empty($row['used_fallback'])) { $usedFallback=true; $fallbackFields['specifications']=true; }
            $name = trim((string) ($row['name'] ?? '')); $value = trim((string) ($row['value'] ?? ''));
            if ($name !== '' && $value !== '') $features[] = ['name'=>$name,'value'=>$value];
        }
        $variants = [];
        $variantCount=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'product_attribute` pa INNER JOIN `'._DB_PREFIX_.'product_attribute_shop` pas ON pas.id_product_attribute=pa.id_product_attribute AND pas.id_shop='.(int)$shopId.' WHERE pa.id_product='.(int)$idProduct);
        if ($variantCount > 0) {
            $grouped = [];
            $combinationRows=Db::getInstance()->executeS('SELECT pa.id_product_attribute,pa.reference,COALESCE(NULLIF(agl.name,""),agld.name) AS group_name,COALESCE(NULLIF(al.name,""),ald.name) AS attribute_name FROM `'._DB_PREFIX_.'product_attribute` pa INNER JOIN `'._DB_PREFIX_.'product_attribute_shop` pas ON pas.id_product_attribute=pa.id_product_attribute AND pas.id_shop='.(int)$shopId.' LEFT JOIN `'._DB_PREFIX_.'product_attribute_combination` pac ON pac.id_product_attribute=pa.id_product_attribute LEFT JOIN `'._DB_PREFIX_.'attribute` a ON a.id_attribute=pac.id_attribute LEFT JOIN `'._DB_PREFIX_.'attribute_lang` al ON al.id_attribute=a.id_attribute AND al.id_lang='.(int)$langId.' LEFT JOIN `'._DB_PREFIX_.'attribute_lang` ald ON ald.id_attribute=a.id_attribute AND ald.id_lang='.(int)$defaultLang.' LEFT JOIN `'._DB_PREFIX_.'attribute_group_lang` agl ON agl.id_attribute_group=a.id_attribute_group AND agl.id_lang='.(int)$langId.' LEFT JOIN `'._DB_PREFIX_.'attribute_group_lang` agld ON agld.id_attribute_group=a.id_attribute_group AND agld.id_lang='.(int)$defaultLang.' WHERE pa.id_product='.(int)$idProduct.' AND (pa.id_product_attribute IN (SELECT pa2.id_product_attribute FROM `'._DB_PREFIX_.'product_attribute` pa2 INNER JOIN `'._DB_PREFIX_.'product_attribute_shop` pas2 ON pas2.id_product_attribute=pa2.id_product_attribute AND pas2.id_shop='.(int)$shopId.' WHERE pa2.id_product='.(int)$idProduct.' ORDER BY pa2.id_product_attribute ASC LIMIT '.(int)($settings->productLimit('variants')+1).') OR pa.id_product_attribute='.(int)$defaultCombinationId.') ORDER BY pa.id_product_attribute ASC,a.id_attribute_group ASC,a.position ASC');
            if (!is_array($combinationRows)) $combinationRows=[];
            foreach ($combinationRows as $row) {
                $id = (int) ($row['id_product_attribute'] ?? 0); if ($id <= 0) continue;
                $grouped[$id]['id'] = $id;
                $label = trim((string) ($row['group_name'] ?? '') . ': ' . (string) ($row['attribute_name'] ?? ''), ': ');
                if ($label !== '') $grouped[$id]['attributes'][] = $label;
                if (!empty($row['reference'])) $grouped[$id]['reference'] = (string)$row['reference'];
            }
            ksort($grouped, SORT_NUMERIC);
            $variants = array_slice(array_values($grouped), 0, $settings->productLimit('variants'));
            if ($defaultCombinationId > 0 && isset($grouped[$defaultCombinationId])) {
                $variants = array_values(array_filter($variants, static function($variant) use ($defaultCombinationId) { return (int)$variant['id'] !== $defaultCombinationId; }));
                array_unshift($variants, $grouped[$defaultCombinationId]);
                $variants = array_slice($variants, 0, $settings->productLimit('variants'));
            }
        }
        $packRows=[]; $packCount=0; $pack=[];
        if ((int)$product->cache_is_pack===1) { $packRows=Db::getInstance()->executeS('SELECT pp.id_product_item AS id_product,pp.quantity,COALESCE(NULLIF(pl.name,""),pld.name) AS name,ps.active,ps.visibility FROM `'._DB_PREFIX_.'pack` pp INNER JOIN `'._DB_PREFIX_.'product_shop` ps ON ps.id_product=pp.id_product_item AND ps.id_shop='.(int)$shopId.' LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON pl.id_product=pp.id_product_item AND pl.id_shop='.(int)$shopId.' AND pl.id_lang='.(int)$langId.' LEFT JOIN `'._DB_PREFIX_.'product_lang` pld ON pld.id_product=pp.id_product_item AND pld.id_shop='.(int)$shopId.' AND pld.id_lang='.(int)$defaultLang.' WHERE pp.id_product_pack='.(int)$idProduct.' AND ps.active=1 AND ps.visibility<>"none" AND EXISTS (SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp INNER JOIN `'._DB_PREFIX_.'category` c ON c.id_category=cp.id_category INNER JOIN `'._DB_PREFIX_.'category_shop` cs ON cs.id_category=c.id_category AND cs.id_shop='.(int)$shopId.' INNER JOIN `'._DB_PREFIX_.'category_group` cg ON cg.id_category=c.id_category AND cg.id_group='.(int)$this->publicGroupId($shopId).' WHERE cp.id_product=pp.id_product_item AND c.active=1) ORDER BY pp.id_product_item ASC LIMIT '.(int)($settings->productLimit('pack')+1)); if (!is_array($packRows))$packRows=[]; $packCount=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'pack` pp INNER JOIN `'._DB_PREFIX_.'product_shop` ps ON ps.id_product=pp.id_product_item AND ps.id_shop='.(int)$shopId.' WHERE pp.id_product_pack='.(int)$idProduct.' AND ps.active=1 AND ps.visibility<>"none" AND EXISTS (SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp INNER JOIN `'._DB_PREFIX_.'category` c ON c.id_category=cp.id_category INNER JOIN `'._DB_PREFIX_.'category_shop` cs ON cs.id_category=c.id_category AND cs.id_shop='.(int)$shopId.' INNER JOIN `'._DB_PREFIX_.'category_group` cg ON cg.id_category=c.id_category AND cg.id_group='.(int)$this->publicGroupId($shopId).' WHERE cp.id_product=pp.id_product_item AND c.active=1)'); foreach(array_slice($packRows,0,$settings->productLimit('pack')) as $item)$pack[]=['id'=>(int)$item['id_product'],'name'=>(string)$item['name'],'quantity'=>max(1,(int)$item['quantity'])]; }
        $custom = [];
        $rows = Db::getInstance()->executeS('SELECT cf.id_customization_field, cfl.name FROM `'._DB_PREFIX_.'customization_field` cf INNER JOIN `'._DB_PREFIX_.'customization_field_lang` cfl ON cfl.id_customization_field=cf.id_customization_field AND cfl.id_lang='.(int)$langId.' WHERE cf.id_product='.(int)$idProduct.' AND cf.is_module=0 ORDER BY cf.id_customization_field ASC LIMIT '.(int)$settings->productLimit('custom'));
        if (is_array($rows)) foreach ($rows as $row) if (trim((string)$row['name']) !== '') $custom[] = (string)$row['name'];
        $customCount=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'customization_field` WHERE id_product='.(int)$idProduct.' AND is_module=0');
        $type = (int)$product->is_virtual ? 'virtual' : ((int)$product->cache_is_pack ? 'pack' : ($custom ? 'customizable' : ($variants ? 'combination' : 'physical')));
        $descriptionRow=Db::getInstance()->getRow('SELECT COALESCE(NULLIF(pl.description,""),pld.description) AS description,CASE WHEN NULLIF(pl.description,"") IS NOT NULL THEN 0 ELSE 1 END AS used_fallback FROM `'._DB_PREFIX_.'product` p INNER JOIN `'._DB_PREFIX_.'product_shop` ps ON ps.id_product=p.id_product AND ps.id_shop='.(int)$shopId.' LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON pl.id_product=p.id_product AND pl.id_shop='.(int)$shopId.' AND pl.id_lang='.(int)$langId.' LEFT JOIN `'._DB_PREFIX_.'product_lang` pld ON pld.id_product=p.id_product AND pld.id_shop='.(int)$shopId.' AND pld.id_lang='.(int)$defaultLang.' WHERE p.id_product='.(int)$idProduct);
        $description=(string)($descriptionRow['description']??''); if($langId!==$defaultLang && !empty($descriptionRow['used_fallback'])){$usedFallback=true;$fallbackFields['full description']=true;}
        $categoryCount=(int)Db::getInstance()->getValue('SELECT COUNT(DISTINCT c.id_category) FROM `'._DB_PREFIX_.'category_product` cp INNER JOIN `'._DB_PREFIX_.'category` c ON c.id_category=cp.id_category INNER JOIN `'._DB_PREFIX_.'category_shop` cs ON cs.id_category=c.id_category AND cs.id_shop='.(int)$shopId.' INNER JOIN `'._DB_PREFIX_.'category_group` cg ON cg.id_category=c.id_category AND cg.id_group='.(int)$this->publicGroupId($shopId).' WHERE cp.id_product='.(int)$idProduct.' AND c.active=1');
        return ['manufacturer'=>trim((string)($product->manufacturer_name ?? '')), 'reference'=>trim((string)$product->reference), 'ean13'=>trim((string)$product->ean13), 'upc'=>trim((string)$product->upc), 'isbn'=>trim((string)($product->isbn ?? '')), 'mpn'=>trim((string)($product->mpn ?? '')), 'condition'=>(string)$product->condition,
            'description'=>(string)$description, 'images'=>$images, 'categories'=>$categories, 'features'=>$features, 'variants'=>$variants, 'pack'=>$pack, 'custom'=>$custom, 'type'=>$type, 'date_upd'=>(string)$product->date_upd,
            'omitted'=>['images'=>max(0,$imageCount-count($images)),'categories'=>max(0,$categoryCount-count($categories)),'specifications'=>max(0,$featureCount-count($features)),'variants'=>max(0,$variantCount-count($variants)),'pack'=>max(0,$packCount-count($pack)),'customization fields'=>max(0,$customCount-count($custom))], 'used_fallback'=>$usedFallback, 'fallback_fields'=>array_keys($fallbackFields)];
    }

    public function categories(int $langId, int $limit = 50): array
    {
        $shopId = (int) $this->context->shop->id;
        $defaultLangId = (int) \Configuration::get('PS_LANG_DEFAULT');

        $query = new DbQuery();
        $query->select('c.id_category, c.id_parent');
        $query->select('COALESCE(NULLIF(cl.name, ""), cld.name) AS name');
        $query->select('CASE WHEN NULLIF(cl.name, "") IS NOT NULL THEN ' . (int) $langId . ' ELSE ' . $defaultLangId . ' END AS name_source_lang');
        $query->select('COALESCE(NULLIF(cl.link_rewrite, ""), cld.link_rewrite) AS link_rewrite');
        $query->select('CASE WHEN NULLIF(cl.link_rewrite, "") IS NOT NULL THEN ' . (int) $langId . ' ELSE ' . $defaultLangId . ' END AS link_rewrite_source_lang');
        $query->from('category', 'c');
        $query->innerJoin('category_shop', 'cs', 'cs.id_category = c.id_category AND cs.id_shop = ' . $shopId);
        $query->leftJoin('category_lang', 'cl', 'cl.id_category = c.id_category AND cl.id_shop = ' . $shopId . ' AND cl.id_lang = ' . (int) $langId);
        $query->leftJoin('category_lang', 'cld', 'cld.id_category = c.id_category AND cld.id_shop = ' . $shopId . ' AND cld.id_lang = ' . $defaultLangId);
        $query->where('c.active = 1');
        $query->where('c.id_category > 2');
        $query->where('COALESCE(NULLIF(cl.name, ""), cld.name) IS NOT NULL');
        $query->where('COALESCE(NULLIF(cl.link_rewrite, ""), cld.link_rewrite) IS NOT NULL');
        $query->where('EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'category_group cg WHERE cg.id_category = c.id_category AND cg.id_group = ' . $this->publicGroupId($shopId) . ')');
        $query->orderBy('c.level_depth ASC, c.position ASC, c.id_category ASC');
        if ($limit > 0) $query->limit(min(250, max(1, $limit)));

        $categories = Db::getInstance()->executeS($query);
        if ($categories === false || !is_array($categories)) {
            throw new \RuntimeException('Catalog category query failed');
        }
        return $categories;
    }

    private function productsByIds(array $ids, int $langId, bool $detailed = false): array
    {
        if ($ids === []) {
            return [];
        }

        $shopId = (int) $this->context->shop->id;
        $defaultLangId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $idList = implode(',', array_map('intval', $ids));

        $query = new DbQuery();
        $query->select('p.id_product, p.id_category_default, p.reference, p.price, ps.visibility, ps.active, ps.show_price');
        $query->select('COALESCE(NULLIF(pl.name, ""), pld.name) AS name');
        $query->select('CASE WHEN NULLIF(pl.name, "") IS NOT NULL THEN ' . (int) $langId . ' ELSE ' . $defaultLangId . ' END AS name_source_lang');
        $query->select('COALESCE(NULLIF(pl.description_short, ""), pld.description_short) AS description_short');
        $query->select('CASE WHEN NULLIF(pl.description_short, "") IS NOT NULL THEN ' . (int) $langId . ' ELSE ' . $defaultLangId . ' END AS description_short_source_lang');
        $query->select('COALESCE(NULLIF(pl.link_rewrite, ""), pld.link_rewrite) AS link_rewrite');
        $query->select('CASE WHEN NULLIF(pl.link_rewrite, "") IS NOT NULL THEN ' . (int) $langId . ' ELSE ' . $defaultLangId . ' END AS link_rewrite_source_lang');
        $query->from('product', 'p');
        $query->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . $shopId);
        $query->leftJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_shop = ' . $shopId . ' AND pl.id_lang = ' . (int) $langId);
        $query->leftJoin('product_lang', 'pld', 'pld.id_product = p.id_product AND pld.id_shop = ' . $shopId . ' AND pld.id_lang = ' . $defaultLangId);
        $query->where('p.id_product IN (' . $idList . ')');
        $query->where('ps.active = 1');
        $query->where("ps.visibility <> 'none'");
        $query->where('COALESCE(NULLIF(pl.name, ""), pld.name) IS NOT NULL');
        $query->where('COALESCE(NULLIF(pl.link_rewrite, ""), pld.link_rewrite) IS NOT NULL');
        $publicGroupId = $this->publicGroupId($shopId);
        $query->where('EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'category_product cp INNER JOIN ' . _DB_PREFIX_ . 'category cpub ON cpub.id_category = cp.id_category INNER JOIN ' . _DB_PREFIX_ . 'category_shop cspub ON cspub.id_category = cp.id_category AND cspub.id_shop = ' . $shopId . ' INNER JOIN ' . _DB_PREFIX_ . 'category_group cgp ON cgp.id_category = cp.id_category AND cgp.id_group = ' . $publicGroupId . ' WHERE cp.id_product = p.id_product AND cpub.active = 1)');

        $products = Db::getInstance()->executeS($query);
        if ($products === false || !is_array($products)) {
            throw new \RuntimeException('Catalog product query failed');
        }

        $priceContext = (new PublicPriceContextFactory())->create($this->context, $langId);
        foreach ($products as &$product) {
            $this->enricher->enrich($product, $priceContext, $detailed, $langId);
        }

        return $products;
    }

    private function publicGroupId(int $shopId): int
    {
        return (int) \Configuration::get(
            'PS_UNIDENTIFIED_GROUP',
            null,
            (int) $this->context->shop->id_shop_group,
            $shopId
        );
    }
}
