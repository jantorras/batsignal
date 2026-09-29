<?php

use BatSignal\Msg;

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bat_mark(string $size = ''): string
{
    $class = trim('brand-mark ' . $size);
    return <<<SVG
    <span class="{$class}" aria-hidden="true">
        <svg viewBox="0 0 100 50" xmlns="http://www.w3.org/2000/svg" focusable="false">
            <path fill="#0b0c0f" d="M50 14 L46 6 L45 13 C40 12 36 13 33 16 C30 8 20 3 4 4 C12 9 14 16 12 24 C18 20 24 21 28 26 C31 22 36 22 40 28 C43 32 47 38 50 46 C53 38 57 32 60 28 C64 22 69 22 72 26 C76 21 82 20 88 24 C86 16 88 9 96 4 C80 3 70 8 67 16 C64 13 60 12 55 13 L54 6 Z"/>
        </svg>
    </span>
    SVG;
}

function status_badge(?string $status): string
{
    $map = [
        'ok' => ['status-ok', 'ph-check-circle'],
        'warning' => ['status-warning', 'ph-warning'],
        'degraded' => ['status-degraded', 'ph-warning-octagon'],
        'down' => ['status-down', null],
        'open' => ['status-down', null],
        'closed' => ['status-ok', 'ph-check-circle'],
        'active' => ['status-ok', 'ph-play-circle'],
        'paused' => ['status-unknown', 'ph-pause-circle'],
    ];
    $key = isset($map[$status ?? '']) ? $status : 'unknown';
    [$class, $icon] = $map[$key] ?? ['status-unknown', 'ph-question'];
    $glyph = $icon === null
        ? '<span class="pulse" aria-hidden="true"></span>'
        : '<i class="ph ' . $icon . '" aria-hidden="true"></i>';
    return '<span class="status ' . $class . '">' . $glyph . te('status.' . $key) . '</span>';
}

/** Check name as shown to the reader (auto-created default names follow the UI language). */
function cn(string $name): string
{
    return BatSignal\I18n::checkName($name);
}

function type_label(string $type): string
{
    return in_array($type, ['http', 'ssl', 'crawl'], true) ? t('type.' . $type) : strtoupper($type);
}

function every(int $minutes): string
{
    if ($minutes >= 60 && $minutes % 60 === 0) {
        $h = intdiv($minutes, 60);
        return $h === 1 ? t('every.hour') : t('every.hours', ['n' => $h]);
    }
    return $minutes === 1 ? t('every.minute') : t('every.minutes', ['n' => $minutes]);
}

function describe_check(array $check): string
{
    $cfg = json_decode((string)($check['config'] ?? ''), true) ?: [];
    $freq = ucfirst(every((int)$check['frequency_minutes']));

    switch ($check['type']) {
        case 'crawl':
            return t('describe.crawl', ['freq' => $freq, 'max' => (int)($cfg['max_pages'] ?? 100)]);
        case 'ssl':
            return t('describe.ssl', ['freq' => $freq, 'warn' => (int)($cfg['warn_days'] ?? 30), 'crit' => (int)($cfg['critical_days'] ?? 14)]);
        default:
            $text = t('describe.http', [
                'freq' => $freq,
                'path' => parse_url((string)$check['url'], PHP_URL_PATH) ?: '/',
                'status' => (int)($cfg['expected_status'] ?? 200),
                'secs' => round(((int)($cfg['warn_ms'] ?? 3000)) / 1000, 1),
            ]);
            if (!empty($cfg['required_keyword'])) {
                $text .= t('describe.http_required', ['text' => $cfg['required_keyword']]);
            }
            return $text;
    }
}

/** First line of a stored check detail (Msg JSON or legacy text), in the current language. */
function first_line(?string $stored): string
{
    $text = Msg::render($stored);
    $pos = strpos($text, "\n");
    return $pos === false ? $text : substr($text, 0, $pos);
}

/** Stored detail rendered with its first line as summary and the rest (e.g. broken pages) expandable. */
function detail_block(?string $stored, bool $open = false): string
{
    $text = Msg::render($stored);
    $pos = strpos($text, "\n");
    if ($pos === false) {
        return e($text);
    }
    return '<details' . ($open ? ' open' : '') . '><summary style="cursor:pointer">' . e(substr($text, 0, $pos)) . '</summary>'
        . '<div class="mono mt-2" style="white-space: pre-line; font-size: .8rem;">' . e(substr($text, $pos + 1)) . '</div></details>';
}

