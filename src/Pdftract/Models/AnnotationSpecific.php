<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of subtype-specific annotation fields
 */
class AnnotationSpecific
{
    /**
     * The kind of annotation
     */
    public string $kind;

    /**
     * For TextMarkup: array of 8-element quadpoint arrays
     *
     * @var array<array<float>>|null
     */
    public ?array $quads = null;

    /**
     * For Stamp: icon name (e.g., "Approved", "Draft", "Confidential")
     */
    public ?string $name = null;

    /**
     * For FreeText: default appearance string
     */
    public ?string $da = null;

    /**
     * For Text (sticky note): whether the note is initially open
     */
    public ?bool $open = null;

    /**
     * For Text (sticky note): note state
     */
    public ?string $state = null;

    /**
     * For Text (sticky note): state model name
     */
    public ?string $state_model = null;

    /**
     * For Ink: stroke paths as sequences of (x, y) coordinates
     *
     * @var array<array<array<float>>>|null
     */
    public ?array $strokes = null;

    /**
     * For Line: line endpoints as [x0, y0, x1, y1]
     *
     * @var array<float>|null
     */
    public ?array $endpoints = null;

    /**
     * For Polygon/PolyLine: vertices as sequences of (x, y) coordinates
     *
     * @var array<array<float>>|null
     */
    public ?array $vertices = null;

    /**
     * For FileAttachment: file specification reference
     */
    public ?int $fs_ref = null;

    /**
     * Create AnnotationSpecific from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $specific = new self();
        $specific->kind = $data['kind'] ?? 'other';
        $specific->quads = $data['quads'] ?? null;
        $specific->name = $data['name'] ?? null;
        $specific->da = $data['da'] ?? null;
        $specific->open = $data['open'] ?? null;
        $specific->state = $data['state'] ?? null;
        $specific->state_model = $data['state_model'] ?? null;
        $specific->strokes = $data['strokes'] ?? null;
        $specific->endpoints = $data['endpoints'] ?? null;
        $specific->vertices = $data['vertices'] ?? null;
        $specific->fs_ref = $data['fs_ref'] ?? null;

        return $specific;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'kind' => $this->kind,
        ];

        if ($this->quads !== null) {
            $data['quads'] = $this->quads;
        }

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        if ($this->da !== null) {
            $data['da'] = $this->da;
        }

        if ($this->open !== null) {
            $data['open'] = $this->open;
        }

        if ($this->state !== null) {
            $data['state'] = $this->state;
        }

        if ($this->state_model !== null) {
            $data['state_model'] = $this->state_model;
        }

        if ($this->strokes !== null) {
            $data['strokes'] = $this->strokes;
        }

        if ($this->endpoints !== null) {
            $data['endpoints'] = $this->endpoints;
        }

        if ($this->vertices !== null) {
            $data['vertices'] = $this->vertices;
        }

        if ($this->fs_ref !== null) {
            $data['fs_ref'] = $this->fs_ref;
        }

        return $data;
    }
}
