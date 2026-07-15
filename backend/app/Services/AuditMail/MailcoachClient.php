<?php

namespace App\Services\AuditMail;

use App\Exceptions\MailcoachUnavailableException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class MailcoachClient
{
    public function isConfigured(): bool
    {
        return (string) config('services.mailcoach.endpoint') !== ''
            && (string) config('services.mailcoach.api_token') !== '';
    }

    public function sendTransactional(string $to, string $subject, string $html): ?string
    {
        $response = $this->request('post', '/transactional-mails/send', [
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'from' => (string) config('mail.from.address'),
            'store' => true,
        ]);

        return $response->json('data.uuid');
    }

    public function resend(string $uuid): void
    {
        $this->request('post', "/transactional-mails/{$uuid}/resend");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentTransactionalMails(): array
    {
        return $this->request('get', '/transactional-mails')->json('data') ?? [];
    }

    private function request(string $method, string $path, array $payload = []): Response
    {
        $endpoint = rtrim((string) config('services.mailcoach.endpoint'), '/');

        try {
            $response = Http::withToken((string) config('services.mailcoach.api_token'))
                ->acceptJson()
                ->timeout(10)
                ->{$method}($endpoint.$path, $payload);
        } catch (Throwable $e) {
            throw new MailcoachUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new MailcoachUnavailableException("Mailcoach API {$path} responded {$response->status()}");
        }

        return $response;
    }
}
