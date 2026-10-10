<?php
namespace LlmsCatalog\Audit;

/** Stateless bounded coordinator. Call once per AJAX batch; cancellation is explicit. */
final class AuditRunner
{
    public const BATCH_SIZE = 5;
    public const MAX_SAMPLE = 36;

    public function run(array $urls, int $shopId, string $language, int $offset = 0, bool $cancel = false): AuditReport
    {
        $now = gmdate(DATE_ATOM);
        if ($cancel) return new AuditReport($shopId, $language, '1', [], false, $now);
        $urls = array_slice(array_values(array_unique(array_filter($urls, 'is_string'))), 0, self::MAX_SAMPLE);
        $batch = array_slice($urls, max(0, $offset), self::BATCH_SIZE);
        $findings = [];
        foreach ($batch as $url) {
            $findings[] = new AuditFinding('AUDIT_RENDERED_FETCH_REQUIRED', 'info', 'rendered-page', $shopId, $language, $url, null, null, 'Rendered evidence requires the configured shop-local fetch adapter.', [], 'Theme or SEO module', 'Review page source and run the bounded shop-local fetch stage.', 'audit-runner-v1', false, $now);
        }
        return new AuditReport($shopId, $language, '1', $findings, $offset + count($batch) >= count($urls), $now);
    }

    /** Executes one bounded batch; input URLs must come from the internal page inventory. */
    public function runRenderedBatch(array $urls, array $allowedHosts, int $shopId, string $language, int $offset=0, bool $cancel=false, array $productUrls=[],array $variantUrls=[]): AuditReport
    {
        $now=gmdate(DATE_ATOM);if($cancel)return new AuditReport($shopId,$language,'1',[],false,$now);
        $urls=array_slice(array_values(array_unique(array_filter($urls,'is_string'))),0,self::MAX_SAMPLE);
        $batch=array_slice($urls,max(0,$offset),self::BATCH_SIZE);$findings=[];$fetcher=new BoundedHttpFetcher();
        $productAuditor=new ProductOfferAuditor();
        foreach($batch as $url){
            try{
                $response=$fetcher->fetch($url,$allowedHosts);
                if($response['status']<200||$response['status']>=300){$findings[]=new AuditFinding('PAGE_HTTP_STATUS','high','rendered-page',$shopId,$language,$url,null,$response['status'],'HTTP 200',[],'Hosting/CDN','Resolve the response status for the public canonical page.','http-v1',false,$now);continue;}
                $html=$response['body'];$signals=(new HtmlSignalsInspector())->inspect($html,$url,$language);
                foreach($signals['findings'] as $item)$findings[]=new AuditFinding($item['code'],$item['severity'],'rendered-page',$shopId,$language,$url,null,$item['observed']??null,$item['expected']??'Valid rendered signal',[],'Theme or SEO module','Correct the owning rendered surface and re-audit.','html-signals-v1',false,$now);
                $structured=(new StructuredDataInspector())->inspect($html);
                if(!$structured['parseable'])$findings[]=new AuditFinding('JSONLD_MALFORMED','high','structured-data',$shopId,$language,$url,null,$structured['error'],'Every JSON-LD block parses',[],'Theme or SEO module','Fix malformed JSON-LD at its owner; augmentation is suppressed.','jsonld-v1',false,$now);
                elseif(in_array($url,$productUrls,true))$findings=array_merge($findings,$productAuditor->audit($structured['products'],$url,$shopId,$language,$now,in_array($url,$variantUrls,true)));
                foreach((new ContentInspector())->inspect($html,$url,$shopId,$language) as $finding)$findings[]=$finding;
            }catch(\Throwable $e){$findings[]=new AuditFinding('PAGE_FETCH_FAILED','high','rendered-page',$shopId,$language,$url,null,'bounded fetch failed','Authorized same-shop HTTPS response',[], 'Hosting/CDN','Check same-shop HTTPS access and redirect targets.','http-v1',false,$now);}
        }
        return new AuditReport($shopId,$language,'1',$findings,$offset+count($batch)>=count($urls),$now);
    }
}
