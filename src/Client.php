<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

use Jedarden\Pdftract\Codegen\Methods;
use Jedarden\Pdftract\Codegen\Errors;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main client for the pdftract PHP SDK.
 */
class Client
{
    private string $baseUrl;
    private ?string $apiKey;
    private LoggerInterface $logger;
    private Methods $methods;
    private Errors $errors;

    /**
     * Create a new pdftract client.
     *
     * @param string $baseUrl Base URL of the pdftract service
     * @param string|null $apiKey Optional API key for authentication
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        ?LoggerInterface $logger = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger ?? new NullLogger();
        $this->methods = new Methods($this->baseUrl, $this->apiKey, $this->logger);
        $this->errors = new Errors();
    }

    /**
     * Get the API methods handler.
     *
     * @return Methods
     */
    public function methods(): Methods
    {
        return $this->methods;
    }

    /**
     * Get the error handler.
     *
     * @return Errors
     */
    public function errors(): Errors
    {
        return $this->errors;
    }
}
