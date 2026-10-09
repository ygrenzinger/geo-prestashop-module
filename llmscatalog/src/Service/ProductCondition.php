<?php

namespace LlmsCatalog\Service;

use Product;

/**
 * Product condition metadata.
 *
 * The list of allowed conditions is read from the core Product definition so
 * this module never duplicates an enum that can change between PrestaShop
 * versions (for example 9.2 added open_box, damaged and new_with_defects).
 */
final class ProductCondition
{
    /** schema.org itemCondition used for core values without an explicit mapping. */
    private const SCHEMA_ORG_FALLBACK = 'NewCondition';

    /**
     * Explicit schema.org mappings. Every other value falls back to
     * SCHEMA_ORG_FALLBACK (the core default), so new core conditions never
     * lose the field.
     *
     * @var array<string, string>
     */
    private const SCHEMA_ORG_MAP = [
        'new' => 'NewCondition',
        'new_with_defects' => 'NewCondition',
        'refurbished' => 'RefurbishedCondition',
        'used' => 'UsedCondition',
        'open_box' => 'UsedCondition',
        'damaged' => 'UsedCondition',
    ];

    /**
     * Allowed condition values, read from the core Product definition.
     *
     * @param string[]|null $coreValues Override the core definition (used by tests)
     *
     * @return string[]
     */
    public static function allowed(?array $coreValues = null): array
    {
        if ($coreValues === null) {
            $coreValues = class_exists('Product')
                ? (Product::$definition['fields']['condition']['values'] ?? null)
                : null;
        }

        return is_array($coreValues) && $coreValues !== [] ? array_values(array_map('strval', $coreValues)) : ['new'];
    }

    public static function isKnown(string $condition, ?array $coreValues = null): bool
    {
        return in_array($condition, self::allowed($coreValues), true);
    }

    /** Human-readable label derived from the stored value. */
    public static function label(string $condition): string
    {
        return ucfirst(str_replace('_', ' ', $condition));
    }

    /** Absolute schema.org itemCondition, or null when there is no condition. */
    public static function schemaOrgItemCondition(string $condition): ?string
    {
        $condition = strtolower(trim($condition));
        if ($condition === '') {
            return null;
        }

        return 'https://schema.org/' . (self::SCHEMA_ORG_MAP[$condition] ?? self::SCHEMA_ORG_FALLBACK);
    }
}
