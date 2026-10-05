<?php
namespace LlmsCatalog\Audit;

/** Stable severity totals shared by the AJAX and downloadable report shapes. */
final class AuditSummary
{
    public static function fromFindings(array $findings): array
    {
        $counts = ['blocker' => 0, 'high' => 0, 'medium' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $severity = is_array($finding) ? ($finding['severity'] ?? 'info') : 'info';
            if (isset($counts[$severity])) {
                ++$counts[$severity];
            }
        }
        return $counts;
    }
}
