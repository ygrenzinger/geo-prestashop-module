<?php

namespace LlmsCatalog\Service;

use Db;

/** Reads the revision snapshot used to validate cached documents. */
class CacheRevisionStore
{
    public function snapshotForWrite(int $shopId, string $type, int $productId = 0): array
    {
        return $this->snapshot($shopId, $type, $productId);
    }

    public function token(int $shopId, string $type, int $productId = 0): string
    {
        return hash('sha256', json_encode($this->snapshot($shopId, $type, $productId)));
    }

    public function catalogVersion(int $shopId): string
    {
        return substr($this->token($shopId, 'index'), 0, 16);
    }

    public function matches(array $row, string $type, int $productId): bool
    {
        $current = $this->snapshot((int) $row['id_shop'], $type, $productId);
        foreach (['settings', 'global_pricing', 'index', 'pricing', 'routing', 'product'] as $field) {
            if ((int) $row[$field . '_revision'] !== $current[$field]) {
                return false;
            }
        }

        return true;
    }

    private function snapshot(int $shopId, string $type, int $productId): array
    {
        $table = '`' . _DB_PREFIX_ . 'llmscatalog_revision`';
        $query = "SELECT (SELECT revision FROM $table WHERE id_shop=0 AND scope='settings' AND id_entity=0) AS settings, (SELECT revision FROM $table WHERE id_shop=0 AND scope='pricing' AND id_entity=0) AS global_pricing, (SELECT revision FROM $table WHERE id_shop=" . (int) $shopId . " AND scope='index' AND id_entity=0) AS `index`, (SELECT revision FROM $table WHERE id_shop=" . (int) $shopId . " AND scope='pricing' AND id_entity=0) AS pricing, (SELECT revision FROM $table WHERE id_shop=" . (int) $shopId . " AND scope='routing' AND id_entity=0) AS routing, (SELECT revision FROM $table WHERE id_shop=" . (int) $shopId . " AND scope='product' AND id_entity=" . (int) ($type === 'product' ? $productId : 0) . ') AS product';
        $row = Db::getInstance()->getRow($query);

        return [
            'settings' => (int) ($row['settings'] ?? 0),
            'global_pricing' => (int) ($row['global_pricing'] ?? 0),
            'index' => $type === 'index' ? (int) ($row['index'] ?? 0) : 0,
            'pricing' => (int) ($row['pricing'] ?? 0),
            'routing' => (int) ($row['routing'] ?? 0),
            'product' => $type === 'product' ? (int) ($row['product'] ?? 0) : 0,
        ];
    }
}
