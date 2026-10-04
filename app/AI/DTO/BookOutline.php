<?php

namespace App\AI\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Structured 3-chapter outline produced by the Planner.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class BookOutline implements Arrayable
{
    /** @param array<int, ChapterOutline> $chapters */
    public function __construct(
        public string $title,
        public string $audience,
        public array $chapters,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $chapters = [];

        foreach ((array) ($data['chapters'] ?? []) as $index => $chapter) {
            if (! is_array($chapter)) {
                continue;
            }

            $outline = ChapterOutline::fromArray($chapter, $index + 1);
            if ($outline->isValid()) {
                $chapters[] = $outline;
            }
        }

        // Guarantee 1..N numbering even if the model returned gaps.
        $chapters = array_map(
            static fn (ChapterOutline $c, int $i): ChapterOutline => new ChapterOutline(
                $i + 1,
                $c->title,
                $c->purpose,
                $c->keyTopics,
                $c->importantQuestions,
                $c->suggestedResearchAreas,
            ),
            $chapters,
            array_keys($chapters)
        );

        return new self(
            title: (string) ($data['title'] ?? ''),
            audience: (string) ($data['audience'] ?? ''),
            chapters: $chapters,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'audience' => $this->audience,
            'chapters' => array_map(
                static fn (ChapterOutline $c): array => $c->toArray(),
                $this->chapters
            ),
        ];
    }

    public function chapter(int $number): ?ChapterOutline
    {
        foreach ($this->chapters as $chapter) {
            if ($chapter->chapterNumber === $number) {
                return $chapter;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->chapters);
    }
}