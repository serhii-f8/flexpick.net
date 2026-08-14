<?php

namespace App\Constants;

enum AuditFunding: string
{
    case ALLOWANCE = 'allowance';
    case FREE = 'free';
    case PURCHASE = 'purchase';
}
