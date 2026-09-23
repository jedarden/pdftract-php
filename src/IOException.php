<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when a local file operation fails
 *
 * Part of the ported exception hierarchy (bf-4gq). A file-backed
 * {@see Source} that cannot be read raises the base {@see PdftractException}
 * today; this class is the hierarchy's home for filesystem failures should
 * one need to be distinguished.
 */
class IOException extends PdftractException
{
}
