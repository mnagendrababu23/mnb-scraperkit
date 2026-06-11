<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

final class RobotsPolicy
{
    /** @var array<string,array{body:string,status:int,error:?string}> */
    private array $robotsCache = [];

    public function __construct(private HttpClient $client)
    {
    }

    public function isAllowed(string $url, CrawlOptions $options): bool
    {
        return $this->inspect($url, $options)->allowed;
    }

    public function inspect(string $url, CrawlOptions $options): RobotsDecision
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';

        if (!$host) {
            return new RobotsDecision(
                allowed: false,
                url: $url,
                robotsUrl: '',
                userAgent: $options->userAgent,
                reason: 'Invalid URL host; robots.txt could not be evaluated.',
            );
        }

        $origin = strtolower($scheme . '://' . $host);
        $robotsUrl = $origin . '/robots.txt';

        if (!$options->respectRobots) {
            return new RobotsDecision(
                allowed: true,
                url: $url,
                robotsUrl: $robotsUrl,
                userAgent: $options->userAgent,
                reason: 'Robots policy disabled in crawl options. Use this only for owned sites or explicitly authorized crawls.',
            );
        }

        $robots = $this->fetchRobots($robotsUrl, $options);
        if ($robots['error']) {
            return new RobotsDecision(
                allowed: true,
                url: $url,
                robotsUrl: $robotsUrl,
                userAgent: $options->userAgent,
                reason: 'Robots file could not be read; continuing because no enforceable rules were loaded.',
                robotsFetched: true,
                robotsStatusCode: $robots['status'],
                error: $robots['error'],
            );
        }

        if ($robots['status'] >= 400 || trim($robots['body']) === '') {
            return new RobotsDecision(
                allowed: true,
                url: $url,
                robotsUrl: $robotsUrl,
                userAgent: $options->userAgent,
                reason: 'No usable robots.txt rules found for this origin.',
                robotsFetched: true,
                robotsStatusCode: $robots['status'],
            );
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }

        $rules = $this->matchingRulesForUserAgent($robots['body'], $options->userAgent);
        $winner = null;

        foreach ($rules as $rule) {
            if ($rule['pattern'] === '') {
                continue;
            }
            if (!$this->ruleMatchesPath($rule['pattern'], $path)) {
                continue;
            }
            $length = strlen(str_replace(['*', '$'], '', $rule['pattern']));
            if ($winner === null || $length > $winner['length'] || ($length === $winner['length'] && $rule['directive'] === 'allow')) {
                $winner = $rule + ['length' => $length];
            }
        }

        if ($winner === null) {
            return new RobotsDecision(
                allowed: true,
                url: $url,
                robotsUrl: $robotsUrl,
                userAgent: $options->userAgent,
                reason: 'No matching robots.txt rule blocked this URL.',
                robotsFetched: true,
                robotsStatusCode: $robots['status'],
            );
        }

        $allowed = $winner['directive'] === 'allow';

        return new RobotsDecision(
            allowed: $allowed,
            url: $url,
            robotsUrl: $robotsUrl,
            userAgent: $options->userAgent,
            matchedDirective: ucfirst($winner['directive']),
            matchedRule: $winner['pattern'],
            matchedLine: $winner['line'],
            reason: $allowed
                ? 'Allowed by the most specific robots.txt rule.'
                : 'Blocked by the most specific robots.txt rule. Use an official API, sitemap/metadata endpoint, permission-based crawl, or a different allowed URL.',
            robotsFetched: true,
            robotsStatusCode: $robots['status'],
        );
    }

    /** @return array{body:string,status:int,error:?string} */
    private function fetchRobots(string $robotsUrl, CrawlOptions $options): array
    {
        if (!array_key_exists($robotsUrl, $this->robotsCache)) {
            $response = $this->client->get($robotsUrl, $options);
            $this->robotsCache[$robotsUrl] = [
                'body' => $response->body,
                'status' => $response->statusCode,
                'error' => $response->error,
            ];
        }

        return $this->robotsCache[$robotsUrl];
    }

    /**
     * @return array<int,array{directive:string,pattern:string,line:int}>
     */
    private function matchingRulesForUserAgent(string $robotsBody, string $userAgent): array
    {
        $groups = [];
        $currentAgents = [];
        $currentRules = [];
        $lineNo = 0;

        foreach (preg_split('/\R/', $robotsBody) ?: [] as $rawLine) {
            $lineNo++;
            $line = trim(preg_replace('/#.*/', '', $rawLine) ?? '');
            if ($line === '') {
                if ($currentAgents || $currentRules) {
                    $groups[] = ['agents' => $currentAgents, 'rules' => $currentRules];
                }
                $currentAgents = [];
                $currentRules = [];
                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $name = strtolower($name);

            if ($name === 'user-agent') {
                if ($currentRules) {
                    $groups[] = ['agents' => $currentAgents, 'rules' => $currentRules];
                    $currentAgents = [];
                    $currentRules = [];
                }
                $currentAgents[] = strtolower($value);
                continue;
            }

            if (($name === 'allow' || $name === 'disallow') && $currentAgents) {
                $currentRules[] = [
                    'directive' => $name,
                    'pattern' => $value,
                    'line' => $lineNo,
                ];
            }
        }

        if ($currentAgents || $currentRules) {
            $groups[] = ['agents' => $currentAgents, 'rules' => $currentRules];
        }

        $ua = strtolower($this->userAgentProductToken($userAgent));
        $specificRules = [];
        $wildcardRules = [];

        foreach ($groups as $group) {
            $agents = $group['agents'];
            $rules = $group['rules'];
            if (!$agents || !$rules) {
                continue;
            }

            foreach ($agents as $agent) {
                if ($agent === '*') {
                    $wildcardRules = array_merge($wildcardRules, $rules);
                    continue;
                }
                if ($agent !== '' && str_contains($ua, $agent)) {
                    $specificRules = array_merge($specificRules, $rules);
                }
            }
        }

        return $specificRules ?: $wildcardRules;
    }

    private function userAgentProductToken(string $userAgent): string
    {
        $token = trim(explode(' ', $userAgent)[0] ?? $userAgent);
        return trim(explode('/', $token)[0] ?? $token) ?: $userAgent;
    }

    private function ruleMatchesPath(string $pattern, string $path): bool
    {
        if ($pattern === '') {
            return false;
        }

        $anchoredToEnd = str_ends_with($pattern, '$');
        if ($anchoredToEnd) {
            $pattern = substr($pattern, 0, -1);
        }

        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\\*', '.*', $regex);
        $regex = '#^' . $regex . ($anchoredToEnd ? '$' : '') . '#u';

        return preg_match($regex, $path) === 1;
    }
}
