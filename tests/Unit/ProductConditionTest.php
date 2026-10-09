<?php

use LlmsCatalog\Service\ProductCondition;
use PHPUnit\Framework\TestCase;

final class ProductConditionTest extends TestCase
{
    private const CORE_VALUES = ['new', 'used', 'refurbished', 'open_box', 'damaged', 'new_with_defects'];

    public function testAllowedValuesComeFromProvidedCoreDefinition(): void
    {
        self::assertSame(self::CORE_VALUES, ProductCondition::allowed(self::CORE_VALUES));
    }

    public function testAllowedFallsBackToNewWhenCoreDefinitionIsEmpty(): void
    {
        self::assertSame(['new'], ProductCondition::allowed([]));
    }

    public function testAllowedReturnsANonEmptyListWithoutCoreRuntime(): void
    {
        // In a bootstrapped PrestaShop shop this reads the core definition; in a
        // standalone unit run it must still return a safe, non-empty list.
        self::assertNotSame([], ProductCondition::allowed());
    }

    public function testIsKnownAcceptsProvidedCoreValuesOnly(): void
    {
        self::assertTrue(ProductCondition::isKnown('open_box', self::CORE_VALUES));
        self::assertFalse(ProductCondition::isKnown('brand_new', self::CORE_VALUES));
    }

    public function testLabelHumanizesUnderscoredValues(): void
    {
        self::assertSame('New', ProductCondition::label('new'));
        self::assertSame('Open box', ProductCondition::label('open_box'));
        self::assertSame('New with defects', ProductCondition::label('new_with_defects'));
    }

    public function testSchemaOrgItemConditionMapsKnownValues(): void
    {
        self::assertSame('https://schema.org/NewCondition', ProductCondition::schemaOrgItemCondition('new'));
        self::assertSame('https://schema.org/NewCondition', ProductCondition::schemaOrgItemCondition('new_with_defects'));
        self::assertSame('https://schema.org/RefurbishedCondition', ProductCondition::schemaOrgItemCondition('refurbished'));
        self::assertSame('https://schema.org/UsedCondition', ProductCondition::schemaOrgItemCondition('used'));
        self::assertSame('https://schema.org/UsedCondition', ProductCondition::schemaOrgItemCondition('open_box'));
        self::assertSame('https://schema.org/UsedCondition', ProductCondition::schemaOrgItemCondition('damaged'));
    }

    public function testSchemaOrgItemConditionFallsBackToNewForUnknownValues(): void
    {
        self::assertSame('https://schema.org/NewCondition', ProductCondition::schemaOrgItemCondition('some_future_value'));
    }

    public function testSchemaOrgItemConditionIsNullWhenEmpty(): void
    {
        self::assertNull(ProductCondition::schemaOrgItemCondition(''));
    }
}
