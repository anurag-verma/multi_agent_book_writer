<?php

namespace App\AI\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One chapter's slot in the outline. Deliberately contains no prose: the Planner
 * decides what each chapter must cover, never what it says.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ChapterOutline implements Arrayable
{
    /**
     * @param  array<int, string>  $keyTopics
     * @param  array<int, string>  $importantQuestions
     * @param  array<int, string>  $suggestedResearchAreas
     */
    public function __construct(
        public int $chapterNumber,
        public string $title,
        public string $purpose,
        public array $keyTopics,
        public array $importantQuestions,
        public array $suggestedResearchAreas,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, int $fallbackNumber = 0): self
    {
        $list = static fn (mixed $value): array => array_values(array_filter(
            array_map(
                static fn (mixed $item): string => trim((string) (is_array($item) ? ($item['name'] ?? $item['text'] ?? '') : $item)),
                is_array($value) ? $value : []
            ),
            static fn (string $item): bool => $item !== ''
        ));

        return new self(
            chapterNumber: (int) ($data['chapter_number'] ?? $data['number'] ?? $fallbackNumber),
            title: trim((string) ($data['title'] ?? '')),
            purpose: trim((string) ($data['purpose'] ?? '')),
            keyTopics: $list($data['key_topics'] ?? []),
            importantQuestions: $list($data['important_questions'] ?? []),
            suggestedResearchAreas: $list($data['suggested_research_areas'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'chapter_number' => $this->chapterNumber,
            'title' => $this->title,
            'purpose' => $this->purpose,
            'key_topics' => $this->keyTopics,
            'important_questions' => $this->importantQuestions,
            'suggested_research_areas' => $this->suggestedResearchAreas,
        ];
    }

    public function isValid(): bool
    {
        return $this->chapterNumber > 0
            && $this->title !== ''
            && $this->purpose !== ''
            && $this->keyTopics !== [];
    }
}