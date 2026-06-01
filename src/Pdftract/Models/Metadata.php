<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of document metadata
 *
 * Contains all standard PDF document information dictionary fields along
 * with derived signals from the document catalog.
 */
class Metadata
{
    /**
     * PDF /Title - document title
     */
    public ?string $title = null;

    /**
     * PDF /Author - name of the person who created the document
     */
    public ?string $author = null;

    /**
     * PDF /Subject - subject matter summary
     */
    public ?string $subject = null;

    /**
     * PDF /Keywords - space- or comma-delimited keyword list
     */
    public ?string $keywords = null;

    /**
     * PDF /Creator - the authoring application (e.g., "Microsoft Word 2019")
     */
    public ?string $creator = null;

    /**
     * PDF /Producer - the PDF-writing library (e.g., "Acrobat Distiller 23.0")
     */
    public ?string $producer = null;

    /**
     * PDF /CreationDate - ISO-8601 string from /CreationDate
     */
    public ?string $creation_date = null;

    /**
     * PDF /ModDate - ISO-8601 string from /ModDate
     */
    public ?string $modification_date = null;

    /**
     * Total number of pages in the document
     */
    public int $page_count;

    /**
     * PDF version (e.g., "1.7", "2.0")
     */
    public ?string $pdf_version = null;

    /**
     * True if /MarkInfo /Marked: true is present
     */
    public bool $is_tagged;

    /**
     * True if document is encrypted
     */
    public bool $is_encrypted;

    /**
     * PDF/A or PDF/UA conformance level
     *
     * One of: "none", "PDF-A-1a", "PDF-A-1b", "PDF-A-2a", "PDF-A-2b", "PDF-A-2u",
     * "PDF-A-3a", "PDF-A-3b", "PDF-A-3u", "PDF-UA-1", "PDF-UA-2", "PDF-X-1a"
     */
    public string $conformance = 'none';

    /**
     * True if JavaScript actions are present in the document
     */
    public bool $contains_javascript;

    /**
     * JavaScript actions found in the document
     *
     * Per TH-04, this array contains all discovered JavaScript actions
     * with their location and code excerpt. Empty when no JS is present.
     *
     * @var array<JavascriptAction>
     */
    public array $javascript_actions = [];

    /**
     * True if XFA forms are present
     */
    public bool $contains_xfa;

    /**
     * True if optional content groups (layers) are present
     */
    public bool $ocg_present;

    /**
     * Heuristic string identifying the producing application
     */
    public ?string $generator = null;

    /**
     * Create Metadata from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $metadata = new self();
        $metadata->title = $data['title'] ?? null;
        $metadata->author = $data['author'] ?? null;
        $metadata->subject = $data['subject'] ?? null;
        $metadata->keywords = $data['keywords'] ?? null;
        $metadata->creator = $data['creator'] ?? null;
        $metadata->producer = $data['producer'] ?? null;
        $metadata->creation_date = $data['creation_date'] ?? null;
        $metadata->modification_date = $data['modification_date'] ?? null;
        $metadata->page_count = $data['page_count'] ?? 0;
        $metadata->pdf_version = $data['pdf_version'] ?? null;
        $metadata->is_tagged = $data['is_tagged'] ?? false;
        $metadata->is_encrypted = $data['is_encrypted'] ?? false;
        $metadata->conformance = $data['conformance'] ?? 'none';
        $metadata->contains_javascript = $data['contains_javascript'] ?? false;
        $metadata->contains_xfa = $data['contains_xfa'] ?? false;
        $metadata->ocg_present = $data['ocg_present'] ?? false;
        $metadata->generator = $data['generator'] ?? null;

        foreach ($data['javascript_actions'] ?? [] as $item) {
            $metadata->javascript_actions[] = JavascriptAction::fromArray($item);
        }

        return $metadata;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'page_count' => $this->page_count,
            'is_tagged' => $this->is_tagged,
            'is_encrypted' => $this->is_encrypted,
            'conformance' => $this->conformance,
            'contains_javascript' => $this->contains_javascript,
            'javascript_actions' => array_map(fn($j) => $j->toArray(), $this->javascript_actions),
            'contains_xfa' => $this->contains_xfa,
            'ocg_present' => $this->ocg_present,
        ];

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        if ($this->author !== null) {
            $data['author'] = $this->author;
        }

        if ($this->subject !== null) {
            $data['subject'] = $this->subject;
        }

        if ($this->keywords !== null) {
            $data['keywords'] = $this->keywords;
        }

        if ($this->creator !== null) {
            $data['creator'] = $this->creator;
        }

        if ($this->producer !== null) {
            $data['producer'] = $this->producer;
        }

        if ($this->creation_date !== null) {
            $data['creation_date'] = $this->creation_date;
        }

        if ($this->modification_date !== null) {
            $data['modification_date'] = $this->modification_date;
        }

        if ($this->pdf_version !== null) {
            $data['pdf_version'] = $this->pdf_version;
        }

        if ($this->generator !== null) {
            $data['generator'] = $this->generator;
        }

        return $data;
    }
}
