<?php

use LlmsCatalog\Service\LanguageResolver;
use PHPUnit\Framework\TestCase;

final class LanguageResolverTest extends TestCase
{
    public function testFullLocaleIsMatchedCaseInsensitively(): void
    {
        $resolver = new LanguageResolver();
        $language = ['id_lang' => 4, 'locale' => 'fr-FR', 'iso_code' => 'fr'];
        self::assertSame($language, $resolver->matchRequestedLanguage([$language], 'fr-fr'));
    }

    public function testUniqueIsoMatchesAndAmbiguousIsoIsRejected(): void
    {
        $resolver = new LanguageResolver();
        $fr = ['id_lang' => 4, 'locale' => 'fr-FR', 'iso_code' => 'fr'];
        $enUs = ['id_lang' => 5, 'locale' => 'en-US', 'iso_code' => 'en'];
        $enGb = ['id_lang' => 6, 'locale' => 'en-GB', 'iso_code' => 'en'];
        self::assertSame($fr, $resolver->matchRequestedLanguage([$fr, $enUs], 'FR'));
        self::assertNull($resolver->matchRequestedLanguage([$fr, $enUs, $enGb], 'en'));
    }

    public function testUnknownOrShopUnassignedLanguageIsRejected(): void
    {
        $resolver = new LanguageResolver();
        $activeForShop = [['id_lang' => 4, 'locale' => 'fr-FR', 'iso_code' => 'fr']];
        self::assertNull($resolver->matchRequestedLanguage($activeForShop, 'de-DE'));
    }

    public function testMissingFullLocaleUsesLanguageCodeThenIsoCompatibilityFallback(): void
    {
        $resolver = new LanguageResolver();
        self::assertSame('fr-FR', $resolver->canonicalLocaleForRow(['locale' => '', 'language_code' => 'fr-FR', 'iso_code' => 'fr']));
        self::assertSame('fr', $resolver->canonicalLocaleForRow(['locale' => '', 'language_code' => '', 'iso_code' => 'FR']));
        self::assertSame('', $resolver->canonicalLocaleForRow(['locale' => '', 'language_code' => '', 'iso_code' => 'fr_FR']));
    }

    public function testFullLanguageCodeAliasAndRepeatedLocaleRowsAreUnambiguousByLanguageId(): void
    {
        $resolver = new LanguageResolver();
        $fr = ['id_lang' => 4, 'locale' => 'fr-FR', 'language_code' => 'fr-FR', 'iso_code' => 'fr'];
        self::assertSame($fr, $resolver->matchRequestedLanguage([$fr], 'FR-fr'));
        self::assertSame($fr, $resolver->matchRequestedLanguage([$fr], 'fr-FR'));
    }

    public function testLocaleSpellingIsCanonicalizedForLanguageScriptAndRegion(): void
    {
        $resolver = new LanguageResolver();
        self::assertSame('zh-Hant-TW', $resolver->normalizeLocale('ZH-hant-tw'));
        self::assertSame('ar-SA', $resolver->normalizeLocale('AR-sa'));
        self::assertSame('', $resolver->normalizeLocale('fr'));
        self::assertSame('', $resolver->normalizeLocale('fr_XX'));
    }
}
