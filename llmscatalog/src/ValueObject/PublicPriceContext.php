<?php

namespace LlmsCatalog\ValueObject;

/** Immutable representation-affecting inputs for an anonymous public offer. */
final class PublicPriceContext
{
    private array $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function get(string $name)
    {
        return $this->values[$name] ?? null;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