function empty_state(string $icon, string $title, string $text, ?string $ctaHref = null, ?string $ctaLabel = null): string
{
    $cta = $ctaHref
        ? '<a class="btn btn-bat" href="' . e($ctaHref) . '"><i class="ph ph-plus" aria-hidden="true"></i>' . e($ctaLabel) . '</a>'
        : '';
    return '<div class="empty-state"><div class="empty-icon"><i class="ph ' . e($icon) . '" aria-hidden="true"></i></div>'
        . '<h3>' . e($title) . '</h3><p>' . e($text) . '</p>' . $cta . '</div>';
}

const GROUP_COLORS = ['yellow', 'orange', 'red', 'pink', 'purple', 'blue', 'teal', 'green'];

function group_chip(array $group, bool $link = true): string
{
    $color = in_array($group['color'] ?? '', GROUP_COLORS, true) ? $group['color'] : 'yellow';
    $inner = '<span class="dot" aria-hidden="true"></span>' . e($group['name']);
    return $link
        ? '<a class="group-chip gc-' . $color . '" href="index.php?group=' . (int)$group['id'] . '">' . $inner . '</a>'
        : '<span class="group-chip gc-' . $color . '">' . $inner . '</span>';
}

/** Groups keyed by site id: [site_id => [group, ...]]. */
function groups_by_site(): array
{
    $out = [];
    $rows = BatSignal\Database::get()->query(
        'SELECT m.site_id, g.id, g.name, g.color FROM site_group_members m JOIN site_groups g ON g.id = m.group_id ORDER BY g.name'
    )->fetchAll();
    foreach ($rows as $r) {
        $out[(int)$r['site_id']][] = $r;
    }
    return $out;
}

/** "All / group A / group B" filter chips; $base is the page to link to. */
function group_filter(string $base, ?int $active): string
{
    $groups = BatSignal\Database::get()->query('SELECT id, name, color FROM site_groups ORDER BY name')->fetchAll();
    if (!$groups) {
        return '';
    }
    $html = '<nav class="group-filter mb-3" aria-label="' . te('groups.filter_aria') . '">'
        . '<a class="group-chip gc-all' . ($active === null ? ' active' : '') . '" href="' . e($base) . '"' . ($active === null ? ' aria-current="true"' : '') . '>' . te('groups.all') . '</a>';
    foreach ($groups as $g) {
        $isActive = $active === (int)$g['id'];
        $color = in_array($g['color'], GROUP_COLORS, true) ? $g['color'] : 'yellow';
        $html .= '<a class="group-chip gc-' . $color . ($isActive ? ' active' : '') . '" href="' . e($base) . '?group=' . (int)$g['id'] . '"'
            . ($isActive ? ' aria-current="true"' : '') . '><span class="dot" aria-hidden="true"></span>' . e($g['name']) . '</a>';
    }
    return $html . '</nav>';
}

/** Language picker. $back is the current page (relative) so we return to it after switching. */
function lang_switcher(string $back, string $class = ''): string
{
    $current = BatSignal\I18n::get();
    $items = '';
    foreach (BatSignal\I18n::LANGS as $code => $label) {
        $active = $code === $current;
        $items .= '<li><a class="dropdown-item' . ($active ? ' active' : '') . '" lang="' . $code . '" hreflang="' . $code . '"'
            . ($active ? ' aria-current="true"' : '')
            . ' href="lang.php?set=' . $code . '&amp;back=' . rawurlencode($back) . '">'
            . '<span class="lang-code">' . strtoupper($code) . '</span>' . e($label) . '</a></li>';
    }
    return '<div class="dropdown lang-switch ' . e($class) . '">'
        . '<button class="btn btn-ghost btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="' . te('nav.language') . '">'
        . '<i class="ph ph-translate" aria-hidden="true"></i>' . strtoupper($current) . '</button>'
        . '<ul class="dropdown-menu dropdown-menu-end">' . $items . '</ul></div>';
}
