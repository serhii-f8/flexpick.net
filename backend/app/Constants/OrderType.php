<?php

namespace App\Constants;

enum OrderType: string
{
    case PURCHASE = 'purchase';
    case RENEWAL = 'renewal';
}
