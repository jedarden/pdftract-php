<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a structural block
 *
 * A block is a higher-level semantic unit composed of one or more
 * spans. Examples include paragraphs, headings, list items, and
 * table cells.
 */
class Block
{
    /**
     * The block kind/type
     *
     * Common values: "paragraph", "heading", "list", "table", "figure"
     */
    public string $kind;

    /**
     * The concatenated text content of all spans in the block
     */
    public string $text;

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
     * Optional heading level (1-6) for "heading" kind blocks
     *
     * This field is present only for heading blocks. For paragraphs
     * and other block types, it is null.
     */
    public ?int $level = null;

    /**
     * Optional table index for "table" kind blocks
     *
     * This field is present only for table blocks and points to the
     * corresponding entry in the page's `tables` array.
     */
    public ?int $table_index = null;

    /**
     * References to spans in the page's `spans` array
     *
     * These indices point to the spans that make up this block's content.
     *
     * @var array<int>
     */
    public array $spans = [];

    /**
     * Optional cryptographic receipt for verification
     *
     * This field is present when `--receipts=lite` or `--receipts=svg`
     * is enabled. When receipts are disabled, the field is null.
     */
    public ?Receipt $receipt = null;

    /**
     * Create Block from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $block = new self();
        $block->kind = $data['kind'];
        $block->text = $data['text'];
        $block->bbox = $data['bbox'];
        $block->level = $data['level'] ?? null;
        $block->table_index = $data['table_index'] ?? null;
        $block->spans = $data['spans'] ?? [];

        if (isset($data['receipt']) && $data['receipt'] !== null) {
            $block->receipt = Receipt::fromArray($data['receipt']);
        }

        return $block;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'kind' => $this->kind,
            'text' => $this->text,
            'bbox' => $this->bbox,
            'spans' => $this->spans,
        ];

        if ($this->level !== null) {
            $data['level'] = $this->level;
        }

        if ($this->table_index !== null) {
            $data['table_index'] = $this->table_index;
        }

        if ($this->receipt !== null) {
            $data['receipt'] = $this->receipt->toArray();
        }

        return $data;
    }
}
