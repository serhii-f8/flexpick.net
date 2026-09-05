<?php

namespace App\Constants;

enum OrderApprovalDecision: string
{
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
