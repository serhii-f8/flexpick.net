<?php

namespace App\Exceptions;

use Exception;

class AuditNotAnalyzableException extends Exception
{
    /**
     * @param  bool  $accessDenied  true when we could not reach the repository
     *                              at all -- the one case the customer fixes by
     *                              connecting their git account
     * @param  bool  $reconnect  the workspace has (or had, until a refresh the
     *                           provider rejected) a connection for the repo's
     *                           provider, so the fix is to reconnect it
     */
    public function __construct(
        string $message,
        public readonly bool $accessDenied = false,
        public readonly bool $reconnect = false,
    ) {
        parent::__construct($message);
    }

    public static function accessDenied(string $message): self
    {
        return new self($message, accessDenied: true);
    }

    public static function reconnectNeeded(string $message): self
    {
        return new self($message, accessDenied: true, reconnect: true);
    }
}
