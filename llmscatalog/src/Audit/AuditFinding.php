<?php
namespace LlmsCatalog\Audit;

/** Stable, serializable audit evidence. Values are public storefront facts only. */
final class AuditFinding implements \JsonSerializable
{
    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $surface,
        public readonly int $shopId,
        public readonly string $language,
        public readonly ?string $url,
        public readonly ?string $entityId,
        public readonly mixed $observed,
        public readonly string $expected,
        public readonly array $evidence,
        public readonly string $owner,
        public readonly string $remediation,
        public readonly string $source,
        public readonly bool $augmentable,
        public readonly string $scannedAt
    ) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
