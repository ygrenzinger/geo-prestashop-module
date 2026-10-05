<?php
namespace LlmsCatalog\Audit;

/** Bounded streaming Google-style XML/RSS or tab-separated feed reader. */
final class FeedSnapshotReader
{
    public function read(string $input, string $format): array
    {
        if(strlen($input)>2097152)throw new \LengthException('Feed input exceeds 2 MiB');
        if(strtolower($format)==='tsv')return $this->readTsv($input);
        if(strtolower($format)!=='xml')throw new \InvalidArgumentException('Unsupported feed format');
        $reader=new \XMLReader();$ok=$reader->XML($input,null,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_COMPACT);
        if(!$ok)throw new \RuntimeException('Malformed feed XML');
        $reader->setParserProperty(\XMLReader::LOADDTD,false);$reader->setParserProperty(\XMLReader::SUBST_ENTITIES,false);$rows=[];$row=[];$field=null;
        try{while($reader->read()) { if($reader->nodeType===\XMLReader::ELEMENT && strtolower($reader->localName)==='item')$row=[];elseif($reader->nodeType===\XMLReader::ELEMENT)$field=strtolower($reader->localName);elseif($reader->nodeType===\XMLReader::TEXT && $field!==null)$row[$field]=trim($reader->value);elseif($reader->nodeType===\XMLReader::END_ELEMENT && strtolower($reader->localName)==='item'){if($row)$rows[]=$row;if(count($rows)>=10000)break;$row=[];$field=null;}elseif($reader->nodeType===\XMLReader::END_ELEMENT)$field=null;}}finally{$reader->close();}
        return $rows;
    }
    private function readTsv(string $input): array
    {
        $stream=fopen('php://temp','r+');fwrite($stream,$input);rewind($stream);$headers=fgetcsv($stream,0,"\t","\"","\\");$rows=[];
        while(($cells=fgetcsv($stream,0,"\t","\"","\\"))!==false&&count($rows)<10000){$row=[];foreach((array)$headers as $i=>$key)if($key!=='')$row[$key]=(string)($cells[$i]??'');$rows[]=$row;}fclose($stream);return $rows;
    }
}
