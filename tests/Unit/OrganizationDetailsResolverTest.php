<?php

use LlmsCatalog\Service\OrganizationDetailsResolver;
use PHPUnit\Framework\TestCase;

final class OrganizationDetailsResolverTest extends TestCase
{
    public function testBuildsCustomerServiceContactPointFromConfiguredDetails(): void
    {
        $contactPoint = (new OrganizationDetailsResolver())->contactPoint(
            ' support@example.com ',
            ' +33 1 23 45 67 89 '
        );

        self::assertSame([
            '@type' => 'ContactPoint',
            'contactType' => 'customer service',
            'email' => 'support@example.com',
            'telephone' => '+33 1 23 45 67 89',
        ], $contactPoint);
    }

    public function testOmitsInvalidEmailAndContactPointWithoutUsableDetails(): void
    {
        $resolver = new OrganizationDetailsResolver();

        self::assertSame([
            '@type' => 'ContactPoint',
            'contactType' => 'customer service',
            'telephone' => '123',
        ], $resolver->contactPoint('invalid email', '123'));
        self::assertNull($resolver->contactPoint('invalid email', '  '));
    }

    public function testBuildsPostalAddressFromOnlyConfiguredFields(): void
    {
        $address = (new OrganizationDetailsResolver())->postalAddress([
            'streetAddress' => '  4 Rue du Test, Bâtiment B  ',
            'postalCode' => ' 75001 ',
            'addressLocality' => ' Paris ',
            'addressRegion' => '',
            'addressCountry' => 'fr',
        ]);

        self::assertSame([
            '@type' => 'PostalAddress',
            'streetAddress' => '4 Rue du Test, Bâtiment B',
            'postalCode' => '75001',
            'addressLocality' => 'Paris',
            'addressCountry' => 'FR',
        ], $address);
    }

    public function testOmitsPostalAddressWhenNoAddressFieldsAreConfigured(): void
    {
        self::assertNull((new OrganizationDetailsResolver())->postalAddress([
            'streetAddress' => '',
            'postalCode' => '',
            'addressLocality' => '',
            'addressRegion' => '',
            'addressCountry' => 'invalid',
        ]));
    }
}
