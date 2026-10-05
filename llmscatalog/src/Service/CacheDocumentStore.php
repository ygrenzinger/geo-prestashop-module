<?php

namespace LlmsCatalog\Service;

use Context;
use Db;

/** Persists generated document bodies and applies revision snapshots to each row. */
class CacheDocumentStore
{
    private static array $memo = [];

    public function __construct(private Context $context, private CacheRevisionStore $revisions)
    {
    }

    public static function clearRequestMemo(): void
    {
        self::$memo = [];
    }

    public function get(string $key, string $type = 'index', int $langId = 0, int $productId = 0): ?array
    {
        if (isset(self::$memo[$key]) && (int) self::$memo[$key]['expires_at'] > time()) return self::$memo[$key];
        try {
            $row = Db::getInstance()->getRow("SELECT * FROM `" . _DB_PREFIX_ . "llmscatalog_cache_document` WHERE cache_key='" . pSQL($this->digest($key)) . "'");
            if (!is_array($row) || !$this->revisions->matches($row, $type, $productId)) return null;
            $expires = strtotime($row['fresh_until'] . ' UTC');
            if ($expires <= time()) return null;
            $record = ['body' => (string) $row['content'], 'etag' => '"sha256-' . $row['content_sha256'] . '"', 'expires_at' => $expires, 'generated_at' => strtotime($row['generated_at'] . ' UTC')];
            self::$memo[$key] = $record;
            return $record;
        } catch (\Throwable $exception) {
            $this->logCacheIssue('read', $exception);
            return null;
        }
    }

    /** A prior successful row can be used only while every dependency still matches. */
    public function getStale(string $key, string $type = 'index', int $langId = 0, int $productId = 0): ?array
    {
        try {
            $row = Db::getInstance()->getRow("SELECT * FROM `" . _DB_PREFIX_ . "llmscatalog_cache_document` WHERE cache_key='" . pSQL($this->digest($key)) . "'");
            if (!is_array($row) || !$this->revisions->matches($row, $type, $productId) || strtotime($row['stale_until'] . ' UTC') <= time()) return null;
            return ['body' => (string) $row['content'], 'etag' => '"sha256-' . $row['content_sha256'] . '"', 'expires_at' => time(), 'generated_at' => strtotime($row['generated_at'] . ' UTC'), 'stale' => true];
        } catch (\Throwable $exception) {
            $this->logCacheIssue('stale-read', $exception);
            return null;
        }
    }

    public function put(string $key, array $record, int $ttl, string $type = 'index', int $langId = 0, int $productId = 0, string $variant = ''): bool
    {
        try {
            $db = Db::getInstance(); $identity = $this->identity($type, $productId); $old = $db->getRow("SELECT content_sha256, generated_at FROM `" . _DB_PREFIX_ . "llmscatalog_cache_document` WHERE cache_key='" . pSQL($this->digest($key)) . "'");
            $hash = hash('sha256', (string) $record['body']); $now = time();
            $generated = is_array($old) && hash_equals((string) $old['content_sha256'], $hash) ? strtotime($old['generated_at'] . ' UTC') : $now;
            $fresh = (int) ($record['expires_at'] ?? ($now + max(1, $ttl))); $stale = $fresh + min(max(1, $ttl), 900);
            $snapshot = $this->revisions->snapshotForWrite($identity['shop'], $type, $productId);
            if (isset($record['revision_token']) && !hash_equals((string) $record['revision_token'], hash('sha256', json_encode($snapshot)))) return false;
            $values = [
                'cache_key' => $this->digest($key), 'resource_type' => $type, 'id_shop' => $identity['shop'], 'id_lang' => $langId, 'id_product' => $productId,
                'variant_hash' => hash('sha256', $variant), 'content' => (string) $record['body'], 'content_sha256' => $hash,
                'generated_at' => gmdate('Y-m-d H:i:s', $generated), 'fresh_until' => gmdate('Y-m-d H:i:s', $fresh), 'stale_until' => gmdate('Y-m-d H:i:s', $stale),
                'settings_revision' => $snapshot['settings'], 'global_pricing_revision' => $snapshot['global_pricing'], 'shop_index_revision' => $snapshot['index'],
                'shop_pricing_revision' => $snapshot['pricing'], 'shop_routing_revision' => $snapshot['routing'], 'product_revision' => $snapshot['product'], 'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
            if (!$db->insert('llmscatalog_cache_document', $values, false, true, Db::REPLACE)) return false;
            self::$memo[$key] = ['body' => $record['body'], 'etag' => '"sha256-' . $hash . '"', 'expires_at' => $fresh, 'generated_at' => $generated];
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'llmscatalog_cache_document` WHERE stale_until < UTC_TIMESTAMP() LIMIT 100');
            return true;
        } catch (\Throwable $exception) {
            $this->logCacheIssue('write', $exception);
            return false;
        }
    }

    private function logCacheIssue(string $operation, \Throwable $exception): void
    {
        SafeDiagnosticLogger::log('cache.document.' . $operation, (int) ($this->context?->shop->id ?? 0), $exception);
    }

    private function digest(string $key): string { return preg_match('/^[a-f0-9]{64}$/', $key) ? $key : hash('sha256', $key); }
    private function identity(string $type, int $productId): array { return ['shop' => (int) $this->context->shop->id, 'type' => $type, 'product' => $productId]; }
}
