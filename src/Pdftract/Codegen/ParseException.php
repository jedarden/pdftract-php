<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Codegen;

use Jedarden\Pdftract\PdftractException;

/**
 * Exception thrown when JSON parsing fails
 */
class ParseException extends PdftractException
{
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
    }
}
