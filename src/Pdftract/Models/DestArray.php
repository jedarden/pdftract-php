<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of an explicit destination array
 *
 * Describes a specific location within a PDF page.
 */
class DestArray
{
    /**
     * Zero-based page index within the document
     */
    public int $page_index;

    /**
     * Destination type and coordinates
     */
    public DestType $dest;

    /**
     * Create DestArray from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $destArray = new self();
        $destArray->page_index = $data['page_index'];
        $destArray->dest = DestType::fromArray($data);

        return $destArray;
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
        ];

        // Merge dest type data
        $destData = $this->dest->toArray();
        foreach ($destData as $key => $value) {
            $data[$key] = $value;
        }

        return $data;
    }
}
