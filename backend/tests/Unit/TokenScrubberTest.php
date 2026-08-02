<?php

namespace Tests\Unit;

use App\Support\Sentry\TokenScrubber;
use Sentry\Event;
use Sentry\EventHint;
use Tests\TestCase;

class TokenScrubberTest extends TestCase
{
    private const TOKEN = 'ghp_AbCdEf0123456789AbCdEf0123456789AbCd';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('audit.github_token', self::TOKEN);
    }

    public function test_strips_an_embedded_credential_pair_from_the_message(): void
    {
        $event = Event::createEvent();
        $event->setMessage(
            'Failed cloning https://x-access-token:'.self::TOKEN.'@github.com/acme/app.git'
        );

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertNotNull($scrubbed);
        $this->assertStringNotContainsString(self::TOKEN, $scrubbed->getMessage());
        $this->assertStringNotContainsString('x-access-token:', $scrubbed->getMessage());
        $this->assertStringContainsString('[REDACTED]', $scrubbed->getMessage());
        $this->assertStringContainsString('github.com/acme/app.git', $scrubbed->getMessage());
    }

    public function test_strips_a_bare_token_occurrence(): void
    {
        $event = Event::createEvent();
        $event->setMessage('git exited 128; token was '.self::TOKEN);

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertStringNotContainsString(self::TOKEN, $scrubbed->getMessage());
    }

    public function test_strips_credentials_from_extra_context(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'command' => 'git clone https://x-access-token:'.self::TOKEN.'@github.com/acme/app.git /tmp/x',
        ]);

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertStringNotContainsString(self::TOKEN, json_encode($scrubbed->getExtra()));
    }

    public function test_strips_any_credential_pair_even_when_no_token_is_configured(): void
    {
        config()->set('audit.github_token', null);

        $event = Event::createEvent();
        $event->setMessage('https://x-access-token:some-other-secret@github.com/acme/app.git');

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertStringNotContainsString('some-other-secret', $scrubbed->getMessage());
    }

    public function test_leaves_clean_events_untouched(): void
    {
        $event = Event::createEvent();
        $event->setMessage('Repository could not be cloned: https://github.com/acme/app.git');

        $scrubbed = (new TokenScrubber)($event, new EventHint);

        $this->assertSame(
            'Repository could not be cloned: https://github.com/acme/app.git',
            $scrubbed->getMessage()
        );
    }
}
