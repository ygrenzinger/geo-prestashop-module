<?php
namespace LlmsCatalog\Audit;

use Context;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/** Coordinates audit execution and its shop/language-local session lifecycle. */
final class AuditApplicationService
{
    public function __construct(
        private readonly AuditRunner $runner,
        private readonly PageInventory $inventory,
        private readonly SurfaceAuditor $surfaceAuditor,
        private readonly FeedComparisonService $feedComparison
    ) {}

    public function run(Context $context, SessionInterface $session, int $shopId, int $languageId, string $locale, string $action): array
    {
        $key = AuditSession::key($shopId, $languageId);
        if ($action === 'cancel') {
            $session->remove($key);
            return ['cancelled' => true];
        }

        $state = $action === 'start' ? AuditSession::emptyState() : AuditSession::read($session, $key);
        $inventory = $this->inventory->collectDetailed($context, $languageId);
        $urls = $inventory['urls'];
        $host = (string) parse_url($urls[0] ?? '', PHP_URL_HOST);
        $report = $this->runner->runRenderedBatch($urls, [$host], $shopId, $locale, (int) ($state['offset'] ?? 0), false, $inventory['product_urls'], $inventory['variant_urls']);

        $state['findings'] = array_merge(
            $state['findings'] ?? [],
            array_map(static fn (AuditFinding $finding) => $finding->jsonSerialize(), $report->findings)
        );
        $state['offset'] = (int) ($state['offset'] ?? 0) + AuditRunner::BATCH_SIZE;
        $state['complete'] = $report->complete;
        $state['scanned_at'] = $report->scannedAt;
        AuditSession::store($session, $key, $state);

        if ($state['complete']) {
            $surfaceFindings = $this->surfaceAuditor->audit($urls[0], [$host], $urls, $shopId, $locale);
            $state['findings'] = array_merge(
                $state['findings'],
                array_map(static fn (AuditFinding $finding) => $finding->jsonSerialize(), $surfaceFindings)
            );
            AuditSession::store($session, $key, $state);
        }

        return ['state' => $state, 'complete' => $report->complete, 'total' => count($urls)];
    }

    public function compareFeed(Context $context, SessionInterface $session, int $shopId, int $languageId, string $locale, string $contents, string $format): array
    {
        $comparison = $this->feedComparison->compare($context, $shopId, $languageId, $locale, $contents, $format);
        $state = [
            'offset' => 0,
            'findings' => array_map(static fn (AuditFinding $finding) => $finding->jsonSerialize(), $comparison['findings']),
            'complete' => true,
            'scanned_at' => $comparison['scanned_at'],
            'expires' => time() + AuditSession::TTL,
        ];
        AuditSession::store($session, AuditSession::key($shopId, $languageId), $state);
        return ['state' => $state, 'feed_rows' => count($comparison['rows']), 'catalog_sample' => $comparison['catalog_sample']];
    }

    public function currentReport(SessionInterface $session, int $shopId, int $languageId): ?array
    {
        $state = $session->get(AuditSession::key($shopId, $languageId));
        if (!is_array($state) || empty($state['complete']) || (int) ($state['expires'] ?? 0) < time()) {
            return null;
        }
        return $state;
    }
}
