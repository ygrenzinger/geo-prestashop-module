<?php

namespace LlmsCatalog\Service;

/** Shared freshness and persistence rules for generated public documents. */
class DocumentCachePolicy
{
    public function __construct(private CacheManager $cache, private Settings $settings)
    {
    }

    public function ttl(?int $promotionExpiry = null): int
    {
        return $this->cache->boundedTtl($this->settings->cacheTtl(), $this->settings->includesPrice(), $promotionExpiry);
    }

    /** Returns the persisted record, or null when persistence failed or a revision changed. */
    public function persist(string $key, string $body, int $ttl, int $expiresAt, string $revisionToken, string $type, int $langId, int $productId, string $variant): ?array
    {
        $record = [
            'body' => $body,
            'etag' => '"' . hash('sha256', $body) . '"',
            'expires_at' => $expiresAt,
            'revision_token' => $revisionToken,
        ];
        if (!$this->cache->put($key, $record, $ttl, $type, $langId, $productId, $variant)) {
            return null;
        }

        return $this->cache->get($key, $type, $langId, $productId);
    }
}
