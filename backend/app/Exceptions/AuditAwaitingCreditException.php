<?php

namespace App\Exceptions;

use Exception;

/**
 * The repository was sized after cloning and the request can't go on: the
 * workspace can't cover the runs it costs, or it is above every self-serve
 * size band. Caught by AuditPipeline, which closes the request as
 * awaiting_credit and refunds the first run.
 */
class AuditAwaitingCreditException extends Exception
{
    /**
     * @param  bool  $tooLarge  above the top size band: buying more credit
     *                          would not help, so the customer contacts us
     */
    public function __construct(string $message, public readonly bool $tooLarge = false)
    {
        parent::__construct($message);
    }

    public static function tooLarge(string $message): self
    {
        return new self($message, tooLarge: true);
    }

    public static function insufficient(string $message): self
    {
        return new self($message);
    }
}
