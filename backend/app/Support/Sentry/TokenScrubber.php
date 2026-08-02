<?php

namespace App\Support\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Spec §15.1 [R] and §18.4: no repository access token may leave the
 * application. RepositoryCloner builds authenticated clone URLs, so any
 * exception raised near it can carry credentials that its own redaction
 * does not cover.
 */
class TokenScrubber
{
    private const REPLACEMENT = '[REDACTED]';

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $message = $event->getMessage();

        if ($message !== null) {
            $event->setMessage($this->scrub($message));
        }

        $extra = $event->getExtra();

        if ($extra !== []) {
            $event->setExtra($this->scrubArray($extra));
        }

        $tags = $event->getTags();

        if ($tags !== []) {
            /** @var array<string, string> $scrubbedTags */
            $scrubbedTags = $this->scrubArray($tags);
            $event->setTags($scrubbedTags);
        }

        return $event;
    }

    private function scrub(string $value): string
    {
        // Any embedded credential pair, whether or not it is our token.
        $value = (string) preg_replace(
            '#https://[^:/@\s]+:[^@\s]+@#i',
            'https://'.self::REPLACEMENT.'@',
            $value
        );

        $token = (string) config('audit.github_token');

        if ($token !== '') {
            $value = str_replace($token, self::REPLACEMENT, $value);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrubArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = $this->scrub($value);
            } elseif (is_array($value)) {
                $values[$key] = $this->scrubArray($value);
            }
        }

        return $values;
    }
}
