<?php

namespace App\Services\GitProviders;

enum RepositoryPickerState: string
{
    case Ok = 'ok';
    case NoConnection = 'no_connection';
    /** The provider refused the listing: the token was revoked or lacks a scope. */
    case Reconnect = 'reconnect';
    /** Network, provider 5xx or rate limit: try again in a minute. */
    case Unavailable = 'unavailable';
    /** This user made too many listing calls in the last minute. */
    case Throttled = 'throttled';
}
