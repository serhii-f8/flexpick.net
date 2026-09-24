<?php

namespace App\Exceptions;

use Exception;

class AuditNotAnalyzableException extends Exception
{
    /**
     * @param  bool  $accessDenied  true when we could not reach the repository
     *                              at all -- the one case the customer fixes by
     *                              inviting our review account
     */
    public function __construct(string $message, public readonly bool $accessDenied = false)
    {
        parent::__construct($message);
    }

    public static function accessDenied(string $message): self
    {
        return new self($message, accessDenied: true);
    }
}
