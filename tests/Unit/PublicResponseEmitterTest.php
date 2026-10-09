<?php

use LlmsCatalog\Service\PublicResponseEmitter;
use PHPUnit\Framework\TestCase;

final class PublicResponseEmitterTest extends TestCase
{
    public function testIfNoneMatchSupportsListsWildcardAndWeakGetComparison(): void
    {
        $emitter = new PublicResponseEmitter();
        $matches = new ReflectionMethod($emitter, 'ifNoneMatch');
        self::assertTrue($matches->invoke($emitter, '"other", "sha256"', '"sha256"'));
        self::assertTrue($matches->invoke($emitter, '*', '"sha256"'));
        self::assertTrue($matches->invoke($emitter, 'W/"sha256"', '"sha256"'));
        self::assertFalse($matches->invoke($emitter, '"other"', '"sha256"'));
    }

    public function testIfModifiedSinceUsesWholeSecondLastModified(): void
    {
        $emitter = new PublicResponseEmitter();
        $method = new ReflectionMethod($emitter, 'ifModifiedSince');
        self::assertTrue($method->invoke($emitter, gmdate('D, d M Y H:i:s', 1000) . ' GMT', 1000));
        self::assertFalse($method->invoke($emitter, 'invalid', 1000));
    }
}
