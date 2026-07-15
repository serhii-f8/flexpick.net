<?php

namespace Tests\Feature\Services;

use App\Exceptions\MailcoachUnavailableException;
use App\Services\AuditMail\MailcoachClient;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class MailcoachClientTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'test-token');
    }

    public function test_unconfigured_without_endpoint(): void
    {
        config()->set('services.mailcoach.endpoint', null);

        $this->assertFalse(app(MailcoachClient::class)->isConfigured());
    }

    public function test_send_transactional_returns_uuid_when_present(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response(['data' => ['uuid' => 'tm-123']], 200)]);

        $uuid = app(MailcoachClient::class)->sendTransactional('a@b.com', 'Subject', '<p>Hi</p>');

        $this->assertSame('tm-123', $uuid);
        Http::assertSent(function ($request) {
            return $request->url() === 'http://mailcoach/api/transactional-mails/send'
                && $request['to'] === 'a@b.com'
                && $request['subject'] === 'Subject'
                && $request['html'] === '<p>Hi</p>'
                && $request['store'] === true
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_send_transactional_handles_bodyless_success(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response(null, 204)]);

        $this->assertNull(app(MailcoachClient::class)->sendTransactional('a@b.com', 'S', '<p></p>'));
    }

    public function test_send_transactional_throws_on_server_error(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response('nope', 500)]);

        $this->expectException(MailcoachUnavailableException::class);
        app(MailcoachClient::class)->sendTransactional('a@b.com', 'S', '<p></p>');
    }

    public function test_resend_posts_to_uuid_endpoint(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails/tm-9/resend' => Http::response(null, 200)]);

        app(MailcoachClient::class)->resend('tm-9');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transactional-mails/tm-9/resend'));
    }

    public function test_recent_transactional_mails_returns_items(): void
    {
        Http::fake(['http://mailcoach/api/transactional-mails*' => Http::response(['data' => [['uuid' => 'tm-1']]], 200)]);

        $items = app(MailcoachClient::class)->recentTransactionalMails();

        $this->assertSame('tm-1', $items[0]['uuid']);
    }
}
