<?php

namespace App\AI\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The user-supplied brief. Everything downstream is derived from this, so it is
 * normalised once at the boundary and treated as immutable.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class BookBrief implements Arrayable
{
    /**
     * @param  array<int, string>  $sourcePreferences
     * @param  array<int, string>  $styleRules
     */
    public function __construct(
        public string $title,
        public string $audience,
        public string $tone,
        public int $chapterCount,
        public int $minWordsPerChapter,
        public int $maxWordsPerChapter,
        public array $sourcePreferences,
        public array $styleRules,
        public string $language = 'English',
    ) {}

    public static function make(array $overrides = []): self
    {
        $config = config('ai.workflow');

        return new self(
            title: (string) ($overrides['title'] ?? 'Untitled'),
            audience: (string) ($overrides['audience'] ?? 'General readers'),
            tone: (string) ($overrides['tone'] ?? 'Friendly and clear'),
            chapterCount: (int) ($overrides['chapter_count'] ?? $config['chapter_count']),
            minWordsPerChapter: (int) ($overrides['min_words'] ?? $config['min_chapter_words']),
            maxWordsPerChapter: (int) ($overrides['max_words'] ?? $config['max_chapter_words']),
            sourcePreferences: array_values($overrides['source_preferences'] ?? config('ai.research.preferred_domains', [])),
            styleRules: array_values($overrides['style_rules'] ?? [
                'Plain English. Avoid jargon, and explain any technical term the first time it appears.',
                'No bullet points inside chapters. Flowing prose only.',
                'Every factual claim, figure or date must carry a numbered citation such as [1].',
                'Maintain the same friendly mentor voice across all chapters.',
            ]),
            language: (string) ($overrides['language'] ?? 'English'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'audience' => $this->audience,
            'tone' => $this->tone,
            'chapter_count' => $this->chapterCount,
            'min_words' => $this->minWordsPerChapter,
            'max_words' => $this->maxWordsPerChapter,
            'source_preferences' => $this->sourcePreferences,
            'style_rules' => $this->styleRules,
            'language' => $this->language,
        ];
    }

    /**
     * Prompt-friendly rendering. Kept separate from toArray() because array
     * form is for persistence and this form is for the LLM.
     */
    public function toPrompt(): string
    {
        return <<<PROMPT
        Book title: {$this->title}
        Audience: {$this->audience}
        Tone: {$this->tone}
        Language: {$this->language}
        Length: {$this->chapterCount} chapters, roughly {$this->minWordsPerChapter}-{$this->maxWordsPerChapter} words each.
        Style rules:
        PROMPT.''.PHP_EOL.implode("\n", array_map(
            fn (string $rule, int $i): string => '  '.($i + 1).'. '.$rule,
            $this->styleRules,
            array_keys($this->styleRules)
        ));
    }
}