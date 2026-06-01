<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * Extraction quality metrics for the document
 *
 * This structure appears in the document footer (NDJSON mode) or
 * in the root metadata (full JSON mode). It provides aggregate
 * quality signals across all pages.
 */
class ExtractionQuality
{
    /**
     * Overall quality assessment: "high", "medium", "low", or "none"
     *
     * - "high": All pages extracted successfully with high confidence
     * - "medium": Most pages extracted, some with lower confidence
     * - "low": Significant extraction issues (many low-confidence pages)
     * - "none": No extractable content found (all blank pages)
     */
    public string $overall_quality;

    /**
     * DPI used for OCR rendering (Phase 5.2)
     *
     * This field records the DPI selected by the automatic DPI selection
     * algorithm (or the user-specified override). It is present when OCR
     * was performed on any page.
     *
     * Values: 200 (JBIG2), 300 (standard), 400 (fine print), or custom
     */
    public ?int $dpi_used = null;

    /**
     * Fraction of pages that required OCR fallback [0.0, 1.0]
     *
     * This is the count of pages classified as "scanned" or "mixed"
     * divided by the total page count.
     */
    public ?float $ocr_fraction = null;

    /**
     * Minimum confidence score across all spans [0.0, 1.0]
     *
     * This represents the weakest link in the extraction chain.
     */
    public ?float $min_confidence = null;

    /**
     * Average confidence score across all spans [0.0, 1.0]
     */
    public ?float $avg_confidence = null;

    /**
     * Per-page readability score (char-weighted median of span scores) [0.0, 1.0]
     *
     * This is the median of per-span readability scores, weighted by character count.
     * A score below 0.5 may indicate mojibake, encoding issues, or broken text layers.
     */
    public ?float $readability = null;

    /**
     * Create ExtractionQuality from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $quality = new self();
        $quality->overall_quality = $data['overall_quality'] ?? 'none';
        $quality->dpi_used = $data['dpi_used'] ?? null;
        $quality->ocr_fraction = $data['ocr_fraction'] ?? null;
        $quality->min_confidence = $data['min_confidence'] ?? null;
        $quality->avg_confidence = $data['avg_confidence'] ?? null;
        $quality->readability = $data['readability'] ?? null;

        return $quality;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'overall_quality' => $this->overall_quality,
        ];

        if ($this->dpi_used !== null) {
            $data['dpi_used'] = $this->dpi_used;
        }

        if ($this->ocr_fraction !== null) {
            $data['ocr_fraction'] = $this->ocr_fraction;
        }

        if ($this->min_confidence !== null) {
            $data['min_confidence'] = $this->min_confidence;
        }

        if ($this->avg_confidence !== null) {
            $data['avg_confidence'] = $this->avg_confidence;
        }

        if ($this->readability !== null) {
            $data['readability'] = $this->readability;
        }

        return $data;
    }
}
