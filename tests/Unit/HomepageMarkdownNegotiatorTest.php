<?php

use LlmsCatalog\Service\HomepageMarkdownNegotiator;
use PHPUnit\Framework\TestCase;

final class HomepageMarkdownNegotiatorTest extends TestCase
{
    public function testExplicitMarkdownRequestSelectsMarkdown(): void
    {
        self::assertTrue(HomepageMarkdownNegotiator::wantsMarkdown('text/markdown'));
        self::assertTrue(HomepageMarkdownNegotiator::wantsMarkdown('text/html;q=0.2, text/markdown;q=0.9'));
        self::assertTrue(HomepageMarkdownNegotiator::wantsMarkdown('TEXT/MARKDOWN; charset=UTF-8'));
    }

    public function testHtmlOrUnacceptableMarkdownKeepsHtml(): void
    {
        self::assertFalse(HomepageMarkdownNegotiator::wantsMarkdown('text/html'));
        self::assertFalse(HomepageMarkdownNegotiator::wantsMarkdown('*/*'));
        self::assertFalse(HomepageMarkdownNegotiator::wantsMarkdown('text/markdown;q=0'));
        self::assertFalse(HomepageMarkdownNegotiator::wantsMarkdown('text/html, text/markdown'));
        self::assertFalse(HomepageMarkdownNegotiator::wantsMarkdown('text/html;q=0.8, text/markdown;q=0.4'));
        self::assertFalse(HomepageMarkdownNegotiator::wantsMarkdown('text/markdown;q=0.5, text/*;q=0.8'));
        self::assertFalse(HomepageMarkdownNegotiator::wantsMarkdown('text/markdown;q=0.5, */*;q=0.8'));
    }
}
