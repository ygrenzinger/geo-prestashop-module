<?php
namespace LlmsCatalog\Audit;

/** Structural content evidence only; never assigns quality scores or rewrites copy. */
final class ContentInspector
{
    public function inspect(string $html,string $url,int $shopId,string $language): array
    {
        $now=gmdate(DATE_ATOM);$findings=[];$dom=new \DOMDocument();$prior=libxml_use_internal_errors(true);
        try{if(!$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))return [new AuditFinding('CONTENT_HTML_UNPARSEABLE','medium','content',$shopId,$language,$url,null,null,'Parseable rendered HTML',[],'Theme/content owner','Review the rendered page source.','content-v1',false,$now)];
            $x=new \DOMXPath($dom);$h1=$x->query('//h1');if(!$h1||$h1->length===0)$findings[]=new AuditFinding('CONTENT_H1_MISSING','medium','content',$shopId,$language,$url,null,null,'A visible primary heading for the entity',[],'Theme/content owner','Expose the product/category name as visible page heading content.','content-v1',false,$now);
            $main=$x->query('//main');if(!$main||$main->length===0)$findings[]=new AuditFinding('CONTENT_MAIN_MISSING','info','content',$shopId,$language,$url,null,null,'A clear main-content region',[],'Theme owner','Review semantic landmarks for the primary content.','content-v1',false,$now);
            $faq=$x->query('//*[contains(concat(" ",normalize-space(@class)," ")," faq ") or self::details]');foreach($faq?:[] as $node)if(trim((string)$node->textContent)==='')$findings[]=new AuditFinding('FAQ_NOT_VISIBLE','medium','content',$shopId,$language,$url,null,'empty FAQ section','Questions and answers visible in normal HTML',[],'Theme/content owner','Make authored FAQ content visible without interaction or scripts.','content-v1',false,$now);
            return $findings;
        }finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
    }
}
