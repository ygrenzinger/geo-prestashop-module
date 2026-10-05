<?php

use LlmsCatalog\Service\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../llmscatalog/src/Service/MarkdownRenderer.php';

final class MarkdownRendererTest extends TestCase
{
    public function testTextRemovesMarkupAndCollapsesWhitespace(): void
    {
        $renderer = new MarkdownRenderer();

        self::assertSame('A product description', $renderer->text("<strong>A</strong> product\n description"));
    }

    public function testLinkEscapesMarkdownLabelAndUrlCharacters(): void
    {
        $renderer = new MarkdownRenderer();

        self::assertSame(
            '- [A \\[product\\]](https://example.test/a%20product%29): Description',
            $renderer->link('A [product]', 'https://example.test/a product)', 'Description')
        );
    }

    public function testTruncationKeepsAnExplicitNotice(): void
    {
        $renderer = new MarkdownRenderer();

        $result = $renderer->truncate("# Shop\n\n## Products\n\n- [One](https://example.test/one)", 80);

        self::assertStringContainsString('This index is truncated', $result);
    }

    public function testTruncationStaysWithinByteLimitAndPreservesUtf8AndLinks(): void
    {
        $renderer = new MarkdownRenderer();
        $line = '- [東京と é 😀](https://example.test/item): 商品説明';
        $result = $renderer->truncate("# 商品\n\n" . $line . "\n\n" . str_repeat('説明。', 400), 1024, 'Truncated.');
        self::assertLessThanOrEqual(1024, strlen($result));
        self::assertSame(1, preg_match('//u', $result));
        if (strpos($result, '- [東京と é 😀](') !== false) {
            self::assertStringContainsString($line, $result);
        }
    }

    public function testHtmlDescriptionKeepsStructureAndRemovesUnsafeContent(): void
    {
        $renderer = new MarkdownRenderer();
        $result = $renderer->htmlToMarkdown('<h2>Details</h2><p>Good <strong>quality</strong> and <a href="https://example.test/info">details</a>.</p><ul><li>One</li><li>Two</li></ul><script>alert(1)</script><a href="javascript:alert(1)">bad</a>');
        self::assertStringContainsString('### Details', $result);
        self::assertStringContainsString('**quality**', $result);
        self::assertStringContainsString('[details](https://example.test/info)', $result);
        self::assertStringContainsString('- One', $result);
        self::assertStringContainsString('1. First', $renderer->htmlToMarkdown('<ol><li>First</li><li>Second</li></ol>'));
        self::assertStringNotContainsString('alert(1)', $result);
        self::assertStringNotContainsString('<script', $result);
    }

    public function testHtmlDescriptionBudgetKeepsWholeParagraphs(): void
    {
        $renderer = new MarkdownRenderer();
        $result = $renderer->htmlToMarkdown('<p>First paragraph.</p><p>' . str_repeat('Second paragraph. ', 50) . '</p>', 32);
        self::assertLessThanOrEqual(32, strlen($result));
        self::assertStringContainsString('First paragraph.', $result);
        self::assertStringNotContainsString('Second paragraph.', $result);
    }

    public function testSafeTextEscapesMarkdownControlCharacters(): void
    {
        $renderer = new MarkdownRenderer();
        self::assertSame('Heading \\# \\[label\\] \\*bold\\*', $renderer->safeText('Heading # [label] *bold*'));
    }
}
