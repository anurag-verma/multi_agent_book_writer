<?php

namespace App\AI\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Everything the Researcher could verify for one chapter.
 *
 * This object is the Writer's entire universe of citable facts. If a URL is not
 * in here, it cannot be cited, because the citation validator strips any
 * marker that does not resolve to a SourceItem in this package.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ResearchPackage implements Arrayable
{
    /** @param array<int, SourceItem> $sources */
    public function __construct(
        public int $chapterNumber,
        public string $chapterTitle,
        public array $sources,
        public array $notes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $sources = [];

        foreach ((array) ($data['sources'] ?? []) as $index => $source) {
            if (! is_array($source)) {
                continue;
            }

            $item = SourceItem::fromArray($source, $index + 1);
            if ($item->url === '' || $item->title === '') {
                continue;
            }

            $sources[] = $item;
        }

        // Citation numbers are assigned in order, ignoring whatever the model
        // proposed, so [1] is always the first source and numbering is dense.
        $sources = array_map(
            static fn (SourceItem $s, int $i): SourceItem => new SourceItem(
                $i + 1,
                $s->organization,
                $s->title,
                $s->url,
                $s->claimsSupported,
                $s->retrievedContent,
                $s->verified,
                $s->verificationNote,
                $s->httpStatus,
            ),
            $sources,
            array_keys($sources)
        );

        return new self(
            chapterNumber: (int) ($data['chapter'] ?? $data['chapter_number'] ?? 0),
            chapterTitle: (string) ($data['chapter_title'] ?? ''),
            sources: $sources,
            notes: array_values(array_filter((array) ($data['notes'] ?? []))),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'chapter' => $this->chapterNumber,
            'chapter_title' => $this->chapterTitle,
            'source_count' => count($this->sources),
            'sources' => array_map(
                static fn (SourceItem $s): array => $s->toArray(),
                $this->sources
            ),
            'notes' => $this->notes,
        ];
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

    /** @return array<int, int> */
    public function validCitationIds(): array
    {
        return array_map(
            static fn (SourceItem $s): int => $s->citationId,
            $this->sources
        );
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }

    /**
     * Compact rendering handed to the Writer. Deliberately excludes page text so
     * the Writer is not tempted to quote beyond what the claims describe.
     */
    public function toPrompt(int $maxCharsPerSource = 1200): string
    {
        if ($this->sources === []) {
            return 'NO VERIFIED SOURCES AVAILABLE.';
        }

        $blocks = [];
        foreach ($this->sources as $source) {
            $claims = $source->claimsSupported === []
                ? '  (no specific claims recorded)'
                : implode("\n", array_map(static fn (string $c): string => '  - '.$c, $source->claimsSupported));

            $evidence = $source->hasEvidence()
                ? mb_substr($this->condense($source->retrievedContent), 0, $maxCharsPerSource)
                : '(page text unavailable)';

            $blocks[] = <<<TXT
            [{$source->citationId}] {$source->organization}
              Title: {$source->title}
              URL: {$source->url}
              Claims this source supports:
            {$claims}
              Retrieved page text:
            {$evidence}
            TXT;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Trim a page down to the sentences most likely to carry figures, so the
     * Writer sees evidence rather than navigation furniture.
     */
    private function condense(string $content): string
    {
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;

        if (mb_strlen($content) <= 1200) {
            return trim($content);
        }

        // Prefer regions containing digits or currency words.
        preg_match_all('/[^.!?]*[0-9][^.!?]*[.!?]/u', $content, $numeric);

        if (isset($numeric[0]) && count($numeric[0]) > 3) {
            $picked = array_slice(array_map('trim', $numeric[0]), 0, 8);

            return implode(' ', $picked);
        }

        return trim(mb_substr($content, 0, 1200));
    }
}