<?php
namespace LlmsCatalog\Audit;

/** Assembles the existing admin API report contract from persisted audit state. */
final class AuditResponseFactory
{
    public function batch(int $shopId, string $locale, bool $complete, int $offset, int $total, array $state): array
    {
        return [
            'shop_id' => $shopId,
            'language' => $locale,
            'audit_version' => '1',
            'complete' => $complete,
            'processed' => min($offset, $total),
            'total' => $total,
            'summary' => AuditSummary::fromFindings($state['findings'] ?? []),
            'findings' => $state['findings'] ?? [],
            'scanned_at' => $state['scanned_at'] ?? null,
        ];
    }

    public function feed(int $feedRows, int $catalogSample, array $state): array
    {
        return [
            'complete' => true,
            'feed_rows' => $feedRows,
            'compared_catalog_sample' => $catalogSample,
            'summary' => AuditSummary::fromFindings($state['findings'] ?? []),
            'findings' => $state['findings'] ?? [],
        ];
    }

    public function download(int $shopId, string $locale, array $state): array
    {
        return [
            'shop_id' => $shopId,
            'language' => $locale,
            'audit_version' => '1',
            'summary' => AuditSummary::fromFindings($state['findings'] ?? []),
            'findings' => $state['findings'] ?? [],
            'scanned_at' => $state['scanned_at'] ?? null,
        ];
    }
}
