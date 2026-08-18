<?php

namespace App\Support\Sentry;

use Sentry\Breadcrumb;
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

    // Static so config/sentry.php can reference it as a [Class, method]
    // callable — a plain array of strings, which var_export() (used by
    // `config:cache`) can serialize. A closure or an instantiated object
    // cannot: both fail with "could not be serialized" / "undefined method
    // __set_state()" the moment config:cache runs.
    public static function handle(Event $event, ?EventHint $hint): ?Event
    {
        $message = $event->getMessage();

        if ($message !== null) {
            $event->setMessage(self::scrub($message));
        }

        $extra = $event->getExtra();

        if ($extra !== []) {
            $event->setExtra(self::scrubArray($extra));
        }

        $tags = $event->getTags();

        if ($tags !== []) {
            /** @var array<string, string> $scrubbedTags */
            $scrubbedTags = self::scrubArray($tags);
            $event->setTags($scrubbedTags);
        }

        // The path taken by every unhandled `\Throwable` reaching Sentry via
        // `captureException()` — including Laravel's exception handler.
        // `ExceptionDataBag::$value` (the exception message) lives entirely
        // separately from `Event::getMessage()`, which only carries explicit
        // string captures.
        foreach ($event->getExceptions() as $exceptionDataBag) {
            $exceptionDataBag->setValue(self::scrub($exceptionDataBag->getValue()));
        }

        // Command/SQL breadcrumbs are enabled by default (config/sentry.php),
        // and the audit pipeline shells out to `git clone` with the
        // credential embedded in the URL.
        $breadcrumbs = $event->getBreadcrumbs();

        if ($breadcrumbs !== []) {
            $event->setBreadcrumb(array_map(
                fn (Breadcrumb $breadcrumb): Breadcrumb => self::scrubBreadcrumb($breadcrumb),
                $breadcrumbs
            ));
        }

        $request = $event->getRequest();

        if ($request !== []) {
            $event->setRequest(self::scrubArray($request));
        }

        foreach ($event->getContexts() as $name => $data) {
            $event->setContext($name, self::scrubArray($data));
        }

        return $event;
    }

    private static function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        if ($message !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::scrub($message));
        }

        foreach ($breadcrumb->getMetadata() as $key => $value) {
            if (is_string($value)) {
                $breadcrumb = $breadcrumb->withMetadata($key, self::scrub($value));
            } elseif (is_array($value)) {
                $breadcrumb = $breadcrumb->withMetadata($key, self::scrubArray($value));
            }
        }

        return $breadcrumb;
    }

    private static function scrub(string $value): string
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
    private static function scrubArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = self::scrub($value);
            } elseif (is_array($value)) {
                $values[$key] = self::scrubArray($value);
            }
        }

        return $values;
    }
}
