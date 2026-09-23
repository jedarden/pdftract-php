<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when text cannot be encoded or decoded
 *
 * Part of the ported exception hierarchy (bf-4gq). The HTTP client raises
 * the base {@see PdftractException} for a response body it cannot decode
 * today; this class is the hierarchy's home for encoding failures should
 * one need to be distinguished.
 */
class EncodingException extends PdftractException
{
}
