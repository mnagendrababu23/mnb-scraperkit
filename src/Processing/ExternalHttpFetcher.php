<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Processing;

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpResponse;

final class ExternalHttpFetcher
{
    /** @param array<string,string> $headers */
    public function fetch(string $method, string $url, CrawlOptions $options, array $headers = []): HttpResponse
    {
        return match (strtolower($method)) {
            'cmd-curl', 'external-curl', 'curl-bin', 'curl.exe' => $this->fetchWithCurlBinary($url, $options, $headers, 'cmd-curl'),
            'powershell', 'pwsh', 'ps' => $this->fetchWithPowerShell($url, $options, $headers),
            default => new HttpResponse($url, null, 0, [], '', 0, 'Unsupported external fetch method: ' . $method, 0, 0, $method),
        };
    }

    public function isExternalMethod(string $method): bool
    {
        return in_array(strtolower($method), ['cmd-curl', 'external-curl', 'curl-bin', 'curl.exe', 'powershell', 'pwsh', 'ps'], true);
    }

    /** @param array<string,string> $headers */
    private function fetchWithCurlBinary(string $url, CrawlOptions $options, array $headers, string $engine): HttpResponse
    {
        $started = microtime(true);
        $curl = $this->findExecutable(PHP_OS_FAMILY === 'Windows' ? ['curl.exe', 'curl'] : ['curl']);
        if ($curl === null) {
            return new HttpResponse($url, null, 0, [], '', 0, 'curl command was not found in PATH.', 0, 0, $engine);
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'mnbcurl_');
        if ($tmpBase === false) {
            return new HttpResponse($url, null, 0, [], '', 0, 'Unable to create temp file for external curl.', 0, 0, $engine);
        }
        $bodyFile = $tmpBase . '.body';
        $headerFile = $tmpBase . '.headers';
        @unlink($tmpBase);

        $requestHeaders = array_merge($this->defaultHeaders($options), $options->requestHeaders, $headers);
        $cmd = [
            $curl,
            '--location',
            '--silent',
            '--show-error',
            '--max-redirs', (string) $options->maxRedirects,
            '--connect-timeout', (string) min(10, $options->timeoutSeconds),
            '--max-time', (string) $options->timeoutSeconds,
            '--dump-header', $headerFile,
            '--output', $bodyFile,
            '--write-out', "\nMNB_CURL_META:%{http_code}|%{url_effective}|%{time_total}|%{num_redirects}\n",
        ];
        if (!$options->verifySsl) {
            $cmd[] = '--insecure';
        }
        foreach ($requestHeaders as $name => $value) {
            $cmd[] = '--header';
            $cmd[] = trim((string) $name) . ': ' . trim((string) $value);
        }
        $cmd[] = $url;

        [$stdout, $stderr, $exitCode] = $this->runProcess($cmd, $options->timeoutSeconds + 5);
        $rawHeaders = is_file($headerFile) ? (string) file_get_contents($headerFile) : '';
        $body = is_file($bodyFile) ? (string) file_get_contents($bodyFile) : '';
        @unlink($headerFile);
        @unlink($bodyFile);

        [$statusCode, $finalUrl, $redirectCount, $responseTimeMs] = $this->parseCurlMeta($stdout, $url, (int) round((microtime(true) - $started) * 1000));
        [$parsedStatus, $headersOut, $redirectHistory] = $this->parseCurlHeaders($rawHeaders);
        if ($statusCode === 0 && $parsedStatus > 0) {
            $statusCode = $parsedStatus;
        }
        $error = $exitCode === 0 ? null : (trim($stderr) ?: 'curl command failed with exit code ' . $exitCode);
        [$body, $error] = $this->applyResponseLimit($body, $options, $error);

        return new HttpResponse($url, $finalUrl, $statusCode, $headersOut, $body, $responseTimeMs, $error, $redirectCount, $exitCode, $engine, $redirectHistory);
    }

