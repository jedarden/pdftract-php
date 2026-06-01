<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a non-link annotation
 *
 * Represents markup annotations like highlights, text notes, stamps,
 * and other non-link annotations.
 */
class Annotation
{
    /**
     * Annotation subtype (e.g., "Text", "Highlight", "Stamp", "FreeText")
     */
    public string $type;

    /**
     * Bounding box in PDF user-space points
     *
     * Format: [x0, y0, x1, y1] where (x0, y0) is the bottom-left corner.
     * Null if the /Rect entry is missing or invalid.
     *
     * @var array<float>|null
     */
    public ?array $rect = null;

    /**
     * The annotation's content text (from /Contents)
     */
    public ?string $contents = null;

    /**
     * The annotation's author (from /T)
     */
    public ?string $author = null;

    /**
     * The modification date (from /M) as an ISO 8601 string
     */
    public ?string $modified = null;

    /**
     * The color array (from /C) as RGB/Grayscale components
     *
     * Null if /C is missing. Length is 1 (grayscale), 3 (RGB), or 4 (CMYK).
     *
     * @var array<float>|null
     */
    public ?array $color = null;

    /**
     * The opacity (from /CA)
     */
    public ?float $opacity = null;

    /**
     * The name identifier (from /NM)
     */
    public ?string $name_id = null;

    /**
     * The subject (from /Subj)
     */
    public ?string $subject = null;

    /**
     * Subtype-specific fields
     *
     * @var AnnotationSpecific|null
     */
    public $specific = null;

    /**
     * Create Annotation from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $annotation = new self();
        $annotation->type = $data['type'];
        $annotation->rect = $data['rect'] ?? null;
        $annotation->contents = $data['contents'] ?? null;
        $annotation->author = $data['author'] ?? null;
        $annotation->modified = $data['modified'] ?? null;
        $annotation->color = $data['color'] ?? null;
        $annotation->opacity = $data['opacity'] ?? null;
        $annotation->name_id = $data['name_id'] ?? null;
        $annotation->subject = $data['subject'] ?? null;

        if (isset($data['specific']) && $data['specific'] !== null) {
            $annotation->specific = AnnotationSpecific::fromArray($data['specific']);
        }

        return $annotation;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
        ];

        if ($this->rect !== null) {
            $data['rect'] = $this->rect;
        }

        if ($this->contents !== null) {
            $data['contents'] = $this->contents;
        }

        if ($this->author !== null) {
            $data['author'] = $this->author;
        }

        if ($this->modified !== null) {
            $data['modified'] = $this->modified;
        }

        if ($this->color !== null) {
            $data['color'] = $this->color;
        }

        if ($this->opacity !== null) {
            $data['opacity'] = $this->opacity;
        }

        if ($this->name_id !== null) {
            $data['name_id'] = $this->name_id;
        }

        if ($this->subject !== null) {
            $data['subject'] = $this->subject;
        }

        if ($this->specific !== null) {
            $data['specific'] = $this->specific->toArray();
        }

        return $data;
    }
}
