<?php
namespace BatSignal;

/**
 * Looks inside an HTML body for signs that the page is broken even though the
 * server answered 2xx: PHP errors, CMS "critical error" screens, blank pages.
 */
class PageInspector
{
    // Matches PHP's own error output ("Warning: foo() ... in /path/file.php on line 12"),
    // with or without html_errors <b> tags. Precise enough to avoid false positives on
    // pages that merely contain the word "Warning".
    private const PHP_ERROR_REGEX = '/(?:<b>\s*)?(Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated)(?:\s*<\/b>)?\s*:\s*(.{0,300}?)\s+in\s+(?:<b>\s*)?[^\s<]+\.php(?:\s*<\/b>)?\s+on\s+line/is';

    private const FATAL_PHRASES = [
        'Uncaught Error',
        'Uncaught Exception',
        'Uncaught TypeError',
        'Uncaught ArgumentCountError',
        'There has been a critical error on this website',
        'Ha habido un error crítico en esta web',
        'Error establishing a database connection',
        'Error al establecer una conexión con la base de datos',
    ];

    /** @return array{reason_code:string, detail:array}|null  detail is a Msg */
    public static function findProblem(string $body, array $customForbidden = []): ?array
    {
        foreach ($customForbidden as $needle) {
            $needle = trim((string)$needle);
            if ($needle !== '' && stripos($body, $needle) !== false) {
                return [
                    'reason_code' => 'forbidden_keyword_found',
                    'detail' => Msg::make('msg.page_forbidden', ['text' => $needle]),
                ];
            }
        }

        if (preg_match(self::PHP_ERROR_REGEX, $body, $m)) {
            $message = preg_replace('/\s*Stack trace:.*$/s', '', strip_tags($m[2]));
            $message = preg_replace('/\s+in\s+\S+\.php(:\d+)?\s*$/', '', $message);
            $message = trim(preg_replace('/\s+/', ' ', $message));
            return [
                'reason_code' => 'php_error_detected',
                'detail' => Msg::make('msg.page_php_error', ['type' => $m[1], 'message' => mb_strimwidth($message, 0, 160, '…')]),
            ];
        }

        foreach (self::FATAL_PHRASES as $phrase) {
            if (stripos($body, $phrase) !== false) {
                return [
                    'reason_code' => 'php_error_detected',
                    'detail' => Msg::make('msg.page_critical', ['phrase' => $phrase]),
                ];
            }
        }

        if (strlen(trim($body)) < 50) {
            return [
                'reason_code' => 'empty_page',
                'detail' => Msg::make('msg.page_blank'),
            ];
        }

        return null;
    }
}
