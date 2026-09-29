<?php
namespace BatSignal\Checks;

use BatSignal\Msg;
use BatSignal\PageInspector;

/**
 * HTTP check for a single URL: status code, response time, required text, and
 * page-content problems (PHP errors, blank page) via PageInspector.
 *
 * Built for slow sites too: a timeout, connection error or 5xx is retried once
 * before being reported, and slowness is only ever a warning — "down" means the
 * page really didn't answer or is broken.
 */
class HttpCheck
{
    private const RETRY_DELAY_SECONDS = 3;

    public static function run(string $url, array $config): array
    {
        $expectedStatus = (int)($config['expected_status'] ?? 200);
        $timeout = (int)($config['timeout_seconds'] ?? 30);
        $required = trim((string)($config['required_keyword'] ?? ''));
        $forbidden = $config['forbidden_keywords'] ?? [];
        $warnMs = (int)($config['warn_ms'] ?? 3000);
        $criticalMs = (int)($config['critical_ms'] ?? 10000);

        $r = self::fetch($url, $timeout);
        if ($r['errno'] !== 0 || $r['status'] === 0 || $r['status'] >= 500) {
            sleep(self::RETRY_DELAY_SECONDS);
            $r = self::fetch($url, $timeout);
        }
        ['body' => $body, 'ms' => $responseTimeMs, 'errno' => $errno, 'error' => $error, 'status' => $httpStatus] = $r;

        if ($errno !== 0) {
            $reason = $errno === CURLE_OPERATION_TIMEDOUT ? 'http_timeout' : 'http_connection_error';
            return [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => $reason,
                'detail' => Msg::make('msg.curl_error', ['errno' => $errno, 'error' => $error]),
            ];
        }

        if ($httpStatus !== $expectedStatus) {
            return [
                'status' => 'down',
                'http_status' => $httpStatus,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'http_status_mismatch',
                'detail' => Msg::make('msg.status_mismatch', ['expected' => $expectedStatus, 'got' => $httpStatus]),
            ];
        }

        $problem = PageInspector::findProblem($body, $forbidden);
        if ($problem !== null) {
            return [
                'status' => 'down',
                'http_status' => $httpStatus,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => $problem['reason_code'],
                'detail' => Msg::make('msg.http_but', ['status' => $httpStatus, 'problem' => $problem['detail']]),
            ];
        }

        if ($required !== '' && stripos($body, $required) === false) {
            return [
                'status' => 'down',
                'http_status' => $httpStatus,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'required_keyword_missing',
                'detail' => Msg::make('msg.required_missing', ['text' => $required]),
            ];
        }

        if ($responseTimeMs >= $criticalMs) {
            return [
                'status' => 'warning',
                'http_status' => $httpStatus,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'response_time_critical',
                'detail' => Msg::make('msg.slow_critical', ['ms' => $responseTimeMs, 'limit' => $criticalMs]),
            ];
        }

        if ($responseTimeMs >= $warnMs) {
            return [
                'status' => 'warning',
                'http_status' => $httpStatus,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'response_time_warning',
                'detail' => Msg::make('msg.slow_warning', ['ms' => $responseTimeMs, 'limit' => $warnMs]),
            ];
        }

        return [
            'status' => 'ok',
            'http_status' => $httpStatus,
            'response_time_ms' => $responseTimeMs,
            'reason_code' => null,
            'detail' => null,
        ];
    }

    private static function fetch(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 15),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'BatSignal-Monitor/1.0',
        ]);
        $start = microtime(true);
        $body = curl_exec($ch);
        $result = [
            'body' => (string)$body,
            'ms' => (int)round((microtime(true) - $start) * 1000),
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        ];
        curl_close($ch);
        return $result;
    }
}
