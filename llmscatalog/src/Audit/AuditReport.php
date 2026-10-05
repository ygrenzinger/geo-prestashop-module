<?php
namespace LlmsCatalog\Audit;

final class AuditReport implements \JsonSerializable
{
    public function __construct(public readonly int $shopId, public readonly string $language, public readonly string $version, public readonly array $findings, public readonly bool $complete, public readonly string $scannedAt) {}
    public function jsonSerialize(): array { return ['shop_id'=>$this->shopId,'language'=>$this->language,'audit_version'=>$this->version,'findings'=>$this->findings,'complete'=>$this->complete,'scanned_at'=>$this->scannedAt]; }
}
