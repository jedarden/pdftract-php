<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when pdftract command fails
 */
class PdftractException extends \Exception
{
    private int $exitCode;

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int $exitCode Process exit code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "", int $exitCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $previous);
        $this->exitCode = $exitCode;
    }

    /**
     * Get the exit code from the failed process
     *
     * @return int Exit code
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
