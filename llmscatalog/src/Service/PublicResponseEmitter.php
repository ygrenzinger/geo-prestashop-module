<?php

namespace LlmsCatalog\Service;

use LlmsCatalog\ValueObject\PublicDocument;

/** Emits conditional GET/HEAD responses for deterministic public documents. */
class PublicResponseEmitter
{
    public function emit(PublicDocument $document): void
    {
        CookiePolicy::disallowWriting();
        header_remove('Set-Cookie');
        $status = $document->status; $headers = $document->headers;
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($document->languageId > 0) header('Content-Language: ' . $this->locale($document->languageId));
        if ($status === 301) {
            header('Cache-Control: public, max-age=300');
        } elseif ($status === 200 && $document->cacheable) {
            $remaining = max(0, $document->originExpiry - time()); $etag = $document->etag();
            $generated = $document->generatedAt > 0 ? $document->generatedAt : time();
            $lastModified = gmdate('D, d M Y H:i:s', $generated) . ' GMT';
            header('Cache-Control: public, max-age=0, s-maxage=' . $remaining . ', must-revalidate, no-transform');
            header('ETag: ' . $etag); if ($document->lastModifiedEnabled) header('Last-Modified: ' . $lastModified); header('Vary: Accept-Encoding');
            if ($this->ifNoneMatch((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), $etag)) $status = 304;
            elseif ($document->lastModifiedEnabled && !isset($_SERVER['HTTP_IF_NONE_MATCH']) && $this->ifModifiedSince((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''), $generated)) $status = 304;
        } else {
            header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
        }
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $name => $value) foreach ((array) $value as $field) header($name . ': ' . $field, false);
        if ($status === 304) header_remove('Content-Type');
        else header('Content-Type: ' . $document->contentType . '; charset=UTF-8');
        http_response_code($status);
        if ($method !== 'HEAD' && $status !== 304) echo $document->body;
    }

    private function ifNoneMatch(string $header, string $etag): bool
    {
        if (trim($header) === '') return false;
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') return true;
            if (strncmp($candidate, 'W/', 2) === 0) $candidate = trim(substr($candidate, 2));
            if (hash_equals($etag, $candidate)) return true;
        }
        return false;
    }

    private function ifModifiedSince(string $header, int $generated): bool
    { $time = $header === '' ? false : strtotime($header); return $time !== false && $time >= $generated; }

    private function locale(int $languageId): string
    { $language = new \Language($languageId); return (new LanguageResolver())->normalizeLocale((string) $language->locale) ?: (string) $language->iso_code; }
}
