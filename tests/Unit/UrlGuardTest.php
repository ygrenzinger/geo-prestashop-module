<?php
use LlmsCatalog\Audit\UrlGuard;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase
{
    public function testRequiresAuthorizedHttpsHostAndRejectsIpLiterals(): void
    {
        $guard = new UrlGuard(static fn($host)=>[['ip'=>'93.184.216.34']]);
        self::assertTrue($guard->authorize('https://shop.example/sitemap.xml', ['shop.example']));
        self::assertFalse($guard->authorize('http://shop.example/a', ['shop.example']));
        self::assertFalse($guard->authorize('https://127.0.0.1/a', ['127.0.0.1']));
        self::assertFalse($guard->authorize('https://evil.example/a', ['shop.example']));
    }
}
