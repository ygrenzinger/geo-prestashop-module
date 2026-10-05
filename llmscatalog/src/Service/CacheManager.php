<?php

namespace LlmsCatalog\Service;

use Context;

/** Persistent public document cache shared by every web node. */
class CacheManager
{
    private ?CacheDocumentStore $documents;
    private CacheRevisionStore $revisions;
    private ?CacheGenerationLock $locks;
    private ?CacheKeyFactory $keys;
    private ?Context $context;

    public function __construct(?Context $context = null, ?CacheRevisionStore $revisions = null, ?CacheGenerationLock $locks = null, ?CacheKeyFactory $keys = null, ?CacheDocumentStore $documents = null)
    {
        $this->context = $context;
        $this->revisions = $revisions ?? new CacheRevisionStore();
        $this->locks = $locks;
        $this->keys = $keys;
        $this->documents = $documents;
    }

    public static function clearRequestMemo(): void { CacheDocumentStore::clearRequestMemo(); }

    public static function installTables(): bool
    {
        return CacheSchema::install();
    }

    public static function dropTables(): bool
    {
        return CacheSchema::drop();
    }

    public function get(string $key, string $type = 'index', int $langId = 0, int $productId = 0): ?array
    {
        return $this->documents()->get($key, $type, $langId, $productId);
    }

    public function getStale(string $key, string $type = 'index', int $langId = 0, int $productId = 0): ?array
    {
        return $this->documents()->getStale($key, $type, $langId, $productId);
    }

    public function put(string $key, array $record, int $ttl, string $type = 'index', int $langId = 0, int $productId = 0, string $variant = ''): bool
    {
        return $this->documents()->put($key, $record, $ttl, $type, $langId, $productId, $variant);
    }

    public function key(string $type, int $langId, Settings $settings, string $priceFingerprint, int $productId = 0, string $localizationFingerprint = '', int $cursor = 0, int $categoryId = 0): string
    {
        return $this->keys()->create($type, $langId, $settings, $priceFingerprint, $productId, $localizationFingerprint, $cursor, $categoryId);
    }

    public function boundedTtl(int $configuredTtl, bool $pricesEnabled, ?int $promotionExpiry = null, ?int $now = null): int
    { $now = $now ?? time(); $ttl = max(1, $configuredTtl); if ($promotionExpiry && $promotionExpiry > $now) $ttl = min($ttl, $promotionExpiry - $now); return max(1, $ttl); }
    public function clearAll(): void { (new InvalidationService())->invalidateSettings(); }
    public function clearShop(int $shopId): void { (new InvalidationService())->invalidateIndex([$shopId]); }
    public function bumpGeneration(): void { $this->clearAll(); }

    public function revisionToken(string $type, int $productId = 0): string
    { $shopId = (int) $this->context()->shop->id; return $this->revisions->token($shopId, $type, $productId); }

    public function catalogVersion(): string { return $this->revisions->catalogVersion((int) $this->context()->shop->id); }

    /** Returns false only when another origin node owns this document lock. */
    public function acquireGenerationLock(string $key): bool
    {
        return $this->locks()->acquire($key);
    }

    public function releaseGenerationLock(string $key): void
    {
        $this->locks()->release($key);
    }

    private function context(): Context
    {
        return $this->context ??= Context::getContext();
    }

    private function documents(): CacheDocumentStore
    {
        return $this->documents ??= new CacheDocumentStore($this->context(), $this->revisions);
    }

    private function locks(): CacheGenerationLock
    {
        return $this->locks ??= new CacheGenerationLock($this->context());
    }

    private function keys(): CacheKeyFactory
    {
        return $this->keys ??= new CacheKeyFactory($this->context());
    }
}
