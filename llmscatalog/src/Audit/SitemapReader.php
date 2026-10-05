<?php
namespace LlmsCatalog\Audit;

/** Non-networking bounded XML sitemap reader; caller authorizes and fetches the URL. */
final class SitemapReader
{
    public function read(string $xml, int $limit=100000): array
    {
        if(strlen($xml)>2097152)throw new \LengthException('Sitemap input exceeds 2 MiB');
        $r=new \XMLReader();if(!$r->XML($xml,null,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_COMPACT))throw new \RuntimeException('Malformed sitemap XML');
        $r->setParserProperty(\XMLReader::LOADDTD,false);$r->setParserProperty(\XMLReader::SUBST_ENTITIES,false);$urls=[];$isIndex=false;$current=null;$lastmod=null;
        try{while($r->read()){if($r->nodeType===\XMLReader::ELEMENT&&$r->localName==='sitemapindex')$isIndex=true;if($r->nodeType===\XMLReader::ELEMENT&&$r->localName==='url'){$current=null;$lastmod=null;}elseif($r->nodeType===\XMLReader::ELEMENT&&$r->localName==='sitemap'){$current=null;}elseif($r->nodeType===\XMLReader::ELEMENT&&$r->localName==='loc')$current='loc';elseif($r->nodeType===\XMLReader::ELEMENT&&$r->localName==='lastmod')$current='lastmod';elseif($r->nodeType===\XMLReader::TEXT&&$current==='loc')$value=trim($r->value);elseif($r->nodeType===\XMLReader::TEXT&&$current==='lastmod')$lastmod=trim($r->value);elseif($r->nodeType===\XMLReader::END_ELEMENT&&in_array($r->localName,['url','sitemap'],true)){if(isset($value)&&filter_var($value,FILTER_VALIDATE_URL))$urls[]=['url'=>$value,'lastmod'=>$lastmod,'kind'=>$isIndex?'sitemap':'url'];unset($value);$current=null;if(count($urls)>=$limit)break;}}}finally{$r->close();}
        return ['items'=>$urls,'truncated'=>count($urls)>=$limit,'index'=>$isIndex];
    }
}
