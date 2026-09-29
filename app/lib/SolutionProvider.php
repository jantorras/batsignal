<?php
namespace BatSignal;

/**
 * Rule-based solution suggestions, keyed by reason_code, with texts in app/lang/*.php
 * ("solution.<code>.title" / ".text"). Swap/extend with an AI-backed provider later
 * without touching callers — they only ever call SolutionProvider::suggest().
 */
class SolutionProvider
{
    public static function suggest(string $reasonCode, ?string $lang = null): array
    {
        $key = 'solution.' . $reasonCode . '.title';
        if ($reasonCode === '' || I18n::t($key, [], $lang) === $key) {
            $reasonCode = 'default';
        }
        return [
            'title' => I18n::t('solution.' . $reasonCode . '.title', [], $lang),
            'suggestion' => I18n::t('solution.' . $reasonCode . '.text', [], $lang),
        ];
    }
}
