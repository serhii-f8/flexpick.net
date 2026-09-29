<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The tenant's git credential could not be obtained *right now* -- a token refresh hit a
 * network error, a provider 5xx or a rate limit, or another worker held the refresh lock
 * past our wait. It says nothing about the repository or the connection, both of which
 * may be perfectly healthy.
 *
 * It is deliberately NOT an AuditNotAnalyzableException: that one closes the audit,
 * refunds it and tells the customer to grant access. This one must reach the queue so
 * GenerateAuditReport's tries/backoff apply, and reach interactive callers so they can
 * ask the user to try again in a minute. The message never carries token material.
 */
class GitAccessTemporarilyUnavailableException extends RuntimeException {}
