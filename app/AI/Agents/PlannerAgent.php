<?php

namespace App\AI\Agents;

use App\AI\DTO\BookBrief;
use App\AI\DTO\BookOutline;
use App\AI\Exceptions\AgentException;
use App\AI\Interfaces\LLMInterface;
use Illuminate\Support\Facades\Log;

/**
 * Agent 1 of 5.
 *
 * Turns the brief into a chapter-by-chapter plan. It deliberately produces no
 * prose: separating "what the chapter must achieve" from "what the chapter says"
 * is what lets the Researcher aim its queries and the Writer be held to a brief.
 */
class PlannerAgent
{
    public function __construct(private readonly LLMInterface $llm) {}

    /**
     * JSON Schema for the outline. Structured output is requested rather than
     * parsed loosely, because every downstream agent reads this object.
     *
     * @return array<string, mixed>
     */
    private function schema(int $chapterCount): array
    {
        $chapter = [
            'type' => 'object',
            'properties' => [
                'chapter_number' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'purpose' => ['type' => 'string'],
                'key_topics' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 3],
                'important_questions' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2],
                'suggested_research_areas' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2],
            ],
            'required' => ['chapter_number', 'title', 'purpose', 'key_topics', 'important_questions', 'suggested_research_areas'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'audience' => ['type' => 'string'],
                'chapters' => [
                    'type' => 'array',
                    'minItems' => $chapterCount,
                    'maxItems' => $chapterCount,
                    'items' => $chapter,
                ],
            ],
            'required' => ['title', 'audience', 'chapters'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @throws AgentException when the model returns nothing usable
     */
    public function plan(BookBrief $brief): BookOutline
    {
        Log::info('[Planner] Started', ['title' => $brief->title]);

        $system = <<<'TXT'
        You are the Planner in a multi-agent book production pipeline.

        You do NOT write chapters. You only decide the structure of the book.

        Rules:
        - Produce exactly the requested number of chapters, numbered 1..N in order.
        - Each chapter must have a distinct purpose. Later chapters must build on
          earlier ones rather than repeat them.
        - Chapter titles must be plain and specific. No clever wordplay.
        - "important_questions" are the questions the chapter must answer for the reader.
        - "suggested_research_areas" are research topics, phrased as things to look up.
          Prefer official Indian sources: NPCI, RBI, Press Information Bureau (PIB),
          Ministry of Finance, Department of Financial Services.
        - Plan for a first-time reader. Do not assume any prior knowledge.
        TXT;

        $user = $brief->toPrompt()."\n\n"
            ."Create a chapter outline for exactly {$brief->chapterCount} chapters.\n"
            .'Do not write any chapter content. Plan only.';

        $data = $this->llm->sendJson($system, $user, $this->schema($brief->chapterCount), [
            'temperature' => 0.4,
        ]);

        $outline = BookOutline::fromArray($data);

        if ($outline->count() === 0) {
            throw new AgentException('Planner returned no usable chapters.', ['raw' => $data]);
        }

        if ($outline->count() !== $brief->chapterCount) {
            Log::warning('[Planner] chapter count mismatch', [
                'expected' => $brief->chapterCount,
                'received' => $outline->count(),
            ]);
        }

        Log::info('[Planner] Completed', [
            'chapters' => $outline->count(),
            'titles' => array_map(static fn ($c): string => $c->title, $outline->chapters),
        ]);

        return $outline;
    }
}