<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a text span
 *
 * A span is the smallest unit of extracted text, representing a
 * contiguous run of text with consistent font and styling.
 */
class Span
{
    /**
     * The extracted text content
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
     * Font name or identifier
     */
    public string $font;

    /**
     * Font size in points
     */
    public float $size;

    /**
     * Fill color as CSS hex string (e.g., "#1a1a1a"), or null if not expressible as RGB
     *
     * Null for spot colors, patterns, or complex color spaces that cannot be
     * accurately represented as RGB hex.
     */
    public ?string $color = null;

    /**
     * PDF Tr operator value (0-7) indicating the text rendering mode
     *
     * 0 = fill, 1 = stroke, 2 = fill then stroke, 3 = invisible,
     * 4 = fill to clip, 5 = stroke to clip, 6 = fill then stroke to clip,
     * 7 = clip.
     */
    public ?int $rendering_mode = null;

    /**
     * Optional confidence score (0.0 to 1.0)
     *
     * This field is present when OCR is used or when the extraction
     * has uncertainty about the text. When confidence is not applicable,
     * this field is null.
     */
    public ?float $confidence = null;

    /**
     * Source of the confidence/text extraction
     *
     * One of: "vector" (native font decoding), "ocr" (pure OCR),
     * "ocr-assisted" (OCR + vector correction), "ocr-fallback" (region-level fallback),
     * "repaired" (text was repaired via heuristics).
     */
    public ?string $confidence_source = null;

    /**
     * BCP-47 language tag if detected, otherwise null
     *
     * Examples: "en", "en-US", "zh-Hans". Null when language detection
     * is not available or not applicable.
     */
    public ?string $lang = null;

    /**
     * Set of style flags applied to this span
     *
     * Possible values: "bold", "italic", "smallcaps", "subscript", "superscript"
     *
     * @var array<string>
     */
    public array $flags = [];

    /**
     * Optional cryptographic receipt for verification
     *
     * This field is present when `--receipts=lite` or `--receipts=svg`
     * is enabled. When receipts are disabled, the field is null.
     */
    public ?Receipt $receipt = null;

    /**
     * Column index (0-based) assigned by Phase 4.3 column detection
     *
     * This field is null for spans outside any detected column
     * (e.g., full-width headings, inter-column gaps).
     */
    public ?int $column = null;

    /**
     * Create Span from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $span = new self();
        $span->text = $data['text'];
        $span->bbox = $data['bbox'];
        $span->font = $data['font'];
        $span->size = $data['size'];
        $span->color = $data['color'] ?? null;
        $span->rendering_mode = $data['rendering_mode'] ?? null;
        $span->confidence = $data['confidence'] ?? null;
        $span->confidence_source = $data['confidence_source'] ?? null;
        $span->lang = $data['lang'] ?? null;
        $span->flags = $data['flags'] ?? [];
        $span->column = $data['column'] ?? null;

        if (isset($data['receipt']) && $data['receipt'] !== null) {
            $span->receipt = Receipt::fromArray($data['receipt']);
        }

        return $span;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'text' => $this->text,
            'bbox' => $this->bbox,
            'font' => $this->font,
            'size' => $this->size,
            'flags' => $this->flags,
        ];

        if ($this->color !== null) {
            $data['color'] = $this->color;
        }

        if ($this->rendering_mode !== null) {
            $data['rendering_mode'] = $this->rendering_mode;
        }

        if ($this->confidence !== null) {
            $data['confidence'] = $this->confidence;
        }

        if ($this->confidence_source !== null) {
            $data['confidence_source'] = $this->confidence_source;
        }

        if ($this->lang !== null) {
            $data['lang'] = $this->lang;
        }

        if ($this->column !== null) {
            $data['column'] = $this->column;
        }

        if ($this->receipt !== null) {
            $data['receipt'] = $this->receipt->toArray();
        }

        return $data;
    }
}
