<?php

namespace LlmsCatalog\Service;

/**
 * Applies PrestaShop's "do not write cookies" policy to machine-readable public endpoints.
 *
 * PrestaShop 9 exposes Cookie::disallowWriting() as an instance method, so it must be
 * called on the context cookie. Calling it statically (which PrestaShop 1.7/8 tolerated)
 * is a fatal error on 9.x.
 */
final class CookiePolicy
{
    public static function disallowWriting(): void
    {
        $cookie = \Context::getContext()->cookie ?? null;
        if ($cookie instanceof \Cookie) {
            $cookie->disallowWriting();
        }
    }
}
