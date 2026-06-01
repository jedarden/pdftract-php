<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a single page
 *
 * Contains all page-level fields including geometry, classification,
 * and content arrays (spans, blocks, tables, annotations).
 */
class Page
{
    /**
     * Zero-based page index, canonical for programmatic use
     */
    public int $page_index;

    /**
     * One-based page number (= page_index + 1)
     */
    public int $page_number;

    /**
     * Human-readable label from PDF /PageLabels number tree
     *
     * Examples: "iv", "A-3", "1". Null if the PDF defines no page labels.
     */
    public ?string $page_label = null;

    /**
     * Page width in points (1/72 inch)
     */
    public float $width;

    /**
     * Page height in points (1/72 inch)
     */
    public float $height;

    /**
     * Page rotation in degrees clockwise (0, 90, 180, or 270)
     */
    public int $rotation;

    /**
     * Page classification from the page classifier
     *
     * One of: "text", "scanned", "mixed", "broken_vector", "blank", "figure_only"
     */
    public string $type;

    /**
     * Text spans (atomic units with consistent font and styling)
     *
     * @var array<Span>
     */
    public array $spans = [];

    /**
     * Semantic blocks (paragraphs, headings, lists, tables, etc.)
     *
     * @var array<Block>
     */
    public array $blocks = [];

    /**
     * Parallel table structure objects
     *
     * @var array<Table>
     */
    public array $tables = [];

    /**
     * Page-level annotations (highlights, stamps, notes, links)
     *
     * @var array<Annotation>
     */
    public array $annotations = [];

    /**
     * Create Page from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $page = new self();
        $page->page_index = $data['page_index'];
        $page->page_number = $data['page_number'];
        $page->page_label = $data['page_label'] ?? null;
        $page->width = $data['width'];
        $page->height = $data['height'];
        $page->rotation = $data['rotation'];
        $page->type = $data['type'];

        foreach ($data['spans'] ?? [] as $item) {
            $page->spans[] = Span::fromArray($item);
        }

        foreach ($data['blocks'] ?? [] as $item) {
            $page->blocks[] = Block::fromArray($item);
        }

        foreach ($data['tables'] ?? [] as $item) {
            $page->tables[] = Table::fromArray($item);
        }

        foreach ($data['annotations'] ?? [] as $item) {
            $page->annotations[] = Annotation::fromArray($item);
        }

        return $page;
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
            'page_number' => $this->page_number,
            'width' => $this->width,
            'height' => $this->height,
            'rotation' => $this->rotation,
            'type' => $this->type,
            'spans' => array_map(fn($s) => $s->toArray(), $this->spans),
            'blocks' => array_map(fn($b) => $b->toArray(), $this->blocks),
            'tables' => array_map(fn($t) => $t->toArray(), $this->tables),
            'annotations' => array_map(fn($a) => $a->toArray(), $this->annotations),
        ];

        if ($this->page_label !== null) {
            $data['page_label'] = $this->page_label;
        }

        return $data;
    }
}
