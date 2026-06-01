<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * A single bead in an article thread chain
 *
 * Represents one bead's position on a page, extracted during bead chain walking.
 * Per PDF 1.7 Section 12.4.3, each bead contains a reference to its page and
 * a bounding rectangle defining the article region on that page.
 */
class Bead
{
    /**
     * 0-based page index where this bead is located
     */
    public int $page_index;

    /**
     * Bounding rectangle in PDF user-space coordinates [x0, y0, x1, y1]
     *
     * Per PDF spec, the origin is at the bottom-left corner of the page.
     * This rect is NOT flipped to image-space coordinates.
     *
     * @var array<float>
     */
    public array $rect;

    /**
     * Create Bead from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $bead = new self();
        $bead->page_index = $data['page_index'];
        $bead->rect = $data['rect'];

        return $bead;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'page_index' => $this->page_index,
            'rect' => $this->rect,
        ];
    }
}
