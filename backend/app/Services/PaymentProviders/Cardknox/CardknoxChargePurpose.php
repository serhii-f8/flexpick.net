<?php

namespace App\Services\PaymentProviders\Cardknox;

enum CardknoxChargePurpose: string
{
    case First = 'first';
    case Renewal = 'renewal';
    case Proration = 'proration';
    case Order = 'order';
}
