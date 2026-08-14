<?php

namespace App\Services\AuditReport;

use Anthropic\Client;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The SDK's own RequestOptions::$timeout is advisory — it declares a 600s
 * intent but nothing in the SDK enforces it, so the real limit is whatever the
 * discovered PSR-18 transporter defaults to. Here that is Symfony HttpClient,
 * whose `timeout` is an IDLE timeout defaulting to 60 SECONDS: any Anthropic
 * call that thinks for longer than a minute before its first byte dies as an
 * APIConnectionException. Tier-1 analysis finishes in ~45s and survived; the
 * deep review, which sends ~150k tokens and asks for 16k back, never did.
 *
 * Building the transporter here rather than at each call site keeps that limit
 * in one place, where the next long-running Anthropic call inherits it.
 */
class AnthropicClientFactory
{
    public function make(): Client
    {
        return new Client(
            apiKey: (string) config('services.anthropic.api_key'),
            requestOptions: ['transporter' => $this->transporter()],
        );
    }

    private function transporter(): ClientInterface
    {
        return new Psr18Client(HttpClient::create($this->httpOptions()));
    }

    /**
     * @return array{timeout: float, max_duration: float}
     */
    public function httpOptions(): array
    {
        return [
            // Idle timeout: how long the connection may go silent while the
            // model thinks.
            'timeout' => (float) config('services.anthropic.timeout'),
            // Hard ceiling on the whole request, so a stalled response can
            // never outlive the queue job that is waiting on it.
            'max_duration' => (float) config('services.anthropic.max_duration'),
        ];
    }
}
