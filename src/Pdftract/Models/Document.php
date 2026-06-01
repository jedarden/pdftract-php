<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

use Jedarden\Pdftract\Models\Metadata;
use Jedarden\Pdftract\Models\Page;
use Jedarden\Pdftract\Models\Fingerprint;
use Jedarden\Pdftract\Models\Classification;
use Jedarden\Pdftract\Models\ExtractionQuality;
use Jedarden\Pdftract\Models\Diagnostic;

/**
 * Top-level output structure for PDF extraction
 *
 * This is the canonical JSON output format, containing document-level
 * metadata and an array of page objects.
 */
class Document
{
    /**
     * Schema version identifier (e.g., "1.0")
     */
    public string $schema_version;

    /**
     * Document-level metadata
     */
    public Metadata $metadata;

    /**
     * Document outline (bookmark tree)
     *
     * @var array<OutlineNode>
     */
    public array $outline = [];

    /**
     * Article thread chains
     *
     * @var array<Thread>
     */
    public array $threads = [];

    /**
     * Embedded file attachments
     *
     * @var array<Attachment>
     */
    public array $attachments = [];

    /**
     * Digital signature metadata
     *
     * @var array<Signature>
     */
    public array $signatures = [];

    /**
     * AcroForm/XFA form fields
     *
     * @var array<FormField>
     */
    public array $form_fields = [];

    /**
     * Document-scoped hyperlinks
     *
     * @var array<Link>
     */
    public array $links = [];

    /**
     * Page objects array
     *
     * @var array<Page>
     */
    public array $pages;

    /**
     * Aggregate extraction quality metrics
     */
    public ExtractionQuality $extraction_quality;

    /**
     * All diagnostics emitted during extraction
     *
     * @var array<Diagnostic>
     */
    public array $errors = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->schema_version = '1.0';
        $this->metadata = new Metadata();
        $this->pages = [];
        $this->extraction_quality = new ExtractionQuality();
    }

    /**
     * Create Document from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $doc = new self();
        $doc->schema_version = $data['schema_version'] ?? '1.0';
        $doc->metadata = Metadata::fromArray($data['metadata'] ?? []);

        foreach ($data['outline'] ?? [] as $item) {
            $doc->outline[] = OutlineNode::fromArray($item);
        }

        foreach ($data['threads'] ?? [] as $item) {
            $doc->threads[] = Thread::fromArray($item);
        }

        foreach ($data['attachments'] ?? [] as $item) {
            $doc->attachments[] = Attachment::fromArray($item);
        }

        foreach ($data['signatures'] ?? [] as $item) {
            $doc->signatures[] = Signature::fromArray($item);
        }

        foreach ($data['form_fields'] ?? [] as $item) {
            $doc->form_fields[] = FormField::fromArray($item);
        }

        foreach ($data['links'] ?? [] as $item) {
            $doc->links[] = Link::fromArray($item);
        }

        foreach ($data['pages'] ?? [] as $item) {
            $doc->pages[] = Page::fromArray($item);
        }

        $doc->extraction_quality = ExtractionQuality::fromArray($data['extraction_quality'] ?? []);

        foreach ($data['errors'] ?? [] as $item) {
            $doc->errors[] = Diagnostic::fromArray($item);
        }

        return $doc;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'schema_version' => $this->schema_version,
            'metadata' => $this->metadata->toArray(),
            'pages' => array_map(fn($p) => $p->toArray(), $this->pages),
            'extraction_quality' => $this->extraction_quality->toArray(),
            'errors' => array_map(fn($e) => $e->toArray(), $this->errors),
        ];

        if (!empty($this->outline)) {
            $data['outline'] = array_map(fn($o) => $o->toArray(), $this->outline);
        }

        if (!empty($this->threads)) {
            $data['threads'] = array_map(fn($t) => $t->toArray(), $this->threads);
        }

        if (!empty($this->attachments)) {
            $data['attachments'] = array_map(fn($a) => $a->toArray(), $this->attachments);
        }

        if (!empty($this->signatures)) {
            $data['signatures'] = array_map(fn($s) => $s->toArray(), $this->signatures);
        }

        if (!empty($this->form_fields)) {
            $data['form_fields'] = array_map(fn($f) => $f->toArray(), $this->form_fields);
        }

        if (!empty($this->links)) {
            $data['links'] = array_map(fn($l) => $l->toArray(), $this->links);
        }

        return $data;
    }
}