    /** @param array<string,string> $headers */
    private function fetchWithPowerShell(string $url, CrawlOptions $options, array $headers): HttpResponse
    {
        $started = microtime(true);
        $powershell = $this->findExecutable(PHP_OS_FAMILY === 'Windows' ? ['powershell.exe', 'pwsh.exe', 'pwsh'] : ['pwsh', 'powershell']);
        if ($powershell === null) {
            return new HttpResponse($url, null, 0, [], '', 0, 'PowerShell executable was not found in PATH.', 0, 0, 'powershell');
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'mnbps_');
        if ($tmpBase === false) {
            return new HttpResponse($url, null, 0, [], '', 0, 'Unable to create temp file for PowerShell fetch.', 0, 0, 'powershell');
        }
        $bodyFile = $tmpBase . '.body';
        $metaFile = $tmpBase . '.json';
        @unlink($tmpBase);

        $requestHeaders = array_merge($this->defaultHeaders($options), $options->requestHeaders, $headers);
        $headerLiteral = '@{}';
        if ($requestHeaders !== []) {
            $pairs = [];
            foreach ($requestHeaders as $name => $value) {
                $pairs[] = $this->psQuote((string) $name) . '=' . $this->psQuote((string) $value);
            }
            $headerLiteral = '@{' . implode(';', $pairs) . '}';
        }

        $script = '$ErrorActionPreference="Stop";'
            . '$h=' . $headerLiteral . ';'
            . '$u=' . $this->psQuote($url) . ';'
            . '$body=' . $this->psQuote($bodyFile) . ';'
            . '$meta=' . $this->psQuote($metaFile) . ';'
            . '$sw=[Diagnostics.Stopwatch]::StartNew();'
            . 'try {'
            . '$r=Invoke-WebRequest -Uri $u -Headers $h -MaximumRedirection ' . (int) $options->maxRedirects . ' -TimeoutSec ' . (int) $options->timeoutSeconds . ' -UseBasicParsing -OutFile $body -PassThru;'
            . '$sw.Stop();'
            . '$headers=@{}; foreach($k in $r.Headers.Keys){$headers[$k]=$r.Headers[$k]};'
            . '$o=[ordered]@{status_code=[int]$r.StatusCode; final_url=[string]$r.BaseResponse.ResponseUri; elapsed_ms=[int]$sw.ElapsedMilliseconds; headers=$headers; error=$null};'
            . '$o | ConvertTo-Json -Depth 6 | Set-Content -Encoding UTF8 $meta; exit 0;'
            . '} catch {'
            . '$sw.Stop();'
            . '$resp=$_.Exception.Response; $code=0; $final=$u; $headers=@{};'
            . 'if($resp){try{$code=[int]$resp.StatusCode}catch{}; try{$final=[string]$resp.ResponseUri}catch{}; try{foreach($k in $resp.Headers.Keys){$headers[$k]=$resp.Headers[$k]}}catch{}};'
            . '$o=[ordered]@{status_code=$code; final_url=$final; elapsed_ms=[int]$sw.ElapsedMilliseconds; headers=$headers; error=[string]$_.Exception.Message};'
            . '$o | ConvertTo-Json -Depth 6 | Set-Content -Encoding UTF8 $meta; exit 2;'
            . '}';

        [$stdout, $stderr, $exitCode] = $this->runProcess([$powershell, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $script], $options->timeoutSeconds + 10);
        $meta = is_file($metaFile) ? json_decode((string) file_get_contents($metaFile), true) : [];
        $body = is_file($bodyFile) ? (string) file_get_contents($bodyFile) : '';
        @unlink($bodyFile);
        @unlink($metaFile);
        if (!is_array($meta)) {
            $meta = [];
        }
        $headersOut = [];
        foreach ((array) ($meta['headers'] ?? []) as $k => $v) {
            $headersOut[strtolower((string) $k)] = is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v;
        }
        $statusCode = (int) ($meta['status_code'] ?? 0);
        $finalUrl = isset($meta['final_url']) ? (string) $meta['final_url'] : $url;
        $responseTimeMs = (int) ($meta['elapsed_ms'] ?? round((microtime(true) - $started) * 1000));
        $error = isset($meta['error']) && $meta['error'] !== null ? (string) $meta['error'] : null;
        if ($exitCode !== 0 && !$error) {
            $error = trim($stderr) ?: 'PowerShell fetch failed with exit code ' . $exitCode;
        }
        [$body, $error] = $this->applyResponseLimit($body, $options, $error);

        return new HttpResponse($url, $finalUrl, $statusCode, $headersOut, $body, $responseTimeMs, $error, 0, $exitCode, 'powershell');
    }

