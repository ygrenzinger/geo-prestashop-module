<?php

namespace LlmsCatalog\Service;

/** Best-effort diagnostics that never become part of the business outcome. */
final class SafeDiagnosticLogger
{
    public static function log(string $operation, int $shopId, \Throwable $exception, int $severity = 2): void
    {
        $operation = preg_replace('/[^a-z0-9._-]/i', '-', $operation) ?: 'unknown';
        $operation = substr($operation, 0, 48);
        $error = substr(basename(str_replace('\\', '/', get_class($exception))), 0, 64);
        $detail = substr(preg_replace('/\s+/', ' ', (string) $exception->getMessage()), 0, 400);

        try {
            if (!class_exists('PrestaShopLogger')) {
                return;
            }

            \PrestaShopLogger::addLog(
                sprintf('llmscatalog operation=%s shop=%d error=%s detail=%s', $operation, max(0, $shopId), $error, $detail),
                $severity
            );
        } catch (\Throwable $loggingFailure) {
            // Diagnostics must never change storefront or back-office behavior.
        }
    }
}
