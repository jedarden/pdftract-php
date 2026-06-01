<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a destination type
 *
 * Uses a "fit" field for unambiguous variant discrimination.
 */
class DestType
{
    /**
     * The destination fit type: "xyz", "fit", "fith", "fitv", "fitr", "fitb", "fitbh", "fitbv"
     */
    public string $fit;

    /**
     * For xyz: left coordinate (null = retain current left)
     */
    public ?float $left = null;

    /**
     * For xyz/fith/fitr/fitbh: top coordinate (null = retain current)
     */
    public ?float $top = null;

    /**
     * For xyz/fitv/fitr/fitbv: left coordinate (null = retain current left)
     */
    public ?float $bottom = null;

    /**
     * For fitr: right edge of rectangle
     */
    public ?float $right = null;

    /**
     * For xyz: zoom factor (null = retain current zoom)
     */
    public ?float $zoom = null;

    /**
     * Create DestType from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $destType = new self();
        $destType->fit = $data['fit'] ?? 'fit';
        $destType->left = $data['left'] ?? null;
        $destType->top = $data['top'] ?? null;
        $destType->bottom = $data['bottom'] ?? null;
        $destType->right = $data['right'] ?? null;
        $destType->zoom = $data['zoom'] ?? null;

        return $destType;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'fit' => $this->fit,
        ];

        if ($this->left !== null) {
            $data['left'] = $this->left;
        }

        if ($this->top !== null) {
            $data['top'] = $this->top;
        }

        if ($this->bottom !== null) {
            $data['bottom'] = $this->bottom;
        }

        if ($this->right !== null) {
            $data['right'] = $this->right;
        }

        if ($this->zoom !== null) {
            $data['zoom'] = $this->zoom;
        }

        return $data;
    }
}