    /** @param array<int,string> $cmd @return array{0:string,1:string,2:int} */
    private function runProcess(array $cmd, int $timeoutSeconds): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptor, $pipes);
        if (!is_resource($process)) {
            return ['', 'Unable to start process.', 127];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $start = time();
        $timedOut = false;
        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            if (!$status['running']) {
                break;
            }
            if ($timeoutSeconds > 0 && (time() - $start) > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(100000);
        }
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($timedOut) {
            return [$stdout, 'Process timed out after ' . $timeoutSeconds . ' seconds. ' . $stderr, 124];
        }
        return [$stdout, $stderr, is_int($exitCode) ? $exitCode : 1];
    }

    /** @param array<int,string> $names */
    private function findExecutable(array $names): ?string
    {
        foreach ($names as $name) {
            if (str_contains($name, DIRECTORY_SEPARATOR) && is_file($name)) {
                return $name;
            }
            $cmd = PHP_OS_FAMILY === 'Windows' ? ['where', $name] : ['sh', '-lc', 'command -v ' . escapeshellarg($name)];
            [$stdout, , $exit] = $this->runProcess($cmd, 3);
            if ($exit === 0) {
                $path = trim(strtok($stdout, "\r\n") ?: '');
                if ($path !== '') {
                    return $path;
                }
            }
        }
        return null;
    }

    /** @return array<string,string> */
    private function defaultHeaders(CrawlOptions $options): array
    {
        return [
            'User-Agent' => $options->userAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
        ];
    }

    /** @return array{0:int,1:?string,2:int,3:int} */
    private function parseCurlMeta(string $stdout, string $url, int $fallbackMs): array
    {
        if (preg_match('~MNB_CURL_META:(\d{3})\|([^|]*)\|([0-9.]+)\|(\d+)~', $stdout, $m) === 1) {
            return [(int) $m[1], $m[2] !== '' ? $m[2] : $url, (int) $m[4], (int) round(((float) $m[3]) * 1000)];
        }
        return [0, $url, 0, $fallbackMs];
    }

    /** @return array{0:int,1:array<string,mixed>,2:array<int,array<string,mixed>>} */
    private function parseCurlHeaders(string $raw): array
    {
        $blocks = preg_split("~\r?\n\r?\n~", trim($raw));
        $headers = [];
        $history = [];
        $lastStatus = 0;
        foreach ($blocks ?: [] as $block) {
            $lines = preg_split("~\r?\n~", trim($block));
            if (!$lines || $lines === ['']) {
                continue;
            }
            $current = [];
            $statusLine = array_shift($lines);
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string) $statusLine, $m) === 1) {
                $lastStatus = (int) $m[1];
                $current['_status_code'] = $lastStatus;
                $current['_status_line'] = (string) $statusLine;
            }
            foreach ($lines as $line) {
                if (!str_contains($line, ':')) {
                    continue;
                }
                [$name, $value] = explode(':', $line, 2);
                $key = strtolower(trim($name));
                $value = trim($value);
                if (isset($current[$key])) {
                    if (!is_array($current[$key])) {
                        $current[$key] = [$current[$key]];
                    }
                    $current[$key][] = $value;
                } else {
                    $current[$key] = $value;
                }
            }
            if ($headers !== []) {
                $history[] = [
                    'status_code' => $headers['_status_code'] ?? null,
                    'location' => $headers['location'] ?? null,
                ];
            }
            $headers = $current;
        }
        return [$lastStatus, $headers, $history];
    }

    /** @return array{0:string,1:?string} */
    private function applyResponseLimit(string $body, CrawlOptions $options, ?string $error): array
    {
        if ($options->maxResponseBytes > 0 && strlen($body) > $options->maxResponseBytes) {
            return [substr($body, 0, $options->maxResponseBytes), trim((string) $error . ' Response truncated at max_response_bytes.')];
        }
        return [$body, $error];
    }

    private function psQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
