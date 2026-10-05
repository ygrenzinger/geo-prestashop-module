<?php

namespace LlmsCatalog\ValueObject;

final class PublicDocument
{
    public function __construct(
        public string $body,
        public string $contentType,
        public int $languageId,
        public int $status = 200,
        public bool $cacheable = true,
        public int $originExpiry = 0,
        public array $headers = [],
        public int $generatedAt = 0,
        public bool $lastModifiedEnabled = true
    ) {
    }

    public function etag(): string
    {
        return '"sha256-' . hash('sha256', $this->body) . '"';
    }
}
