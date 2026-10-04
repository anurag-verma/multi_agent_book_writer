<?php

namespace App\AI\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Deterministic report produced by the Final Validator. Contains no LLM output
 * and is fully reproducible from the chapter list.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ValidationReport implements Arrayable
{
    /**
     * @param  array<int, array<string, mixed>>  $chapters
     * @param  array<int, string>                $errors    Fatal problems; any error fails the report.
     * @param  array<int, string>                $warnings  Non-fatal observations.
     */
    public function __construct(
        public bool $passed,
        public array $chapters,
        public array $errors,
        public array $warnings,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'chapter_count' => count($this->chapters),
            'chapters' => $this->chapters,
        ];
    }

    public function summaryLine(): string
    {
        $total = count($this->chapters);
        $words = array_sum(array_map(
            static fn (array $c): int => (int) ($c['word_count'] ?? 0),
            $this->chapters
        ));

        return sprintf(
            '%s - %d chapter(s), %d words total, %d error(s), %d warning(s)',
            $this->passed ? 'PASSED' : 'FAILED',
            $total,
            $words,
            count($this->errors),
            count($this->warnings)
        );
    }
}