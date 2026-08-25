<?php

namespace App\Support;

/**
 * Makes bytes from an audited repository safe to encode.
 *
 * Everything the audit pipeline learns about a repo arrives as raw bytes we
 * did not write: file contents, file names, branch names, commit author
 * strings, and the stdout of the scanners. None of it is guaranteed to be
 * UTF-8, and three separate layers of this app treat invalid UTF-8 as fatal
 * rather than as data:
 *
 *  - the Anthropic SDK encodes the request body with JSON_THROW_ON_ERROR, so
 *    one bad byte anywhere in the prompt aborts the analysis;
 *  - Eloquent's array/json casts throw JsonEncodingException, so one bad byte
 *    anywhere in $metrics aborts the run before the model is even called;
 *  - json_decode() rejects malformed UTF-8 input, so one bad byte in a
 *    scanner's output silently degrades that scanner to "did not complete".
 *
 * A repo is allowed to contain whatever bytes it likes. Scrubbing at the
 * boundary is what turns "the audit crashed" into "the audit ran".
 */
class Utf8
{
    /**
     * Replace ill-formed byte sequences with U+FFFD.
     *
     * Substitution rather than deletion: it keeps byte offsets roughly stable
     * for the deep-review token budget, and leaves the damage visible in the
     * excerpt instead of silently closing the gap.
     */
    public static function scrub(string $value): string
    {
        // mb_scrub() substitutes whatever mbstring.substitute_character says,
        // which defaults to '?' and is settable per environment -- so the same
        // repo could scrub differently in dev and production. Pinning U+FFFD
        // here makes the output depend on the input alone, and '?' is a
        // plausible source character where the replacement char is obviously
        // damage. The previous value is restored because this is global state.
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_scrub($value, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * Scrub every string in a nested structure, keys included.
     *
     * Array keys matter as much as values here: a JSON object from a scanner
     * can be keyed by a file name, and an unencodable key kills the encode
     * exactly as an unencodable value does.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    public static function scrubDeep(array $value): array
    {
        $scrubbed = [];

        foreach ($value as $key => $item) {
            $key = is_string($key) ? self::scrub($key) : $key;

            $scrubbed[$key] = match (true) {
                is_string($item) => self::scrub($item),
                is_array($item) => self::scrubDeep($item),
                default => $item,
            };
        }

        return $scrubbed;
    }
}
