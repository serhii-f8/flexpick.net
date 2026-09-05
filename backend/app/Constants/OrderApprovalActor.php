<?php

namespace App\Constants;

enum OrderApprovalActor: string
{
    case PARTNER = 'partner';
    case ADMIN = 'admin';
    case SYSTEM = 'system';
}
