<?php

namespace App\AI\DTO;

use App\AI\Helpers\TextUtils;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A drafted or edited chapter plus the sources it actually cites.
 *
 * The body is guaranteed by CitationService to contain only citation markers
 * that resolve to a SourceItem present in $sources.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ChapterDraft implements Arrayable
{
    /**
     * @param  array<int, SourceItem>  $sources  Only the sources cited in $body.
     * @param  array<int, string>      $warnings  Non-fatal notes for the validator and UI.
     */
    public function __construct(
        public int $chapterNumber,
        public string $title,
        public string $body,
        public array $sources,
        public array $warnings = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'chapter_number' => $this->chapterNumber,
            'title' => $this->title,
            'body' => $this->body,
            'word_count' => $this->wordCount(),
            'citations_used' => $this->citationsUsed(),
            'sources' => array_map(
                static fn (SourceItem $s): array => $s->toArray(),
                $this->sources
            ),
            'warnings' => $this->warnings,
        ];
    }

    /** @return array<int, int> */
    public function citationsUsed(): array
    {
        return TextUtils::uniqueCitations($this->body);
    }

    /**
     * Word count against the shipping policy (prose only: no Takeaway line,
     * no generated references).
     *
     * This deliberately does not count the whole body. The FinalValidator
     * enforces 600-900 words using the same rule, so the Writer's revision loop
     * must measure the same thing -- otherwise a chapter can pass the loop and
     * then fail validation, which is what happened in a live run.
     */
    public function wordCount(): int
    {
        return TextUtils::countWords($this->proseOnly());
    }

    public function source(int $citationId): ?SourceItem
    {
        foreach ($this->sources as $source) {
            if ($source->citationId === $citationId) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The "Takeaway:" line, without the prefix.
     */
    public function takeaway(): ?string
    {
        if (preg_match('/^Takeaway:\s*(.+)$/mi', $this->body, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Prose with the Takeaway line and the whole reference block removed. Word
     * count and bullet checks must measure only the prose, otherwise reference
     * entries and the Takeaway inflate the count.
     */
    public function proseOnly(): string
    {
        $body = preg_replace('/^Takeaway:.*$/mi', '', $this->body) ?? $this->body;

        // Cut from a References heading to the end of the body.
        $body = preg_replace('/^\s*(?:#{1,6}\s*)?References\s*:?\s*$.*/ms', '', $body) ?? $body;

        // Some drafts emit a bare reference list with no heading; drop trailing
        // lines that look like "[n] Organisation. "Title". url".
        $lines = preg_split('/\R/u', $body) ?: [];
        while ($lines !== []) {
            $last = trim(end($lines));
            if ($last === '' || preg_match('/^\[\d+\]\s+\S+/u', $last) === 1) {
                array_pop($lines);
                continue;
            }
            break;
        }

        return trim(implode("\n", $lines));
    }

    /** @return array<int, string> */
    public function referenceLines(): array
    {
        $lines = [];
        foreach ($this->sources as $source) {
            $lines[] = $source->referenceLine();
        }

        return $lines;
    }

    /**
     * Render as Markdown: heading, prose, Takeaway line, then reference list.
     */
    public function toMarkdown(int $chapterNumber = 1): string
    {
        $parts = [];

        $parts[] = '## Chapter '.$chapterNumber.': '.$this->title;
        $parts[] = rtrim($this->body);
        $parts[] = '### References';
        foreach ($this->referenceLines() as $line) {
            $parts[] = $line;
        }

        return implode("\n\n", $parts)."\n";
    }

    public function withBody(string $body, ?array $sources = null, array $warnings = []): self
    {
        return new self(
            $this->chapterNumber,
            $this->title,
            $body,
            $sources ?? $this->sources,
            $warnings
        );
    }

    public function withTitle(string $title): self
    {
        return new self(
            $this->chapterNumber,
            $title,
            $this->body,
            $this->sources,
            $this->warnings
        );
    }
}