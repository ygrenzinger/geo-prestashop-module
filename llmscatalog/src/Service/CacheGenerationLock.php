<?php

namespace LlmsCatalog\Service;

use Context;
use Db;

/** Coalesces concurrent origin generation while preserving fail-open cache behavior. */
class CacheGenerationLock
{
    private static bool $warningLogged = false;
    private static array $localLocks = [];
    private static array $databaseLocks = [];

    public function __construct(private Context $context)
    {
    }

    public function acquire(string $key): bool
    {
        try {
            $name = 'llmscat:' . substr($this->digest($key), 0, 48);
            $result = Db::getInstance()->getValue("SELECT GET_LOCK('" . pSQL($name) . "', 0)");
            if ($result === false || $result === null) {
                $this->logUnavailable();
                return $this->acquireLocal($key);
            }
            if ((int) $result === 1) {
                self::$databaseLocks[$key] = $name;
                return true;
            }

            return false;
        } catch (\Throwable $exception) {
            $this->logIssue('generation-lock', $exception);
            return $this->acquireLocal($key);
        }
    }

    public function release(string $key): void
    {
        if (isset(self::$localLocks[$key])) {
            @flock(self::$localLocks[$key], LOCK_UN);
            @fclose(self::$localLocks[$key]);
            unset(self::$localLocks[$key]);
            return;
        }
        if (!isset(self::$databaseLocks[$key])) {
            return;
        }

        $name = self::$databaseLocks[$key];
        unset(self::$databaseLocks[$key]);
        try {
            Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . pSQL($name) . "')");
        } catch (\Throwable $exception) {
            $this->logIssue('release-generation-lock', $exception);
            // Closing the database connection releases its advisory lock.
        }
    }

    private function acquireLocal(string $key): bool
    {
        if (!defined('_PS_CACHE_DIR_')) {
            return true;
        }
        $path = rtrim(_PS_CACHE_DIR_, '/\\') . DIRECTORY_SEPARATOR . 'llmscat-' . substr($this->digest($key), 0, 48) . '.lock';
        $handle = @fopen($path, 'c');
        if (!$handle) {
            return true;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return false;
        }
        self::$localLocks[$key] = $handle;
        return true;
    }

    private function logUnavailable(): void
    {
        if (self::$warningLogged || !class_exists('PrestaShopLogger')) {
            return;
        }
        self::$warningLogged = true;
        SafeDiagnosticLogger::log(
            'cache.generation-lock-unavailable',
            (int) ($this->context->shop->id ?? 0),
            new \RuntimeException('Advisory lock unavailable')
        );
    }

    private function logIssue(string $operation, \Throwable $exception): void
    {
        SafeDiagnosticLogger::log('cache.' . $operation, (int) ($this->context->shop->id ?? 0), $exception);
    }

    private function digest(string $key): string
    {
        return preg_match('/^[a-f0-9]{64}$/', $key) ? $key : hash('sha256', $key);
    }
}
