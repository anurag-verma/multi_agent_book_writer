<?php

namespace App\AI\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Aggregate fact-check report for a chapter.
 *
 * Policy is deliberately strict: a single FAIL blocks the chapter. UNCERTAIN
 * is surfaced but does not block, because a source we could not fetch is not
 * the same as a source that contradicts the claim.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class FactCheckResult implements Arrayable
{
    /** @param array<int, FactCheckItem> $items */
    public function __construct(
        public int $chapterNumber,
        public array $items,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ((array) ($data['citations'] ?? $data['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = FactCheckItem::fromArray($item);
            }
        }

        return new self(
            chapterNumber: (int) ($data['chapter'] ?? $data['chapter_number'] ?? 0),
            items: $items,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'chapter' => $this->chapterNumber,
            'total' => $this->total(),
            'passed' => $this->passedCount(),
            'failed' => $this->failedCount(),
            'uncertain' => $this->uncertainCount(),
            'has_failures' => $this->hasFailures(),
            'citations' => array_map(
                static fn (FactCheckItem $i): array => $i->toArray(),
                $this->items
            ),
        ];
    }

    public function total(): int
    {
        return count($this->items);
    }

    public function passedCount(): int
    {
        return count($this->itemsOfStatus(FactCheckItem::PASS));
    }

    public function failedCount(): int
    {
        return count($this->itemsOfStatus(FactCheckItem::FAIL));
    }

    public function uncertainCount(): int
    {
        return count($this->itemsOfStatus(FactCheckItem::UNCERTAIN));
    }

    public function hasFailures(): bool
    {
        return $this->failedCount() > 0;
    }

    /** @return array<int, FactCheckItem> */
    public function itemsOfStatus(string $status): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (FactCheckItem $i): bool => $i->status === $status
        ));
    }

    /** @return array<int, FactCheckItem> */
    public function failures(): array
    {
        return $this->itemsOfStatus(FactCheckItem::FAIL);
    }

    /**
     * Text block the Writer receives when revising.
     */
    public function toRevisionFeedback(): string
    {
        $lines = [];

        foreach ($this->failures() as $failure) {
            $lines[] = $failure->toRevisionInstruction();
        }

        foreach ($this->itemsOfStatus(FactCheckItem::UNCERTAIN) as $uncertain) {
            $lines[] = $uncertain->toRevisionInstruction();
        }

        return implode("\n", $lines);
    }
}