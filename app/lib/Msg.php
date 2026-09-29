<?php
namespace BatSignal;

/**
 * Language-neutral messages. Checks produce ['k' => key, 'p' => params] (optionally
 * with 'items' => [['path' => ..., 'm' => msg], ...] and 'more' => n for page lists);
 * they are stored as JSON and translated only when shown, so the history and emails
 * can be read in any language. Params may themselves be messages (rendered recursively).
 */
class Msg
{
    public static function make(string $key, array $params = []): array
    {
        return ['k' => $key, 'p' => $params];
    }

    public static function encode(?array $msg): ?string
    {
        return $msg === null ? null : json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Renders a stored detail. Rows written before i18n are plain text and shown as-is. */
    public static function render(?string $stored, ?string $lang = null): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }
        $msg = json_decode($stored, true);
        if (!is_array($msg) || !isset($msg['k'])) {
            return $stored;
        }
        return self::renderMsg($msg, $lang);
    }

    public static function renderMsg(?array $msg, ?string $lang = null): string
    {
        if ($msg === null) {
            return '';
        }
        $params = [];
        foreach ($msg['p'] ?? [] as $k => $v) {
            if (is_array($v) && isset($v['k'])) {
                $params[$k] = self::renderMsg($v, $lang);
            } elseif (is_array($v)) {
                // A list of messages, e.g. "2 with errors, 1 broken link".
                $params[$k] = implode(', ', array_map(fn($m) => self::renderMsg($m, $lang), $v));
            } else {
                $params[$k] = $v;
            }
        }
        $text = I18n::t($msg['k'], $params, $lang);
        foreach ($msg['items'] ?? [] as $item) {
            $text .= "\n• " . $item['path'] . ' — ' . self::renderMsg($item['m'], $lang);
            if (!empty($item['from'])) {
                $text .= ' ' . I18n::t('msg.linked_from', ['from' => $item['from']], $lang);
            }
        }
        if (!empty($msg['more'])) {
            $text .= "\n" . I18n::t('msg.crawl_more', ['n' => $msg['more']], $lang);
        }
        return $text;
    }
}
