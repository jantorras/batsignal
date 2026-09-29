<?php
namespace BatSignal\Checks;

use BatSignal\Database;
use BatSignal\Msg;
use BatSignal\PageInspector;

/**
 * "All pages" check.
 *
 * Pages discovered (sitemap + internal links) are remembered per check in crawl_pages,
 * and each run inspects a budget of them: the start page, then pages that failed last
 * time, then never-checked and least-recently-checked pages. Big sites are covered in
 * turns across runs; small sites are fully covered every run.
 *
 * Severity, so one bad page never makes a working site look "down":
 *  - real page errors (5xx, PHP errors, blank page) → "degraded", or "down" only when
 *    at least half of the pages (and 3+) fail;
 *  - broken links (4xx) and slow pages → "warning".
 * Timeouts, connection errors and 5xx are retried once before being counted, and a
 * page that still times out counts as slow, not broken.
 */
class CrawlCheck
{
    private const CONCURRENCY = 5;
    private const MAX_REPORTED = 15;
    private const MAX_KNOWN_PAGES = 20000;
    private const SITEMAP_DISCOVERY_LIMIT = 5000;
    private const FORGET_AFTER_DAYS = 14;
    // A 404 that nothing links to any more is forgotten instead of being reported forever.
    private const STALE_BROKEN_DAYS = 3;
    private const SKIP_EXTENSIONS = '/\.(jpe?g|png|gif|webp|avif|svg|ico|bmp|pdf|zip|rar|7z|gz|tar|docx?|xlsx?|pptx?|odt|ods|csv|mp[34]|webm|avi|mov|css|js|json|xml|txt|woff2?|ttf|eot|otf)$/i';
    // Never follow links that could change state or that aren't public pages.
    private const SKIP_PATHS = '#(logout|log-out|signout|sign-out|/wp-admin|wp-login\.php|xmlrpc\.php|/feed/?$|/cdn-cgi/)#i';

