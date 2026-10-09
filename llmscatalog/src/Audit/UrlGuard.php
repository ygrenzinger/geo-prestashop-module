<?php
namespace LlmsCatalog\Audit;

/** Restricts audit fetch targets and rejects literal/private/reserved IP addresses. */
final class UrlGuard
{
    private $resolver;
    public function __construct(?callable $resolver=null) { $this->resolver=$resolver; }
    public function authorize(string $url, array $allowedHosts): bool
    {
        if (strlen($url) > 2048) return false;
        $parts = parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
        $host = strtolower(rtrim($parts['host'], '.'));
        if (filter_var($host, FILTER_VALIDATE_IP)) return false;
        foreach (array_merge($allowedHosts, []) as $allowed) {
            $allowed = strtolower(rtrim((string) $allowed, '.'));
            if ($host === $allowed) return $this->publicAddresses($host) !== [];
        }
        return false;
    }

    public function publicAddresses(string $host): array
    {
        $records = $this->resolver ? ($this->resolver)($host) : @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || !$records) return [];
        $addresses=[];
        foreach ($records as $record) foreach (['ip','ipv6'] as $key) if (!empty($record[$key])) $addresses[]=$record[$key];
        if (!$addresses) return [];
        foreach ($addresses as $address) if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [];
        return array_values(array_unique($addresses));
    }
}
