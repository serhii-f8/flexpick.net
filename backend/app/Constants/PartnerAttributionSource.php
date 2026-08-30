<?php

namespace App\Constants;

enum PartnerAttributionSource: string
{
    case LINK = 'link';
    case REGISTRATION = 'registration';
    case LOGIN = 'login';
    case ORDER = 'order';
}
