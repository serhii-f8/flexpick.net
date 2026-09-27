<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A token refresh that could not be *attempted* to completion -- a network error or a
 * provider-side 5xx -- as opposed to one the provider definitively rejected (a revoked
 * or invalid refresh_token, which GitProvider::refreshToken() reports as null).
 *
 * The distinction matters to GitRepoAccessResolver: a rejection deletes the connection
 * (the user must reconnect), but a transient failure must not, or a single gitlab.com
 * blip would silently disconnect every tenant whose token happened to expire during it.
 */
class GitTokenRefreshUnavailableException extends RuntimeException {}
