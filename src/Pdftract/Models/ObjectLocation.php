<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a PDF object reference
 *
 * Identifies a specific PDF indirect object by its object and generation numbers.
 */
class ObjectLocation
{
    /**
     * Object number (zero-based index in the xref table)
     */
    public int $object_number;

    /**
     * Generation number (incremented on each save)
     */
    public int $generation_number;

    /**
     * Create ObjectLocation from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $loc = new self();
        $loc->object_number = $data['object_number'];
        $loc->generation_number = $data['generation_number'];

        return $loc;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'object_number' => $this->object_number,
            'generation_number' => $this->generation_number,
        ];
    }
}
