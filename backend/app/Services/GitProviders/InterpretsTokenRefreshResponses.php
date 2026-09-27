<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitTokenRefreshUnavailableException;
use Closure;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Shared classification of an OAuth2 refresh_token grant response (RFC 6749 §5.1/§5.2)
 * for providers whose token endpoint follows the spec's JSON shape.
 */
trait InterpretsTokenRefreshResponses
{
    /**
     * @param  Closure(): Response  $send
     * @return array{access_token:string, refresh_token:?string, expires_in:?int}|null
     */
    private function interpretRefreshResponse(Closure $send): ?array
    {
        try {
            $response = $send();
        } catch (Throwable) {
            // Connection refused/timed out: the refresh_token may be perfectly valid.
            throw new GitTokenRefreshUnavailableException("{$this->label()} token endpoint unreachable");
        }

        if ($response->serverError()) {
            throw new GitTokenRefreshUnavailableException("{$this->label()} token endpoint returned {$response->status()}");
        }

        if (! $response->successful()) {
            // RFC 6749 §5.2: only `invalid_grant` means THIS refresh_token is genuinely
            // dead (expired, revoked, already used) -- that's the one case where deleting
            // the tenant's connection is correct. Everything else -- invalid_client (our
            // own client secret is wrong), a rate limit, a request timeout, or any other
            // rejection -- is transient or on our side, and must not disconnect a tenant
            // over it. A malformed/non-JSON error body (no `error` field at all, e.g. a
            // plain-text 429 from a CDN) also falls through to transient here.
            if ($response->json('error') !== 'invalid_grant') {
                throw new GitTokenRefreshUnavailableException(
                    "{$this->label()} token endpoint rejected the refresh (".($response->json('error') ?? (string) $response->status()).')'
                );
            }

            return null;
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        $refreshToken = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : null,
        ];
    }
}
