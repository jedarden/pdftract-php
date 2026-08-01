<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Codegen;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * API methods for pdftract service.
 * This class will be populated with auto-generated methods from the OpenAPI schema.
 */
class Methods
{
    private string $baseUrl;
    private ?string $apiKey;
    private LoggerInterface $logger;

    public function __construct(
        string $baseUrl,
        ?string $apiKey,
        ?LoggerInterface $logger = null
    ) {
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get the base URL.
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the API key.
     *
     * @return string|null
     */
    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    // TODO: Add auto-generated methods from OpenAPI schema
}
