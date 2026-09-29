<?php
namespace BatSignal\Checks;

use BatSignal\Msg;

/**
 * SSL certificate check: validity and days until expiry.
 */
class SslCheck
{
    public static function run(string $url, array $config): array
    {
        $warnDays = (int)($config['warn_days'] ?? 15);
        $criticalDays = (int)($config['critical_days'] ?? 3);

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?: 443;

        if (!$host) {
            return [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => null,
                'reason_code' => 'ssl_connection_error',
                'detail' => Msg::make('msg.ssl_bad_url', ['url' => $url]),
            ];
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $start = microtime(true);
        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
        $responseTimeMs = (int)round((microtime(true) - $start) * 1000);

        if (!$client) {
            return [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'ssl_connection_error',
                'detail' => Msg::make('msg.ssl_connect', ['host' => $host, 'port' => $port, 'error' => $errstr]),
            ];
        }

        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;

        if (!$cert) {
            return [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'ssl_invalid',
                'detail' => Msg::make('msg.ssl_no_cert', ['host' => $host]),
            ];
        }

        $certData = openssl_x509_parse($cert);
        if (!$certData) {
            return [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'ssl_invalid',
                'detail' => Msg::make('msg.ssl_unparseable', ['host' => $host]),
            ];
        }

        $validTo = $certData['validTo_time_t'] ?? null;
        $commonName = $certData['subject']['CN'] ?? '';
        $altNames = $certData['extensions']['subjectAltName'] ?? '';

        $domainMatches = self::hostMatches($host, $commonName, $altNames);
        if (!$domainMatches) {
            return [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'ssl_invalid',
                'detail' => Msg::make('msg.ssl_mismatch', ['cn' => $commonName, 'host' => $host]),
            ];
        }

        if ($validTo === null) {
            return [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'ssl_invalid',
                'detail' => Msg::make('msg.ssl_no_expiry', ['host' => $host]),
            ];
        }

        $daysLeft = (int)floor(($validTo - time()) / 86400);

        if ($daysLeft < 0) {
            return [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'ssl_expired',
                'detail' => Msg::make('msg.ssl_expired', ['host' => $host, 'days' => abs($daysLeft)]),
            ];
        }

        if ($daysLeft <= $criticalDays) {
            return [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'ssl_expiring_soon',
                'detail' => Msg::make('msg.ssl_expiring', ['host' => $host, 'days' => $daysLeft]),
            ];
        }

        if ($daysLeft <= $warnDays) {
            return [
                'status' => 'warning',
                'http_status' => null,
                'response_time_ms' => $responseTimeMs,
                'reason_code' => 'ssl_expiring_soon',
                'detail' => Msg::make('msg.ssl_expiring', ['host' => $host, 'days' => $daysLeft]),
            ];
        }

        return [
            'status' => 'ok',
            'http_status' => null,
            'response_time_ms' => $responseTimeMs,
            'reason_code' => null,
            'detail' => Msg::make('msg.ssl_ok', ['days' => $daysLeft]),
        ];
    }

    private static function hostMatches(string $host, string $commonName, string $altNames): bool
    {
        $candidates = [$commonName];
        foreach (explode(',', $altNames) as $entry) {
            $entry = trim($entry);
            if (stripos($entry, 'DNS:') === 0) {
                $candidates[] = substr($entry, 4);
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $host) {
                return true;
            }
            // Wildcard match, e.g. *.example.com
            if (strpos($candidate, '*.') === 0) {
                $suffix = substr($candidate, 1); // ".example.com"
                if (str_ends_with($host, $suffix) && substr_count($host, '.') >= substr_count($candidate, '.')) {
                    return true;
                }
            }
        }
        return false;
    }
}
