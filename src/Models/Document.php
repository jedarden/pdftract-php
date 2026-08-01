<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * Document model representing a PDF document.
 */
class Document
{
    private string $id;
    private int $pageCount;
    private string $title;
    private array $pages;

    public function __construct(
        string $id,
        int $pageCount,
        string $title = '',
        array $pages = []
    ) {
        $this->id = $id;
        $this->pageCount = $pageCount;
        $this->title = $title;
        $this->pages = $pages;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPageCount(): int
    {
        return $this->pageCount;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPages(): array
    {
        return $this->pages;
    }

    public function addPage(Page $page): void
    {
        $this->pages[] = $page;
    }
}
