<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a table
 *
 * Tables are emitted in parallel with table blocks - the block
 * provides the concatenated text and position, while the Table
 * provides full cell-level structure.
 */
class Table
{
    /**
     * Unique identifier for this table (e.g., "table_0")
     */
    public string $id;

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
     * Rows in this table, ordered top-to-bottom
     *
     * @var array<Row>
     */
    public array $rows;

    /**
     * Number of contiguous header rows at the top of the table
     *
     * Header rows are typically repeated when tables span multiple pages.
     */
    public int $header_rows;

    /**
     * Detection method used to identify this table
     *
     * - "line_based": Table detected via ruling lines (borders)
     * - "borderless": Table detected via x0 alignment heuristics
     */
    public string $detection_method;

    /**
     * Whether this table continues on the next page
     *
     * Set to true when a table is split across pages and this
     * page contains the first part.
     */
    public bool $continued;

    /**
     * Whether this table is a continuation from the previous page
     *
     * Set to true when a table is split across pages and this
     * page contains a subsequent part.
     */
    public bool $continued_from_prev;

    /**
     * Zero-based page index where this table appears
     */
    public int $page_index;

    /**
     * Create Table from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $table = new self();
        $table->id = $data['id'];
        $table->bbox = $data['bbox'];
        $table->header_rows = $data['header_rows'];
        $table->detection_method = $data['detection_method'];
        $table->continued = $data['continued'];
        $table->continued_from_prev = $data['continued_from_prev'];
        $table->page_index = $data['page_index'];

        foreach ($data['rows'] ?? [] as $item) {
            $table->rows[] = Row::fromArray($item);
        }

        return $table;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'bbox' => $this->bbox,
            'rows' => array_map(fn($r) => $r->toArray(), $this->rows),
            'header_rows' => $this->header_rows,
            'detection_method' => $this->detection_method,
            'continued' => $this->continued,
            'continued_from_prev' => $this->continued_from_prev,
            'page_index' => $this->page_index,
        ];
    }
}
