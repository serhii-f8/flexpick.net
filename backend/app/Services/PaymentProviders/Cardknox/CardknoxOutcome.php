<?php

namespace App\Services\PaymentProviders\Cardknox;

enum CardknoxOutcome: string
{
    case Approved = 'approved';
    case Declined = 'declined';
    case Error = 'error';
    // Timeout, network failure, malformed response: the card may or may not
    // have been charged, so nothing local may change until it is confirmed.
    case Unknown = 'unknown';
}
