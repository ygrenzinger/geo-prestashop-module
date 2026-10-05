<?php

namespace LlmsCatalog\Service;

/** Pure URL classification helpers shared by front controllers and URL building. */
final class CanonicalRequestResolver
{
    private const ROUTING_QUERY_KEYS = [
        'fc', 'module', 'controller', 'llms_locale', 'llms_lang', 'isolang',
        'id_lang', 'id_product', 'rewrite',
    ];

    public function validEncodedPath(string $rawPath): bool
    {
        if (preg_match('/%(?![A-Fa-f0-9]{2})/', $rawPath) || preg_match('/%(?:2f|5c|00)/i', $rawPath)) return false;
        $path = rawurldecode($rawPath);
        return strpos($path, "\0") === false && preg_match('//u', $path) === 1 && strpos($path, '\\') === false;
    }

    public function canonicalPath(string $rawPath, string $basePath, string $expectedPath): bool
    {
        if (!$this->validEncodedPath($rawPath)) return false;
        $path = rawurldecode($rawPath);
        $basePath = '/' . trim($basePath, '/');
        if ($basePath !== '/' && strpos($path, $basePath) === 0) $path = substr($path, strlen($basePath));
        return $path === $expectedPath;
    }

    public function preservedQuery(array $query): array
    {
        foreach (self::ROUTING_QUERY_KEYS as $key) unset($query[$key]);
        return $query;
    }

    public function validPositiveInteger(string $value): bool
    {
        if (!preg_match('/^[0-9]+$/', $value)) return false;
        $normalized = ltrim($value, '0');
        if ($normalized === '') return false;
        $max = (string) PHP_INT_MAX;
        return strlen($normalized) < strlen($max) || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) <= 0);
    }

}
