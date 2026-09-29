<?php

namespace App\Services\GitProviders;

/**
 * What GitRepoAccessResolver found for one repo and tenant: the credential git
 * should present (null = clone anonymously), and whether the tenant has -- or
 * had until a refresh the provider just rejected -- a connection for the repo's
 * provider. The second is what tells "connect your account" apart from
 * "reconnect it" when access then fails.
 */
final readonly class GitRepoAccess
{
    public function __construct(
        public ?GitCredential $credential,
        public bool $connected,
    ) {}
}