    /** @param callable|null $onProgress fn(int $checked, int $estimate, string $path, ?array $problemMsg, int $ms, string $category) */
    public static function run(int $checkId, string $url, array $config, ?callable $onProgress = null): array
    {
        $budget = max(1, min(500, (int)($config['max_pages'] ?? 100)));
        $timeout = max(3, (int)($config['timeout_seconds'] ?? 30));
        $slowMs = (int)($config['slow_ms'] ?? 8000);
        $custom = $config['forbidden_keywords'] ?? [];
        $db = Database::get();

        $start = self::normalize($url, false);
        if ($start === null) {
            return self::result('down', null, 'crawl_pages_failing', Msg::make('msg.crawl_bad_url', ['url' => $url]));
        }
        $host = self::hostKey($start);

        $db->prepare('DELETE FROM crawl_pages WHERE check_id = ? AND last_seen_at < NOW() - INTERVAL ' . self::FORGET_AFTER_DAYS . ' DAY')
            ->execute([$checkId]);

        $stmt = $db->prepare('SELECT url_hash FROM crawl_pages WHERE check_id = ?');
        $stmt->execute([$checkId]);
        $known = array_fill_keys($stmt->fetchAll(\PDO::FETCH_COLUMN), true);

        self::remember($checkId, [$start => null], $known);
        $fromSitemap = [];
        foreach (self::sitemapUrls($start, $timeout, self::SITEMAP_DISCOVERY_LIMIT) as $u) {
            if (self::hostKey($u) === $host) {
                $fromSitemap[$u] = null;
            }
        }
        self::remember($checkId, $fromSitemap, $known);

        // This run's worklist: start page, last run's failures, then never/least recently checked.
        $stmt = $db->prepare(
            "SELECT url, found_on, last_seen_at FROM crawl_pages WHERE check_id = ?
             ORDER BY (url_hash = ?) DESC, COALESCE(last_category IN ('error','broken'), 0) DESC,
                      last_checked_at IS NOT NULL, last_checked_at ASC
             LIMIT {$budget}"
        );
        $stmt->execute([$checkId, sha1($start)]);
        $queue = [];
        $meta = [];
        foreach ($stmt->fetchAll() as $row) {
            $queue[] = $row['url'];
            $meta[$row['url']] = ['from' => $row['found_on'], 'seen' => $row['last_seen_at']];
        }
        $queued = array_fill_keys($queue, true);

        $checked = 0;
        $totalMs = 0;
        $results = []; // url => [category, ?Msg, retryable]

        while ($queue && $checked < $budget) {
            $batch = array_splice($queue, 0, min(self::CONCURRENCY, $budget - $checked));
            $inFlight = count($batch);
            foreach (self::fetchMany($batch, $timeout) as $pageUrl => $res) {
                $checked++;
                $inFlight--;
                $totalMs += $res['ms'];

                if ($pageUrl === $start && $res['effective_url']) {
                    // Follow the home page's final host (e.g. example.com -> www.example.com).
                    $host = self::hostKey($res['effective_url']) ?: $host;
                }

                $results[$pageUrl] = self::evaluate($res, $timeout, $slowMs, $custom);

                $isHtml = $res['content_type'] === '' || stripos($res['content_type'], 'html') !== false;
                if ($res['status'] >= 200 && $res['status'] < 300 && $isHtml) {
                    $links = [];
                    $here = mb_substr(self::shortPath($pageUrl), 0, 1000);
                    foreach (self::extractLinks($res['body'], $res['effective_url'] ?: $pageUrl) as $link) {
                        if (self::hostKey($link) === $host) {
                            $links[$link] = $here;
                        }
                    }
                    foreach (self::remember($checkId, $links, $known) as $new) {
                        // Brand-new pages are checked in this same run while there's budget left.
                        if (!isset($queued[$new])) {
                            $queued[$new] = true;
                            $queue[] = $new;
                            $meta[$new] = ['from' => $links[$new], 'seen' => date('Y-m-d H:i:s')];
                        }
                    }
                    foreach ($links as $link => $from) {
                        if (isset($meta[$link])) {
                            $meta[$link]['from'] = $from;
                            $meta[$link]['seen'] = date('Y-m-d H:i:s');
                        }
                    }
                }

                if ($onProgress) {
                    [$cat, $msg] = $results[$pageUrl];
                    $onProgress($checked, min($budget, $checked + $inFlight + count($queue)), self::shortPath($pageUrl), $cat === 'ok' ? null : $msg, $res['ms'], $cat);
                }
            }
        }

        // One retry for transient failures (slow servers, hiccups, 502/503 from proxies).
        $retry = array_keys(array_filter($results, fn($r) => $r[2]));
        if ($retry) {
            sleep(2);
            foreach (array_chunk($retry, self::CONCURRENCY) as $chunk) {
                foreach (self::fetchMany($chunk, $timeout) as $u => $res) {
                    $results[$u] = self::evaluate($res, $timeout, $slowMs, $custom);
                }
            }
        }

        $stale = time() - self::STALE_BROKEN_DAYS * 86400;
        $update = $db->prepare('UPDATE crawl_pages SET last_checked_at = NOW(), last_category = ?, last_problem = ? WHERE check_id = ? AND url_hash = ?');
        $forget = $db->prepare('DELETE FROM crawl_pages WHERE check_id = ? AND url_hash = ?');
        $by = ['error' => [], 'broken' => [], 'slow' => []];
        foreach ($results as $u => [$cat, $msg]) {
            if ($cat === 'timeout') {
                $cat = 'slow'; // still timing out after the retry: very slow, not broken
            }
            if ($cat === 'broken' && $u !== $start && strtotime($meta[$u]['seen'] ?? 'now') < $stale) {
                $forget->execute([$checkId, sha1($u)]);
                continue;
            }
            $update->execute([$cat, Msg::encode($msg), $checkId, sha1($u)]);
            if (isset($by[$cat])) {
                $by[$cat][$u] = $msg;
            }
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM crawl_pages WHERE check_id = ?');
        $stmt->execute([$checkId]);
        $knownCount = (int)$stmt->fetchColumn();
        $coverage = $knownCount > $checked
            ? Msg::make('msg.crawl_coverage', ['known' => $knownCount, 'cycles' => (int)ceil($knownCount / $budget)])
            : '';
        $avgMs = $checked ? (int)round($totalMs / $checked) : null;

        if (!$by['error'] && !$by['broken'] && !$by['slow']) {
            return self::result('ok', $avgMs, null, Msg::make('msg.crawl_all_ok', ['checked' => $checked, 'coverage' => $coverage]));
        }

        $parts = [];
        foreach (['error' => 'msg.part_errors', 'broken' => 'msg.part_broken', 'slow' => 'msg.part_slow'] as $cat => $key) {
            if ($by[$cat]) {
                $parts[] = Msg::make($key, ['n' => count($by[$cat])]);
            }
        }
        $msg = Msg::make('msg.crawl_issues', ['checked' => $checked, 'parts' => $parts, 'coverage' => $coverage]);

        $items = [];
        foreach (['error', 'broken', 'slow'] as $cat) {
            foreach ($by[$cat] as $u => $m) {
                $item = ['path' => self::shortPath($u), 'm' => $m];
                if ($cat === 'broken' && !empty($meta[$u]['from'])) {
                    $item['from'] = $meta[$u]['from'];
                }
                $items[] = $item;
            }
        }
        $msg['items'] = array_slice($items, 0, self::MAX_REPORTED);
        $msg['more'] = max(0, count($items) - self::MAX_REPORTED);

        if ($by['error']) {
            $errors = count($by['error']);
            $status = ($errors >= 3 && $errors / max(1, $checked) >= 0.5) ? 'down' : 'degraded';
            return self::result($status, $avgMs, 'crawl_pages_failing', $msg);
        }
        return self::result('warning', $avgMs, $by['broken'] ? 'crawl_broken_links' : 'crawl_pages_slow', $msg);
    }

    /**
     * Upserts pages (url => path of the page linking to it, or null) and returns the
     * URLs that were not known before. Known pages get last_seen_at refreshed.
     */
    private static function remember(int $checkId, array $urls, array &$known): array
    {
        $new = [];
        $rows = [];
        foreach ($urls as $u => $from) {
            $hash = sha1($u);
            if (!isset($known[$hash])) {
                if (count($known) >= self::MAX_KNOWN_PAGES) {
                    continue;
                }
                $known[$hash] = true;
                $new[] = $u;
            }
            array_push($rows, $checkId, $hash, $u, $from);
        }
        foreach (array_chunk($rows, 4 * 300) as $chunk) {
            $sql = 'INSERT INTO crawl_pages (check_id, url_hash, url, found_on) VALUES '
                . implode(',', array_fill(0, intdiv(count($chunk), 4), '(?,?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE last_seen_at = NOW(), found_on = COALESCE(VALUES(found_on), found_on)';
            Database::get()->prepare($sql)->execute($chunk);
        }
        return $new;
    }

    private static function result(string $status, ?int $ms, ?string $reason, array $detail): array
    {
        return [
            'status' => $status,
            'http_status' => null,
            'response_time_ms' => $ms,
            'reason_code' => $reason,
            'detail' => $detail,
        ];
    }

    /** @return array{0:string, 1:?array, 2:bool} [category, problem Msg, retryable] */
    private static function evaluate(array $res, int $timeout, int $slowMs, array $custom): array
    {
        if ($res['errno'] !== 0) {
            return $res['errno'] === CURLE_OPERATION_TIMEDOUT
                ? ['timeout', Msg::make('msg.page_timeout', ['s' => $timeout]), true]
                : ['error', Msg::make('msg.page_connection', ['error' => $res['error']]), true];
        }
        if ($res['status'] === 0 || $res['status'] >= 500) {
            return ['error', Msg::make('msg.page_http', ['code' => $res['status']]), true];
        }
        if ($res['status'] >= 400) {
            return in_array($res['status'], [404, 410], true)
                ? ['broken', Msg::make('msg.page_http_404'), false]
                : ['broken', Msg::make('msg.page_http', ['code' => $res['status']]), false];
        }
        if ($res['content_type'] === '' || stripos($res['content_type'], 'html') !== false) {
            $problem = PageInspector::findProblem($res['body'], $custom);
            if ($problem !== null) {
                return ['error', $problem['detail'], false];
            }
        }
        if ($res['ms'] >= $slowMs) {
            return ['slow', Msg::make('msg.page_slow', ['ms' => $res['ms']]), false];
        }
        return ['ok', null, false];
    }

    /** @return array<string, array{status:int, ms:int, errno:int, error:string, body:string, content_type:string, effective_url:string}> */
    private static function fetchMany(array $urls, int $timeout): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'BatSignal-Monitor/1.0 (crawler)',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$u] = $ch;
        }

        $codes = [];
        do {
            $status = curl_multi_exec($mh, $running);
            while ($info = curl_multi_info_read($mh)) {
                $codes[spl_object_id($info['handle'])] = $info['result'];
            }
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        while ($info = curl_multi_info_read($mh)) {
            $codes[spl_object_id($info['handle'])] = $info['result'];
        }

        $out = [];
        foreach ($handles as $u => $ch) {
            $errno = $codes[spl_object_id($ch)] ?? 0;
            $out[$u] = [
                'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'ms' => (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
                'errno' => $errno,
                'error' => $errno ? curl_strerror($errno) : '',
                'body' => (string)curl_multi_getcontent($ch),
                'content_type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                'effective_url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private static function sitemapUrls(string $start, int $timeout, int $limit): array
    {
        $p = parse_url($start);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

        $maps = [];
        $robots = self::fetchMany(["{$origin}/robots.txt"], $timeout)["{$origin}/robots.txt"];
        if ($robots['status'] === 200 && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $robots['body'], $m)) {
            $maps = $m[1];
        }
        if (!$maps) {
            $maps = ["{$origin}/sitemap.xml", "{$origin}/sitemap_index.xml", "{$origin}/wp-sitemap.xml"];
        }

        $pages = [];
        $visited = [];
        while ($maps && count($visited) < 50 && count($pages) < $limit) {
            $map = array_shift($maps);
            if (isset($visited[$map]) || str_ends_with(strtolower($map), '.gz')) {
                continue;
            }
            $visited[$map] = true;
            $res = self::fetchMany([$map], $timeout)[$map];
            if ($res['status'] !== 200 || stripos($res['body'], '<loc') === false) {
                continue;
            }
            $isIndex = stripos($res['body'], '<sitemapindex') !== false;
            preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</loc>#is', $res['body'], $m);
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode(trim($loc), ENT_QUOTES | ENT_XML1);
                if ($isIndex) {
                    $maps[] = $loc;
                    continue;
                }
                $n = self::normalize($loc, false);
                if ($n !== null && self::isPage($n)) {
                    $pages[$n] = true;
                    if (count($pages) >= $limit) {
                        break;
                    }
                }
            }
            if ($pages && !$isIndex) {
                // First sitemap with pages found; skip fallback guesses.
                $maps = array_values(array_filter($maps, fn($x) => !in_array($x, ["{$origin}/sitemap_index.xml", "{$origin}/wp-sitemap.xml"], true)));
            }
        }
        return array_keys($pages);
    }

    private static function extractLinks(string $html, string $base): array
    {
        if (!preg_match_all('/<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            return [];
        }
        $links = [];
        foreach ($m[2] as $href) {
            $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
            if ($href === '' || $href[0] === '#' || preg_match('/^(mailto|tel|javascript|data|whatsapp|sms):/i', $href)) {
                continue;
            }
            $abs = self::resolve($base, $href);
            $n = $abs ? self::normalize($abs, true) : null;
            if ($n !== null && self::isPage($n)) {
                $links[$n] = true;
            }
        }
        return array_keys($links);
    }

    private static function isPage(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        return !preg_match(self::SKIP_EXTENSIONS, $path) && !preg_match(self::SKIP_PATHS, $path);
    }

    // Discovered links drop their query string so faceted/filter URLs can't explode the crawl.
    private static function normalize(string $url, bool $stripQuery): ?string
    {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        $out = strtolower($p['scheme']) . '://' . strtolower($p['host']);
        if (isset($p['port'])) {
            $out .= ':' . $p['port'];
        }
        $out .= $p['path'] ?? '/';
        if (!$stripQuery && isset($p['query']) && $p['query'] !== '') {
            $out .= '?' . $p['query'];
        }
        return $out;
    }

    private static function resolve(string $base, string $href): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (!$b || empty($b['host'])) {
            return null;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $b['scheme'] . ':' . $href;
        }
        if ($href[0] === '?') {
            return $origin . ($b['path'] ?? '/') . $href;
        }
        if ($href[0] === '/') {
            $path = $href;
        } else {
            $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
            $path = $dir . $href;
        }
        $query = '';
        if (($q = strpos($path, '?')) !== false) {
            $query = substr($path, $q);
            $path = substr($path, 0, $q);
        }
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }
        $path = '/' . ltrim(implode('/', $segments), '/');
        return $origin . $path . $query;
    }

    private static function hostKey(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)));
    }

    private static function shortPath(string $url): string
    {
        $p = parse_url($url);
        return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }
}
