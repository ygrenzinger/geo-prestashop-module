<?php

use LlmsCatalog\Service\TranslationResolver;
use PHPUnit\Framework\TestCase;

final class TranslationResolverTest extends TestCase
{
    public function testWordingFallsBackFromRequestedToShopDefaultThenEnglish(): void
    {
        $catalogues = ['fr-FR' => ['In stock' => 'En stock'], 'en-US' => []];
        $module = new class($catalogues) {
            public array $calls = [];
            public function __construct(private array $catalogues) {}
            public function trans(string $message, array $parameters, string $domain, string $locale): string
            {
                $this->calls[] = [$message, $locale, $domain];
                return $this->catalogues[$locale][$message] ?? $message;
            }
        };
        $resolver = new TranslationResolver($module, 'fr-CA', 'fr-FR');
        self::assertSame('En stock', $resolver->shop('In stock'));
        self::assertSame([['In stock', 'fr-CA', 'Modules.Llmscatalog.Shop'], ['In stock', 'fr-FR', 'Modules.Llmscatalog.Shop']], $module->calls);
    }

    public function testEnglishSourceWordingIsFinalFallback(): void
    {
        $module = new class {
            public array $calls = [];
            public function trans(string $message, array $parameters, string $domain, string $locale): string
            {
                $this->calls[] = $locale;
                return $message;
            }
        };
        $resolver = new TranslationResolver($module, 'pt-BR', 'fr-FR');
        self::assertSame('Out of stock', $resolver->shop('Out of stock'));
        self::assertSame(['pt-BR', 'fr-FR', 'en-US'], $module->calls);
    }

    public function testRequestedEnglishSourceDoesNotFallThroughToNonEnglishShopDefault(): void
    {
        $module = new class {
            public array $calls = [];
            public function trans(string $message, array $parameters, string $domain, string $locale): string
            {
                $this->calls[] = $locale;
                return $locale === 'fr-FR' ? 'En stock' : $message;
            }
        };
        $resolver = new TranslationResolver($module, 'en-US', 'fr-FR');
        self::assertSame('In stock', $resolver->shop('In stock'));
        self::assertSame(['en-US'], $module->calls);
    }
}
