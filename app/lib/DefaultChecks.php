<?php
namespace BatSignal;

/**
 * The monitoring set every site gets out of the box. Adding a site should mean
 * "watch everything" without the user having to know which checks exist.
 */
class DefaultChecks
{
    public static function definitions(string $baseUrl): array
    {
        $defs = [
            'http' => [
                'name' => I18n::t('default.http'),
                'type' => 'http',
                'url' => $baseUrl,
                'frequency_minutes' => 2,
                'failure_threshold' => 2,
                'config' => ['expected_status' => 200, 'timeout_seconds' => 30, 'warn_ms' => 3000, 'critical_ms' => 10000],
            ],
            'crawl' => [
                'name' => I18n::t('default.crawl'),
                'type' => 'crawl',
                'url' => $baseUrl,
                'frequency_minutes' => 30,
                'failure_threshold' => 2,
                'config' => ['max_pages' => 100, 'timeout_seconds' => 30, 'slow_ms' => 8000],
            ],
        ];

        if (strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)) === 'https') {
            $defs['ssl'] = [
                'name' => I18n::t('default.ssl'),
                'type' => 'ssl',
                'url' => $baseUrl,
                'frequency_minutes' => 360,
                'failure_threshold' => 1,
                'config' => ['warn_days' => 30, 'critical_days' => 14],
            ];
        }
        return $defs;
    }

    /** Types from the default set that the site doesn't have yet. */
    public static function missing(int $siteId, string $baseUrl): array
    {
        $stmt = Database::get()->prepare('SELECT DISTINCT type FROM checks WHERE site_id = ?');
        $stmt->execute([$siteId]);
        $existing = array_flip($stmt->fetchAll(\PDO::FETCH_COLUMN));
        return array_diff_key(self::definitions($baseUrl), $existing);
    }

    public static function createMissing(int $siteId, string $baseUrl): int
    {
        $insert = Database::get()->prepare(
            'INSERT INTO checks (site_id, name, type, url, config, frequency_minutes, failure_threshold) VALUES (?,?,?,?,?,?,?)'
        );
        $missing = self::missing($siteId, $baseUrl);
        foreach ($missing as $d) {
            $insert->execute([$siteId, $d['name'], $d['type'], $d['url'], json_encode($d['config']), $d['frequency_minutes'], $d['failure_threshold']]);
        }
        return count($missing);
    }
}
