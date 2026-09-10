<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when the client is constructed or called with an invalid
 * configuration — a malformed base URL, a negative timeout, or a PDF file
 * that cannot be read.
 */
class ConfigurationException extends PdftractException
{
}
