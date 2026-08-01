<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * Page model representing a single page in a PDF document.
 */
class Page
{
    private int $pageNumber;
    private string $markdown;
    private float $width;
    private float $height;
    private array $elements;

    public function __construct(
        int $pageNumber,
        string $markdown = '',
        float $width = 0.0,
        float $height = 0.0,
        array $elements = []
    ) {
        $this->pageNumber = $pageNumber;
        $this->markdown = $markdown;
        $this->width = $width;
        $this->height = $height;
        $this->elements = $elements;
    }

    public function getPageNumber(): int
    {
        return $this->pageNumber;
    }

    public function getMarkdown(): string
    {
        return $this->markdown;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function getElements(): array
    {
        return $this->elements;
    }
}
