<?php

use LlmsCatalog\ValueObject\PublicDocument;
use PHPUnit\Framework\TestCase;

final class PublicDocumentTest extends TestCase
{
    public function testStrongEtagIsSha256OfExactBody(): void
    {
        $document = new PublicDocument("catalog\n", 'text/plain', 1);
        self::assertSame('"sha256-' . hash('sha256', "catalog\n") . '"', $document->etag());
    }
}
