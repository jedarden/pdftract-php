<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a diagnostic error
 *
 * This struct wraps the internal Diagnostic type for JSON serialization,
 * providing stable error codes and human-readable messages for consumers.
 */
class Diagnostic
{
    /**
     * Stable string identifier for this diagnostic (e.g., "FONT_GLYPH_UNMAPPED")
     */
    public string $code;

    /**
     * Human-readable description of the diagnostic
     */
    public string $message;

    /**
     * Severity level: "info", "warning", "error", or "fatal"
     */
    public string $severity;

    /**
     * Page index where this diagnostic occurred, or null for document-level events
     */
    public ?int $page_index = null;

    /**
     * PDF object reference where the issue originated, if applicable
     */
    public ?ObjectLocation $location = null;

    /**
     * Optional hint for resolving the diagnostic
     *
     * Example: "Install Tesseract for OCR recovery"
     */
    public ?string $hint = null;

    /**
     * Create Diagnostic from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $diag = new self();
        $diag->code = $data['code'];
        $diag->message = $data['message'];
        $diag->severity = $data['severity'];
        $diag->page_index = $data['page_index'] ?? null;
        $diag->hint = $data['hint'] ?? null;

        if (isset($data['location']) && $data['location'] !== null) {
            $diag->location = ObjectLocation::fromArray($data['location']);
        }

        return $diag;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
        ];

        if ($this->page_index !== null) {
            $data['page_index'] = $this->page_index;
        }

        if ($this->location !== null) {
            $data['location'] = $this->location->toArray();
        }

        if ($this->hint !== null) {
            $data['hint'] = $this->hint;
        }

        return $data;
    }
}
