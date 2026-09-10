<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * The PDF document a request should be extracted from
 *
 * The pdftract serve API accepts documents as multipart uploads only — the
 * server never reads from its own filesystem on a client's behalf. A Source
 * holds the document bytes (or a path to read them from) for upload.
 *
 * Construct one with {@see Source::bytes()} for an in-memory PDF or
 * {@see Source::file()} for a file on the local filesystem. The legacy CLI
 * transport also accepted URL and stdin sources; the serve API has no
 * equivalent for either, so neither is represented here.
 */
final class Source
{
    private ?string $path;
    private ?string $bytes;

    private function __construct(?string $path, ?string $bytes)
    {
        $this->path = $path;
        $this->bytes = $bytes;
    }

    /**
     * A source that uploads the given raw PDF bytes
     *
     * @param string $bytes PDF file contents
     * @return self
     */
    public static function bytes(string $bytes): self
    {
        return new self(null, $bytes);
    }

    /**
     * A source that reads a PDF from the local filesystem at request time
     *
     * The file is read when the request is built, not when the Source is
     * constructed, so a Source can be reused across calls to pick up
     * regenerated documents.
     *
     * @param string $path Path to a PDF file
     * @return self
     */
    public static function file(string $path): self
    {
        return new self($path, null);
    }

    /**
     * The PDF bytes this source resolves to
     *
     * @return string PDF file contents
     * @throws PdftractException If a file-backed source cannot be read
     */
    public function toBytes(): string
    {
        if ($this->bytes !== null) {
            return $this->bytes;
        }

        $bytes = @file_get_contents($this->path ?? '');

        if ($bytes === false) {
            throw new PdftractException(
                sprintf('Failed to read PDF file: %s', $this->path ?? ''),
            );
        }

        return $bytes;
    }

    /**
     * Get the file path this source reads from, if file-backed
     *
     * @return string|null Path, or null for a bytes-backed source
     */
    public function getPath(): ?string
    {
        return $this->path;
    }
}
