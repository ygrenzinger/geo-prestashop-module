<?php
namespace LlmsCatalog\Audit;

use Context;
use LlmsCatalog\Service\CatalogRepository;
use LlmsCatalog\Service\UrlBuilder;

/** Parses a bounded merchant feed and reconciles its sample with public catalog facts. */
final class FeedComparisonService
{
    public function compare(Context $context, int $shopId, int $languageId, string $locale, string $contents, string $format): array
    {
        $rows = (new FeedSnapshotReader())->read($contents, $format);
        $catalog = (new CatalogRepository($context))->products($languageId, 25);
        $byId = [];
        foreach ($catalog as $item) {
            $byId[(string) $item['id_product']] = $item;
        }

        $findings = [];
        $now = gmdate(DATE_ATOM);
        foreach (array_slice($rows, 0, 10000) as $row) {
            $id = (string) ($row['id'] ?? '');
            $item = $byId[$id] ?? null;
            if (!$item) {
                $findings[] = new AuditFinding('FEED_ENTITY_NOT_IN_SAMPLE', 'info', 'feed', $shopId, $locale, null, $id, $row, 'Feed row reconciled with sampled public catalog', 'Feed row is outside this bounded 25-product comparison sample.', 'Feed provider', 'Compare the row in the feed owner and landing page.', 'feed-v1', false, $now);
                continue;
            }
            $expected = [
                'title' => (string) $item['name'],
                'price' => (string) ($item['price'] ?? ''),
                'link' => (new UrlBuilder($context))->productHtmlUrl((int) $item['id_product'], (string) $item['link_rewrite'], $languageId),
                'availability' => (string) ($item['availability']['state'] ?? ''),
            ];
            $observed = [
                'title' => (string) ($row['title'] ?? ''),
                'price' => (string) ($row['price'] ?? ''),
                'link' => (string) ($row['link'] ?? ''),
                'availability' => (string) ($row['availability'] ?? ''),
            ];
            if ($expected !== $observed) {
                $findings[] = new AuditFinding('FEED_STOREFRONT_MISMATCH', 'high', 'feed', $shopId, $locale, $expected['link'], $id, ['feed' => $observed, 'catalog' => $expected], 'Feed and public landing data agree', [], 'Feed provider / catalog owner', 'Review the feed, storefront, public price, and availability together; the module does not choose an authoritative value.', 'feed-v1', false, $now);
            }
        }
        return ['rows' => $rows, 'catalog_sample' => count($byId), 'findings' => $findings, 'scanned_at' => $now];
    }
}
