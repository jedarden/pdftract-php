<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a hyperlink annotation
 *
 * Represents either a URI hyperlink (external link) or an internal destination
 * link (named or explicit destination within the same document).
 */
class Link
{
    /**
     * Zero-based page index containing this link
     */
    public int $page_index;

    /**
     * Bounding box in PDF user-space points
     *
     * Format: [x0, y0, x1, y1] where (x0, y0) is the bottom-left corner.
     *
     * @var array<float>
     */
    public array $rect;

    /**
     * The URI target for external links (from /A /S /URI /URI)
     *
     * Present for URI links and JavaScript actions (prefixed with "javascript:").
     * Null for internal destination links.
     */
    public ?string $uri = null;

    /**
     * The internal destination name (from /Dest as a name string)
     *
     * Present for named destination links. Null for URI links or explicit destinations.
     */
    public ?string $dest = null;

    /**
     * Explicit destination array (from /Dest as an array or resolved name tree)
     *
     * Present when the link target can be resolved to explicit coordinates.
     * Null for URI links or unresolved named destinations.
     */
    public ?DestArray $dest_array = null;

    /**
     * Create Link from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $link = new self();
        $link->page_index = $data['page_index'];
        $link->rect = $data['rect'];
        $link->uri = $data['uri'] ?? null;
        $link->dest = $data['dest'] ?? null;

        if (isset($data['dest_array']) && $data['dest_array'] !== null) {
            $link->dest_array = DestArray::fromArray($data['dest_array']);
        }

        return $link;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'page_index' => $this->page_index,
            'rect' => $this->rect,
        ];

        if ($this->uri !== null) {
            $data['uri'] = $this->uri;
        }

        if ($this->dest !== null) {
            $data['dest'] = $this->dest;
        }

        if ($this->dest_array !== null) {
            $data['dest_array'] = $this->dest_array->toArray();
        }

        return $data;
    }
}
