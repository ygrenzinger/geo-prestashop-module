<?php

namespace LlmsCatalog\Service;

/** Resolves public Organization contact details into Schema.org values. */
final class OrganizationDetailsResolver
{
    public function contactPoint(string $email, string $phone): ?array
    {
        $email = trim($email);
        $phone = trim($phone);
        $validEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : null;

        if ($validEmail === null && $phone === '') {
            return null;
        }

        $contactPoint = [
            '@type' => 'ContactPoint',
            'contactType' => 'customer service',
        ];
        if ($validEmail !== null) {
            $contactPoint['email'] = $validEmail;
        }
        if ($phone !== '') {
            $contactPoint['telephone'] = $phone;
        }

        return $contactPoint;
    }

    public function postalAddress(array $fields): ?array
    {
        $address = ['@type' => 'PostalAddress'];
        foreach ([
            'streetAddress',
            'postalCode',
            'addressLocality',
            'addressRegion',
        ] as $field) {
            $value = trim((string) ($fields[$field] ?? ''));
            if ($value !== '') {
                $address[$field] = $value;
            }
        }

        $country = strtoupper(trim((string) ($fields['addressCountry'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/D', $country) === 1) {
            $address['addressCountry'] = $country;
        }

        return count($address) > 1 ? $address : null;
    }
}
