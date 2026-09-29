<?php
namespace BatSignal;

use BatSignal\Checks\CrawlCheck;
use BatSignal\Checks\HttpCheck;
use BatSignal\Checks\SslCheck;

class CheckRunner
{
    /**
     * Runs one check row, records the result and handles incidents/notifications.
     * $onProgress is forwarded to checks that report partial progress (the crawler).
     */
    public static function runAndRecord(array $check, ?callable $onProgress = null): array
    {
        $config = json_decode((string)$check['config'], true) ?: [];

        try {
            $result = match ($check['type']) {
                'http' => HttpCheck::run($check['url'], $config),
                'ssl' => SslCheck::run($check['url'], $config),
                'crawl' => CrawlCheck::run((int)$check['id'], $check['url'], $config, $onProgress),
            };
        } catch (\Throwable $e) {
            $result = [
                'status' => 'down',
                'http_status' => null,
                'response_time_ms' => null,
                'reason_code' => 'internal_error',
                'detail' => Msg::make('msg.internal_error', ['error' => $e->getMessage()]),
            ];
        }

        IncidentManager::process($check, $result);
        return $result;
    }
}
