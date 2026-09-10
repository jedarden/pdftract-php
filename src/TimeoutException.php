<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when a pdftract request exceeds its configured timeout
 *
 * Buffered calls (extract, extractText) bound the whole request; streaming
 * calls (extractStream) bound *silence* — the deadline resets every time the
 * server produces output, so a long but productive stream is not cut off
 * while a stalled one still is.
 */
class TimeoutException extends PdftractException
{
    private float $timeoutSeconds;

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param float $timeoutSeconds The timeout that was exceeded, in seconds
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "", float $timeoutSeconds = 0.0, ?\Throwable $previous = null)
    {
        parent::__construct($message, null, null, null, $previous);
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Get the timeout that was exceeded
     *
     * @return float Timeout in seconds
     */
    public function getTimeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }
}
