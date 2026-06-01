<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a destination anchor
 *
 * Describes a specific location within a PDF page.
 */
class Destination
{
    /**
     * Destination type: "xyz", "fit", "fith", "fitv", "fitr", "fitb", "fitbh", "fitbv"
     */
    public string $type;

    /**
     * Left coordinate (user-space points), present for "xyz", "fitv", "fitr", "fitbv"
     */
    public ?float $left = null;

    /**
     * Top coordinate (user-space points), present for "xyz", "fith", "fitr", "fitbh"
     */
    public ?float $top = null;

    /**
     * Right coordinate (user-space points), present only for "fitr"
     */
    public ?float $right = null;

    /**
     * Bottom coordinate (user-space points), present only for "fitr"
     */
    public ?float $bottom = null;

    /**
     * Zoom factor, present only for "xyz"
     */
    public ?float $zoom = null;

    /**
     * Create Destination from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $dest = new self();
        $dest->type = $data['type'];
        $dest->left = $data['left'] ?? null;
        $dest->top = $data['top'] ?? null;
        $dest->right = $data['right'] ?? null;
        $dest->bottom = $data['bottom'] ?? null;
        $dest->zoom = $data['zoom'] ?? null;

        return $dest;
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

        if ($this->left !== null) {
            $data['left'] = $this->left;
        }

        if ($this->top !== null) {
            $data['top'] = $this->top;
        }

        if ($this->right !== null) {
            $data['right'] = $this->right;
        }

        if ($this->bottom !== null) {
            $data['bottom'] = $this->bottom;
        }

        if ($this->zoom !== null) {
            $data['zoom'] = $this->zoom;
        }

        return $data;
    }
}
