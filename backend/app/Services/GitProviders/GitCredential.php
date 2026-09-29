<?php

namespace App\Services\GitProviders;

use SensitiveParameter;

/**
 * A tenant's git credential for one provider origin, as an HTTP basic-auth pair.
 *
 * It is only ever handed to git through the process environment (see gitEnv()),
 * scoped to the origin so a redirect to another host does not receive it.
 */
final readonly class GitCredential
{
    public function __construct(
        public string $username,
        #[SensitiveParameter] public string $password,
        /** "https://<host>", lowercase, no port or path */
        public string $origin,
    ) {}

    /**
     * @return array<string, string>
     */
    public function gitEnv(): array
    {
        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => "http.{$this->origin}/.extraHeader",
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode("{$this->username}:{$this->password}"),
        ];
    }
}
