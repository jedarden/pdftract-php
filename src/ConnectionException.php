<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when the pdftract server cannot be reached
 *
 * Covers transport-level failures that produce no HTTP response at all:
 * unresolvable hostnames, refused connections, and TLS handshake problems.
 * Distinguishing these from server-reported errors lets callers retry
 * against a replica without also retrying a genuine 4xx/5xx.
 */
class ConnectionException extends PdftractException
{
    private string $reason;

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param string $reason Underlying transport error text (e.g. cURL's error string)
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "", string $reason = "", ?\Throwable $previous = null)
    {
        parent::__construct($message, null, null, null, $previous);
        $this->reason = $reason;
    }

    /**
     * Get the underlying transport error text
     *
     * @return string Transport error text (e.g. cURL's error string)
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
