<?php

namespace App\Search;

/**
 * Turns Hebrew/English text into comparable tokens.
 *
 * Lowercases, drops niqqud and gershayim, splits on anything that is not a
 * letter or digit, and writes final letters as regular ones (ם → מ). Hebrew
 * tokens also get lighter forms: without a one-letter prefix (השואב → שואב)
 * and without a plural suffix (בלנדרים → בלנדר). The query looks up its forms;
 * the index stores the forms of catalog tokens too ("לתינוק" → "תינוק").
 */
class TextNormalizer
{
    private const HEBREW_PREFIXES = ['ה', 'ו', 'ב', 'ל', 'ש', 'מ', 'כ'];

    // Written as after normalize(): final mem is a regular one (ים → ימ).
    private const HEBREW_PLURAL_SUFFIXES = ['ימ', 'ות'];

    /** @var array<string, true> */
    private array $stopwords;

    /**
     * @param  list<string>  $stopwords
     */
    public function __construct(array $stopwords = [])
    {
        $this->stopwords = array_fill_keys($stopwords, true);
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\p{Mn}+/u', '', $text);
        $text = preg_replace('/[\'"`׳״’]/u', '', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        // Final letters as regular ones, so "סכינים" minus its suffix ("סכינ") matches "סכין".
        $text = strtr($text, ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);

        return trim($text);
    }

    /**
     * Unique tokens of the text, without stopwords.
     *
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $token) => ! isset($this->stopwords[$token]),
        )));
    }

    /**
     * Forms of a query token to look up, with a confidence weight each.
     *
     * @return array<string, float>
     */
    public function variants(string $token): array
    {
        $variants = [$token => 1.0];

        if (! preg_match('/^\p{Hebrew}+$/u', $token)) {
            return $variants;
        }

        foreach (self::HEBREW_PLURAL_SUFFIXES as $suffix) {
            if (mb_strlen($token) >= 5 && str_ends_with($token, $suffix)) {
                $variants[mb_substr($token, 0, -2)] = 0.9;
            }
        }

        if (mb_strlen($token) >= 4 && in_array(mb_substr($token, 0, 1), self::HEBREW_PREFIXES, true)) {
            $variants[mb_substr($token, 1)] ??= 0.8;
        }

        return $variants;
    }
}
