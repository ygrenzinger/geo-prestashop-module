<?php

use LlmsCatalog\Service\RootIndexGenerator;
use PHPUnit\Framework\TestCase;

final class RootIndexGeneratorTest extends TestCase
{
    public function testDirectoryPutsDefaultFirstThenSortsByLocaleAndLanguageId(): void
    {
        $body = RootIndexGenerator::renderDirectory('Example Shop', [
            ['id' => 7, 'locale' => 'fr-FR', 'label' => 'Français', 'url' => 'https://example.test/fr-FR/llms.txt', 'default' => false],
            ['id' => 4, 'locale' => 'en-US', 'label' => 'English', 'url' => 'https://example.test/en-US/llms.txt', 'default' => true],
            ['id' => 8, 'locale' => 'de-DE', 'label' => 'Deutsch', 'url' => 'https://example.test/de-DE/llms.txt', 'default' => false],
        ]);
        self::assertLessThan(strpos($body, 'de-DE'), strpos($body, 'en-US'));
        self::assertLessThan(strpos($body, 'fr-FR'), strpos($body, 'de-DE'));
        self::assertStringNotContainsString('Products', $body);
    }

    public function testShopAndLanguageLabelsCannotInjectMarkdownStructure(): void
    {
        $body = RootIndexGenerator::renderDirectory('# Shop [x]', [
            ['id' => 1, 'locale' => 'en-US', 'label' => 'English ](https://evil.test)', 'url' => 'https://example.test/en-US/llms.txt', 'default' => true],
        ]);
        self::assertStringContainsString('# \\# Shop \\[x\\]', $body);
        self::assertStringContainsString('English \\](https://evil.test\\)', $body);
    }
}
