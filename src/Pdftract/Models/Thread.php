<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of an article thread
 *
 * Represents a single article thread from the PDF's /Threads array,
 * including metadata from the thread info dict (/I) and the complete
 * bead chain walked from the first bead.
 */
class Thread
{
    /**
     * Thread title from /I/Title
     *
     * Empty string if /I/Title is present but empty, null if /I is missing or /Title is absent
     */
    public ?string $title = null;

    /**
     * Thread author from /I/Author
     *
     * Empty string if /I/Author is present but empty, null if /I is missing or /Author is absent
     */
    public ?string $author = null;

    /**
     * Thread subject from /I/Subject
     *
     * Empty string if /I/Subject is present but empty, null if /I is missing or /Subject is absent
     */
    public ?string $subject = null;

    /**
     * Thread keywords from /I/Keywords
     *
     * Per PDF spec, this is a comma-separated convention (not an array).
     * Empty string if /I/Keywords is present but empty, null if /I is missing or /Keywords is absent.
     */
    public ?string $keywords = null;

    /**
     * Beads in this thread chain, in traversal order
     *
     * Each bead represents a region on a page that is part of this article.
     * The beads are ordered by following `/N` (next bead) links from the
     * first bead through the chain until termination.
     *
     * @var array<Bead>
     */
    public array $beads = [];

    /**
     * Create Thread from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $thread = new self();
        $thread->title = $data['title'] ?? null;
        $thread->author = $data['author'] ?? null;
        $thread->subject = $data['subject'] ?? null;
        $thread->keywords = $data['keywords'] ?? null;

        foreach ($data['beads'] ?? [] as $item) {
            $thread->beads[] = Bead::fromArray($item);
        }

        return $thread;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'beads' => array_map(fn($b) => $b->toArray(), $this->beads),
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

        return $data;
    }
}
