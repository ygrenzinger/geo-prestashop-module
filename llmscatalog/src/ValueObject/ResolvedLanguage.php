<?php

namespace LlmsCatalog\ValueObject;

final class ResolvedLanguage
{
    public function __construct(
        public int $shopId,
        public int $languageId,
        public string $locale,
        public string $isoCode,
        public int $defaultLanguageId,
        public string $defaultLocale,
        public bool $needsCanonicalRedirect,
        public bool $fallbackPossible
    ) {
    }
}
