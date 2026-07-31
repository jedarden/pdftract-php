<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when a pdftract invocation exceeds its configured timeout.
 *
 * The child process is terminated before this is thrown, so no orphaned
 * pdftract process is left behind.
 */
class TimeoutException extends PdftractException
{
    /** Exit code reported for a timed-out command (matches GNU timeout(1)). */
    public const EXIT_CODE = 124;

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
        parent::__construct($message, self::EXIT_CODE, $previous);
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
