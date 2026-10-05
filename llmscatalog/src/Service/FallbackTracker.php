<?php

namespace LlmsCatalog\Service;

use LlmsCatalog\ValueObject\LocalizedField;
use LlmsCatalog\ValueObject\ResolvedLanguage;

final class FallbackTracker
{
    private bool $used = false;

    public function observe(LocalizedField $field, int $requestedLanguageId): void
    {
        if (trim($field->value) !== '' && $field->sourceLanguageId > 0 && $field->sourceLanguageId !== $requestedLanguageId) {
            $this->used = true;
        }
    }

    public function observeConfiguredFallback(bool $used): void
    {
        $this->used = $this->used || $used;
    }

    public function hasFallback(): bool
    {
        return $this->used;
    }

    public function disclosure(TranslationResolver $translations, ResolvedLanguage $language): string
    {
        return $translations->shop('Some catalog content is shown in %fallback_locale% because it is unavailable in %requested_locale%.', [
            '%fallback_locale%' => $language->defaultLocale,
            '%requested_locale%' => $language->locale,
        ]);
    }
}
