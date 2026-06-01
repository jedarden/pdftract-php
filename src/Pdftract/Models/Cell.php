<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a table cell
 *
 * A cell represents a single unit within a table row, containing
 * its text content, bounding box, and position information.
 */
class Cell
{
    /**
     * Bounding box in PDF user-space points
     *
     * Format: [x0, y0, x1, y1] where (x0, y0) is the bottom-left
     * corner and (x1, y1) is the top-right corner.
     *
     * @var array<float>
     */
    public array $bbox;

    /**
     * The concatenated text content of all spans in the cell
     */
    public string $text;

    /**
     * References to spans in the page's `spans` array
     *
     * These indices point to the spans that make up this cell's content.
     *
     * @var array<int>
     */
    public array $spans;

    /**
     * Zero-based row index within the table
     */
    public int $row;

    /**
     * Zero-based column index within the table
     */
    public int $col;

    /**
     * Number of rows this cell spans (default 1)
     *
     * Values greater than 1 indicate a merged cell that spans
     * multiple rows vertically.
     */
    public int $rowspan = 1;

    /**
     * Number of columns this cell spans (default 1)
     *
     * Values greater than 1 indicate a merged cell that spans
     * multiple columns horizontally.
     */
    public int $colspan = 1;

    /**
     * Whether this cell is in a header row
     *
     * Header cells are typically rendered differently (bold, centered)
     * and may be reused when tables span multiple pages.
     */
    public bool $is_header_row;

    /**
     * Create Cell from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $cell = new self();
        $cell->bbox = $data['bbox'];
        $cell->text = $data['text'];
        $cell->spans = $data['spans'];
        $cell->row = $data['row'];
        $cell->col = $data['col'];
        $cell->rowspan = $data['rowspan'] ?? 1;
        $cell->colspan = $data['colspan'] ?? 1;
        $cell->is_header_row = $data['is_header_row'];

        return $cell;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'bbox' => $this->bbox,
            'text' => $this->text,
            'spans' => $this->spans,
            'row' => $this->row,
            'col' => $this->col,
            'rowspan' => $this->rowspan,
            'colspan' => $this->colspan,
            'is_header_row' => $this->is_header_row,
        ];
    }
}
