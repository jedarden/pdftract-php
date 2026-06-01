<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a JavaScript action found in a PDF
 *
 * Represents a single JavaScript action discovered during extraction.
 * Per TH-04, pdftract NEVER executes embedded JavaScript; this struct
 * surfaces the JS for downstream security review.
 */
class JavascriptAction
{
    /**
     * Location of the JavaScript action in the PDF structure
     *
     * Examples: "catalog.openaction", "page.0.aa.O", "page.1.annot.0.A".
     * The format is: `<scope>`.`<index>`.`<path>` where scope is "catalog" or "page",
     * index is the page number (for pages), and path is the dot-joined entry path.
     */
    public string $location;

    /**
     * Truncated excerpt of the JavaScript code (first 200 characters)
     *
     * The excerpt is JSON-escaped and HTML-escaped if rendered in a web context.
     * This field contains the raw JS text for review, NOT executable code.
     */
    public string $code_excerpt;

    /**
     * Create JavascriptAction from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $action = new self();
        $action->location = $data['location'];
        $action->code_excerpt = $data['code_excerpt'];

        return $action;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'location' => $this->location,
            'code_excerpt' => $this->code_excerpt,
        ];
    }
}
