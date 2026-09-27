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

        // RFC 6749 §5.2: a revoked/expired/invalid refresh_token is a 400 invalid_grant
        // (401 for bad client credentials). Either way this token will never refresh.
        if (! $response->successful()) {
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
