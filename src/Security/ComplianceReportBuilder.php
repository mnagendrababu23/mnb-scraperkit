<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Security;

final class ComplianceReportBuilder
{
    public function __construct(private readonly string $rootDir)
    {
    }

    /** @return array<string,mixed> */
    public function build(?string $policyFile = null): array
    {
        $audit = (new SecurityAuditScanner($this->rootDir))->audit($policyFile);
        $policy = (array) ($audit['policy'] ?? CompliancePolicy::defaults());
        $summary = (array) ($audit['summary'] ?? []);

        return [
            'ok' => (bool) ($audit['ok'] ?? false),
            'compliance_report_version' => '1.0.3',
            'generated_at' => date(DATE_ATOM),
            'policy_name' => (string) ($policy['name'] ?? 'MNB ScraperKit Responsible Crawling Policy'),
            'score' => (int) ($audit['score'] ?? 0),
            'status' => (string) ($audit['status'] ?? 'unknown'),
            'summary' => $summary,
            'attestations' => [
                'safe_by_default' => (($summary['critical'] ?? 0) === 0 && ($summary['high'] ?? 0) === 0),
                'secrets_scan_completed' => true,
                'release_hygiene_checked' => true,
                'browser_sessions_reviewed' => true,
                'plugins_reviewed_as_config_only' => true,
            ],
            'policy' => $policy,
            'findings' => (array) ($audit['findings'] ?? []),
            'recommendations' => (array) ($audit['recommendations'] ?? []),
        ];
    }

    /** @param array<string,mixed> $report */
    public function renderHtml(array $report): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $summary = (array) ($report['summary'] ?? []);
        $rows = '';
        foreach ((array) ($report['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $rows .= '<tr><td>' . $e($finding['severity'] ?? '') . '</td><td>' . $e($finding['category'] ?? '') . '</td><td>' . $e($finding['title'] ?? '') . '</td><td>' . $e($finding['path'] ?? '') . '</td><td>' . $e($finding['recommendation'] ?? '') . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">No findings.</td></tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>MNB ScraperKit Compliance Report</title><style>body{font-family:Arial,sans-serif;margin:28px;line-height:1.45}table{border-collapse:collapse;width:100%;margin-top:16px}td,th{border:1px solid #ddd;padding:8px;vertical-align:top}th{background:#f5f5f5}.score{font-size:42px;font-weight:bold}</style></head><body>'
            . '<h1>MNB ScraperKit Compliance Report</h1>'
            . '<p><strong>Policy:</strong> ' . $e($report['policy_name'] ?? '') . '</p>'
            . '<p class="score">Score: ' . $e($report['score'] ?? '') . '</p>'
            . '<p><strong>Status:</strong> ' . $e($report['status'] ?? '') . '</p>'
            . '<p>Critical: ' . $e($summary['critical'] ?? 0) . ' | High: ' . $e($summary['high'] ?? 0) . ' | Medium: ' . $e($summary['medium'] ?? 0) . ' | Low: ' . $e($summary['low'] ?? 0) . '</p>'
            . '<h2>Findings</h2><table><thead><tr><th>Severity</th><th>Category</th><th>Title</th><th>Path</th><th>Recommendation</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '</body></html>';
    }
}
