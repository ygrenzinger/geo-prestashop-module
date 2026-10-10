<?php

namespace LlmsCatalog\Service;

/** Resolves a shop description for the current or default language. */
final class OrganizationDescriptionResolver
{
    public function resolve(string $localizedDescription, string $defaultDescription): ?string
    {
        $description = trim($localizedDescription);
        if ($description === '') {
            $description = trim($defaultDescription);
        }

        return $description !== '' ? $description : null;
    }
}
