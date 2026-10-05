<?php
namespace LlmsCatalog\Audit;

/** Shop-authorized HTTPS fetches only; manual redirects, no cookies, strict bounds. */
final class BoundedHttpFetcher
{
    public function __construct(private readonly UrlGuard $guard = new UrlGuard()) {}
    public function fetch(string $url, array $allowedHosts, int $maxBytes = 2097152): array
    {
        $maxBytes=min(2097152,max(1024,$maxBytes)); $current=$url;
        for ($hop=0;$hop<=3;$hop++) {
            if (!$this->guard->authorize($current,$allowedHosts)) throw new \RuntimeException('URL rejected by audit host policy');
            $body=''; $headers=[]; $ch=curl_init($current);
            $parts=parse_url($current);$port=(int)($parts['port']??(strtolower($parts['scheme'])==='https'?443:80));$addresses=$this->guard->publicAddresses(strtolower((string)$parts['host']));if(!$addresses)throw new \RuntimeException('Shop host no longer resolves to public addresses');$resolve=[];foreach($addresses as $address)$resolve[]=$parts['host'].':'.$port.':'.(strpos($address,':')!==false?'['.$address.']':$address);
            curl_setopt_array($ch,[CURLOPT_RESOLVE=>$resolve,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>5,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'llmscatalog-audit/1',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xml,text/tab-separated-values,*/*;q=0.1'],CURLOPT_HEADERFUNCTION=>static function($curl,$line) use (&$headers){$len=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);return $len;},CURLOPT_WRITEFUNCTION=>static function($curl,$chunk) use (&$body,$maxBytes){if(strlen($body)+strlen($chunk)>$maxBytes)return 0;$body.=$chunk;return strlen($chunk);}]);
            $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
            if($ok===false)throw new \RuntimeException($error ?: 'Audit fetch failed or exceeded its byte limit');
            if(in_array($status,[301,302,303,307,308],true)) { if($hop===3||empty($headers['location']))throw new \RuntimeException('Audit redirect limit exceeded');$current=$this->resolveRedirect($current,$headers['location']);continue; }
            return ['url'=>$current,'status'=>$status,'headers'=>$headers,'body'=>$body];
        }
        throw new \RuntimeException('Audit redirect limit exceeded');
    }
    private function resolveRedirect(string $base,string $location): string
    {
        if (preg_match('~^https?://~i',$location)) return $location;
        $parts=parse_url($base); if (!$parts) return '';
        $origin=strtolower((string)$parts['scheme']).'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        return $origin.'/'.ltrim($location,'/');
    }
}
