<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when a pdftract request fails
 *
 * This is the base class for every exception the client raises. Failures
 * reported by the pdftract server (a non-2xx response carrying the serve
 * API's {error, message, hint} JSON body) surface the server's fields
 * directly; transport-level failures (connection refused, DNS, timeouts)
 * use the {@see ConnectionException} and {@see TimeoutException} subclasses.
 */
class PdftractException extends \Exception
{
    private ?int $statusCode;
    private ?string $errorCode;
    private ?string $hint;

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int|null $statusCode HTTP status code of the failing response, if any
     * @param string|null $errorCode The serve API's machine-readable error code
     *                               (e.g. "CORRUPT_PDF", "REQUEST_TOO_LARGE")
     * @param string|null $hint The server's actionable hint, if it sent one
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = "",
        ?int $statusCode = null,
        ?string $errorCode = null,
        ?string $hint = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->hint = $hint;
    }

    /**
     * Get the HTTP status code of the failing response
     *
     * @return int|null Status code, or null for transport-level failures
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Get the serve API's machine-readable error code
     *
     * @return string|null Error code (e.g. "ENCRYPTED"), or null when the
     *                     server did not send one or the failure never
     *                     reached it
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Get the server's actionable hint, when the error carried one
     *
     * @return string|null Hint string, or null
     */
    public function getHint(): ?string
    {
        return $this->hint;
    }
}
