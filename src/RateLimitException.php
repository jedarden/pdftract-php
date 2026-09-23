<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when a rate limiter rejects the request
 *
 * A 429 response. The serve API itself never rate limits, so this comes
 * from a quota-ing reverse proxy in front of it — the request never
 * reached extraction. Retrying immediately repeats the failure; the
 * deployment's quota window decides when it can succeed.
 */
class RateLimitException extends PdftractException
{
}
