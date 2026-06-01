<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Source specification for pdftract commands
 *
 * Represents a PDF source (file path, URL, or stdin)
 */
class Source
{
    private string $type;
    private string $value;

    /**
     * Constructor
     *
     * @param string $type Source type: 'file', 'url', or 'stdin'
     * @param string $value File path, URL, or '-' for stdin
     */
    private function __construct(string $type, string $value)
    {
        $this->type = $type;
        $this->value = $value;
    }

    /**
     * Create a file source
     *
     * @param string $path Path to PDF file
     * @return self
     */
    public static function file(string $path): self
    {
        return new self('file', $path);
    }

    /**
     * Create a URL source
     *
     * @param string $url URL to PDF
     * @return self
     */
    public static function url(string $url): self
    {
        return new self('url', $url);
    }

    /**
     * Create a stdin source
     *
     * @return self
     */
    public static function stdin(): self
    {
        return new self('stdin', '-');
    }

    /**
     * Convert source to CLI arguments
     *
     * @return array CLI arguments
     */
    public function toArgs(): array
    {
        if ($this->type === 'url') {
            return ['--url', $this->value];
        }

        return [$this->value];
    }
}
