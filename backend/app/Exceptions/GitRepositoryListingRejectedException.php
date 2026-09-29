<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider refused to list repositories for a connection: the token was
 * revoked, or it lacks a scope the listing needs. The connection may still clone;
 * the tenant should reconnect to enable the picker.
 */
class GitRepositoryListingRejectedException extends RuntimeException {}
