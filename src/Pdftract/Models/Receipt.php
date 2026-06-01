<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * Cryptographic receipt for verification
 *
 * This represents a receipt that can be used to verify the provenance
 * of extracted content. Present when `--receipts=lite` or `--receipts=svg`
 * is enabled.
 */
class Receipt
{
    /**
     * PDF fingerprint identifier
     */
    public string $pdf_fingerprint;

    /**
     * Zero-based page index where the content appears
     */
    public int $page_index;

    /**
     * Bounding box in PDF user-space points
     *
     * Format: [x0, y0, x1, y1]
     *
     * @var array<float>
     */
    public array $bbox;

    /**
     * Content hash for verification
     */
    public string $content_hash;

    /**
     * Create Receipt from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $receipt = new self();
        $receipt->pdf_fingerprint = $data['pdf_fingerprint'];
        $receipt->page_index = $data['page_index'];
        $receipt->bbox = $data['bbox'];
        $receipt->content_hash = $data['content_hash'];

        return $receipt;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'pdf_fingerprint' => $this->pdf_fingerprint,
            'page_index' => $this->page_index,
            'bbox' => $this->bbox,
            'content_hash' => $this->content_hash,
        ];
    }
}
