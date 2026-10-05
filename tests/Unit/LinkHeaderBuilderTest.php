<?php

use LlmsCatalog\Service\LinkHeaderBuilder;
use LlmsCatalog\Service\UrlBuilder;
use PHPUnit\Framework\TestCase;

final class LinkHeaderBuilderTest extends TestCase
{
    public function testIndexRelationsAreOrderedAndDefaultLanguageIsLast(): void
    {
        $urls = $this->createMock(UrlBuilder::class);
        $urls->method('indexUrl')->willReturnMap([
            [1, 'https://shop.example/fr-FR/llms.txt'],
            [2, 'https://shop.example/en-US/llms.txt'],
        ]);
        $urls->method('localeAlternates')->willReturn(['en-US' => 2, 'fr-FR' => 1]);
        $urls->method('defaultLanguageId')->willReturn(2);
        $urls->method('canonicalLocale')->with(2)->willReturn('en-US');

        $headers = (new LinkHeaderBuilder())->index($urls, 1)['Link'];
        self::assertSame([
            '<https://shop.example/fr-FR/llms.txt>; rel="canonical"',
            '<https://shop.example/en-US/llms.txt>; rel="alternate"; hreflang="en-US"; type="text/plain"',
            '<https://shop.example/fr-FR/llms.txt>; rel="alternate"; hreflang="fr-FR"; type="text/plain"',
            '<https://shop.example/en-US/llms.txt>; rel="alternate"; hreflang="x-default"; type="text/plain"',
        ], $headers);
    }
}
