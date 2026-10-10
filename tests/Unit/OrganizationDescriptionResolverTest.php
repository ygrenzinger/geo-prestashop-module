<?php

use LlmsCatalog\Service\OrganizationDescriptionResolver;
use PHPUnit\Framework\TestCase;

final class OrganizationDescriptionResolverTest extends TestCase
{
    public function testUsesLocalizedDescriptionWhenAvailable(): void
    {
        self::assertSame(
            'Localized description',
            (new OrganizationDescriptionResolver())->resolve('  Localized description  ', 'Default description')
        );
    }

    public function testFallsBackToDefaultLanguageDescription(): void
    {
        self::assertSame(
            'Default description',
            (new OrganizationDescriptionResolver())->resolve('  ', ' Default description ')
        );
    }

    public function testOmitsDescriptionWhenBothLanguagesAreBlank(): void
    {
        self::assertNull((new OrganizationDescriptionResolver())->resolve('', '  '));
    }
}
