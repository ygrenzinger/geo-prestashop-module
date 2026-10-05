<?php

namespace LlmsCatalog\Service;

use Db;
use Shop;

/** Constant-cost, cross-node dependency revision invalidation. */
class InvalidationService
{
    private static array $seen = [];

    public function invalidateProduct(array $shops, int $productId, string $source = ''): void
    {
        if ($productId <= 0) { $this->invalidateIndex($shops, $source); return; }
        $operations = [];
        foreach ($this->shops($shops) as $shop) { $operations[] = [$shop, 'index', 0]; $operations[] = [$shop, 'product', $productId]; }
        $this->bump($operations, $source);
    }

    public function invalidateIndex(array $shops, string $source = ''): void
    {
        $operations = [];
        foreach ($this->shops($shops) as $shop) {
            $operations[] = [$shop, 'index', 0];
        }
        $this->bump($operations, $source);
    }

    public function invalidateShopPricing(array $shops, string $source = ''): void
    {
        $operations = [];
        foreach ($this->shops($shops) as $shop) {
            $operations[] = [$shop, 'pricing', 0];
            $operations[] = [$shop, 'index', 0];
        }
        $this->bump($operations, $source);
    }

    public function invalidateGlobalPricing(string $source = ''): void
    {
        $this->bump([[0, 'pricing', 0]], $source);
    }

    public function invalidateRouting(array $shops, string $source = ''): void
    {
        $operations = [];
        foreach ($this->shops($shops) as $shop) {
            $operations[] = [$shop, 'routing', 0];
            $operations[] = [$shop, 'index', 0];
        }
        $this->bump($operations, $source);
    }

    public function invalidateSettings(string $source = ''): void
    {
        $this->bump([[0, 'settings', 0]], $source);
    }

    private function bump(array $operations, string $source): void
    {
        CacheManager::clearRequestMemo();
        $db = Db::getInstance();
        $pending = [];
        foreach ($operations as [$shop, $scope, $entity]) {
            $key = "$shop:$scope:$entity";
            if (!isset(self::$seen[$key])) {
                self::$seen[$key] = true;
                $pending[] = [$shop, $scope, $entity];
            }
        }
        if (!$pending) {
            return;
        }
        try {
            $db->execute('START TRANSACTION');
            foreach ($pending as [$shop, $scope, $entity]) {
                $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'llmscatalog_revision` (`id_shop`,`scope`,`id_entity`,`revision`,`changed_at`) VALUES (' . (int) $shop . ",'" . pSQL($scope) . "'," . (int) $entity . ',1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `revision`=`revision`+1, `changed_at`=UTC_TIMESTAMP()';
                if (!$db->execute($sql)) throw new \RuntimeException('Revision write failed');
            }
            $db->execute('COMMIT');
        } catch (\Throwable $e) {
            try {
                $db->execute('ROLLBACK');
            } catch (\Throwable $rollbackFailure) {
                SafeDiagnosticLogger::log('cache.invalidation.rollback', 0, $rollbackFailure, 3);
            }
            SafeDiagnosticLogger::log('cache.invalidation.' . ($source ?: 'unknown'), 0, $e, 3);
        }
    }

    private function shops(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if ($ids) return $ids;
        $active = Shop::getShops(true, null, true);
        return array_values(array_unique(array_map('intval', is_array($active) ? $active : [])));
    }
}
