<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when a response cannot be parsed
 *
 * Part of the ported exception hierarchy (bf-4gq). The HTTP client raises
 * the base {@see PdftractException} for a body that is not the JSON its
 * route promised today; this class is the hierarchy's home for parse
 * failures should one need to be distinguished.
 */
class ParseException extends PdftractException
{
}
