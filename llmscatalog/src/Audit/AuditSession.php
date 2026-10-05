<?php
namespace LlmsCatalog\Audit;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/** Owns the shop/language-scoped, expiring state persisted between audit requests. */
final class AuditSession
{
    public const TTL = 900;

    public static function key(int $shopId, int $languageId): string
    {
        return 'llmscatalog_audit_' . $shopId . '_' . $languageId;
    }

    public static function read(SessionInterface $session, string $key): array
    {
        $state = $session->get($key);
        if (!is_array($state) || (int) ($state['expires'] ?? 0) < time()) {
            return self::emptyState();
        }
        return $state;
    }

    public static function emptyState(): array
    {
        return ['offset' => 0, 'findings' => [], 'expires' => time() + self::TTL];
    }

    public static function store(SessionInterface $session, string $key, array $state): void
    {
        $session->set($key, $state);
    }
}
