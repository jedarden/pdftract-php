<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of an outline node (bookmark)
 *
 * Represents a single node in the document's outline hierarchy, with support
 * for nested children via the `children` field.
 */
class OutlineNode
{
    /**
     * The outline title text (decoded to UTF-8)
     */
    public string $title;

    /**
     * Hierarchical level in the outline tree (0-based, root is 0)
     */
    public int $level;

    /**
     * Zero-based page index this outline points to, if resolved
     */
    public ?int $page_index = null;

    /**
     * Destination type and coordinates within the page
     */
    public ?Destination $destination = null;

    /**
     * Nested child outlines (empty array for leaf nodes)
     *
     * @var array<OutlineNode>
     */
    public array $children = [];

    /**
     * Create OutlineNode from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $node = new self();
        $node->title = $data['title'];
        $node->level = $data['level'];
        $node->page_index = $data['page_index'] ?? null;

        if (isset($data['destination']) && $data['destination'] !== null) {
            $node->destination = Destination::fromArray($data['destination']);
        }

        foreach ($data['children'] ?? [] as $item) {
            $node->children[] = self::fromArray($item);
        }

        return $node;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'title' => $this->title,
            'level' => $this->level,
            'children' => array_map(fn($c) => $c->toArray(), $this->children),
        ];

        if ($this->page_index !== null) {
            $data['page_index'] = $this->page_index;
        }

        if ($this->destination !== null) {
            $data['destination'] = $this->destination->toArray();
        }

        return $data;
    }
}
