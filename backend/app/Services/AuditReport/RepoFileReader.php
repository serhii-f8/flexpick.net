<?php

namespace App\Services\AuditReport;

use App\Support\Utf8;

/**
 * Reads a slice of a file from a cloned repo as text that is safe to put in a
 * prompt.
 *
 * Every byte we read here is attacker-shaped in the sense that matters: it
 * comes from someone else's repository, and nothing guarantees it is UTF-8.
 * The Anthropic SDK encodes the request body with JSON_THROW_ON_ERROR
 * (Anthropic\Core\Util::JSON_ENCODE_FLAGS), so one bad byte anywhere in the
 * prompt aborts the whole analysis with "Malformed UTF-8 characters, possibly
 * incorrectly encoded" — an error that names no file and looks like a provider
 * problem rather than a file we chose to read.
 *
 * Two distinct sources of bad bytes, and the second is the one that bites
 * ordinary repos:
 *
 *  1. The file genuinely isn't UTF-8 — a Latin-1 source file, a UTF-16 export,
 *     a binary that happens to carry a source extension.
 *  2. The file IS valid UTF-8, but the byte cap sliced a multi-byte character
 *     in half. Reading N bytes has no idea where codepoints end, so any repo
 *     with one emoji, curly quote or accented name straddling the cap produces
 *     an invalid tail.
 */
class RepoFileReader
{
    public function read(string $absolutePath, int $bytes): string
    {
        // mb_scrub replaces ill-formed sequences with U+FFFD rather than
        // dropping them, which keeps byte offsets roughly stable for the
        // deep-review token budget and leaves the damage visible in the
        // excerpt instead of silently closing the gap.
        return Utf8::scrub((string) file_get_contents($absolutePath, length: $bytes));
    }
}
