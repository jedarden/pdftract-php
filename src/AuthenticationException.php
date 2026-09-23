<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when the pdftract endpoint rejects the caller's credentials
 *
 * A 401 or 403 response. The serve API itself has no built-in
 * authentication, so in practice these come from an authenticating reverse
 * proxy in front of it — the deployment the client's API key option exists
 * for. Separating them from the other failures lets a caller tell a missing
 * or revoked key apart from a bad request or a rate limit.
 */
class AuthenticationException extends PdftractException
{
}
