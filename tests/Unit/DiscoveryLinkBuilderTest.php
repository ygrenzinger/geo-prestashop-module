<?php

use LlmsCatalog\Service\DiscoveryLinkBuilder;
use PHPUnit\Framework\TestCase;

final class DiscoveryLinkBuilderTest extends TestCase
{
    public function testProductRelationsSerializeAlternateBeforeDescribedbyInHtmlAndHttp(): void
    {
        $builder = new DiscoveryLinkBuilder();
        $links = [
            ['rel' => 'alternate', 'type' => 'text/markdown', 'href' => 'https://shop.example/fr-FR/llms/product/2/a%26b.md'],
            ['rel' => 'describedby', 'href' => 'https://shop.example/fr-FR/llms.txt'],
        ];
        self::assertSame(
            '<link rel="alternate" type="text/markdown" href="https://shop.example/fr-FR/llms/product/2/a%26b.md"><link rel="describedby" href="https://shop.example/fr-FR/llms.txt">',
            $builder->html($links)
        );
        self::assertSame([
            '<https://shop.example/fr-FR/llms/product/2/a%26b.md>; rel="alternate"; type="text/markdown"',
            '<https://shop.example/fr-FR/llms.txt>; rel="describedby"',
        ], $builder->http($links));
    }

    public function testHtmlEscapesUrlsAndHttpRejectsControlCharacters(): void
    {
        $builder = new DiscoveryLinkBuilder();
        $links = [
            ['rel' => 'describedby', 'href' => 'https://shop.example/a?x=1&y="2"'],
            ['rel' => 'describedby', 'href' => "https://shop.example/\r\nInjected: yes"],
        ];
        self::assertStringContainsString('x=1&amp;y=&quot;2&quot;', $builder->html([$links[0]]));
        self::assertSame(['<https://shop.example/a?x=1&y="2">; rel="describedby"'], $builder->http($links));
    }
}
