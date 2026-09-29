<?php
namespace BatSignal;

class I18n
{
    public const LANGS = ['ca' => 'Català', 'es' => 'Español', 'ro' => 'Română'];
    public const DEFAULT = 'ca';

    private static string $lang = self::DEFAULT;
    private static array $dicts = [];

    public static function set(string $lang): void
    {
        if (isset(self::LANGS[$lang])) {
            self::$lang = $lang;
        }
    }

    public static function get(): string
    {
        return self::$lang;
    }

    public static function isValid(?string $lang): bool
    {
        return $lang !== null && isset(self::LANGS[$lang]);
    }

    /** Session (set at login / by the switcher) → cookie → browser Accept-Language → Catalan. */
    public static function detect(): string
    {
        if (self::isValid($_SESSION['lang'] ?? null)) {
            return $_SESSION['lang'];
        }
        if (self::isValid($_COOKIE['bs_lang'] ?? null)) {
            return $_COOKIE['bs_lang'];
        }
        foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') as $part) {
            $code = strtolower(substr(trim($part), 0, 2));
            if (self::isValid($code)) {
                return $code;
            }
        }
        return self::DEFAULT;
    }

    public static function t(string $key, array $params = [], ?string $lang = null): string
    {
        $text = self::dict($lang ?? self::$lang)[$key] ?? self::dict(self::DEFAULT)[$key] ?? $key;
        if ($params) {
            $replace = [];
            foreach ($params as $k => $v) {
                $replace['{' . $k . '}'] = (string)$v;
            }
            $text = strtr($text, $replace);
        }
        return $text;
    }

    /**
     * Check names are user data, but the auto-created ones keep their default name in
     * whatever language they were created in. Show those in the reader's language;
     * leave names the user has changed untouched.
     */
    public static function checkName(string $name, ?string $lang = null): string
    {
        foreach (['default.http', 'default.crawl', 'default.ssl'] as $key) {
            foreach (array_keys(self::LANGS) as $code) {
                if ((self::dict($code)[$key] ?? null) === $name) {
                    return self::t($key, [], $lang);
                }
            }
        }
        return $name;
    }

    /** Strings the front-end script needs (keys prefixed with "js."). */
    public static function jsDict(): array
    {
        $out = [];
        foreach (self::dict(self::DEFAULT) as $key => $_) {
            if (str_starts_with($key, 'js.')) {
                $out[substr($key, 3)] = self::t($key);
            }
        }
        return $out;
    }

    private static function dict(string $lang): array
    {
        return self::$dicts[$lang] ??= require APP_ROOT . '/app/lang/' . $lang . '.php';
    }
}
