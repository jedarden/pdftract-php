<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a table row
 *
 * A row contains a sequence of cells that form a horizontal strip
 * in the table.
 */
class Row
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
     * Cells in this row, ordered left-to-right
     *
     * @var array<Cell>
     */
    public array $cells;

    /**
     * Whether this row is a header row
     *
     * Header rows are typically repeated when tables span multiple pages.
     */
    public bool $is_header;

    /**
     * Create Row from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $row = new self();
        $row->bbox = $data['bbox'];
        $row->is_header = $data['is_header'];

        foreach ($data['cells'] ?? [] as $item) {
            $row->cells[] = Cell::fromArray($item);
        }

        return $row;
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
            'cells' => array_map(fn($c) => $c->toArray(), $this->cells),
            'is_header' => $this->is_header,
        ];
    }
}
