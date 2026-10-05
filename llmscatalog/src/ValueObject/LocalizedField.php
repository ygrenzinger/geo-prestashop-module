<?php

namespace LlmsCatalog\ValueObject;

final class LocalizedField
{
    public function __construct(public string $value, public int $sourceLanguageId)
    {
    }
}
