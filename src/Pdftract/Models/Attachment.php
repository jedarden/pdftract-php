<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of an embedded file attachment
 *
 * Represents a single embedded file extracted from the PDF's
 * `/EmbeddedFiles` name tree or `/AF` (Associated Files) array.
 */
class Attachment
{
    /**
     * Attachment filename from /UF (Unicode, preferred) or /F (system-independent)
     */
    public string $name;

    /**
     * Description from /Desc (null if absent, not empty string)
     */
    public ?string $description = null;

    /**
     * MIME type from stream /Subtype (null if absent, no guessing from extension)
     */
    public ?string $mime_type = null;

    /**
     * Original decoded size in bytes (always populated, even when truncated)
     *
     * This is the size of the attachment content before base64 encoding.
     * When `truncated: true`, this represents the full original size that
     * was not included in the output.
     */
    public int $size;

    /**
     * Creation date from /Params /CreationDate as ISO 8601 string (null if absent)
     */
    public ?string $created = null;

    /**
     * Modification date from /Params /ModDate as ISO 8601 string (null if absent)
     */
    public ?string $modified = null;

    /**
     * MD5 checksum from /Params /CheckSum as hex string (null if absent)
     *
     * Per PDF spec, /CheckSum is a 16-byte binary string (MD5), hex-encoded
     * as 32 lowercase hex characters.
     */
    public ?string $checksum_md5 = null;

    /**
     * Base64-encoded attachment content (null if truncated or empty)
     *
     * - Some(base64_string) when content <= 50 MB
     * - None when `truncated: true` (content too large)
     */
    public ?string $data = null;

    /**
     * Whether the attachment content was truncated due to the 50 MB size limit
     *
     * When true, the `data` field is null and only metadata is included.
     * The `size` field still reflects the original full size.
     */
    public bool $truncated;

    /**
     * Create Attachment from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $attachment = new self();
        $attachment->name = $data['name'];
        $attachment->description = $data['description'] ?? null;
        $attachment->mime_type = $data['mime_type'] ?? null;
        $attachment->size = $data['size'];
        $attachment->created = $data['created'] ?? null;
        $attachment->modified = $data['modified'] ?? null;
        $attachment->checksum_md5 = $data['checksum_md5'] ?? null;
        $attachment->data = $data['data'] ?? null;
        $attachment->truncated = $data['truncated'] ?? false;

        return $attachment;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'size' => $this->size,
            'truncated' => $this->truncated,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->mime_type !== null) {
            $data['mime_type'] = $this->mime_type;
        }

        if ($this->created !== null) {
            $data['created'] = $this->created;
        }

        if ($this->modified !== null) {
            $data['modified'] = $this->modified;
        }

        if ($this->checksum_md5 !== null) {
            $data['checksum_md5'] = $this->checksum_md5;
        }

        if ($this->data !== null) {
            $data['data'] = $this->data;
        }

        return $data;
    }
}
