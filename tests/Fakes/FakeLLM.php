<?php

namespace Tests\Fakes;

use App\AI\DTO\BookBrief;
use App\AI\DTO\ChapterOutline;
use App\AI\DTO\ResearchPackage;
use App\AI\DTO\SourceItem;
use App\AI\Interfaces\LLMInterface;
use Illuminate\Support\Collection;

/**
 * Scriptable LLM double.
 *
 * Agents are tested by handing them a deterministic sequence of responses, which
 * is the only way to assert on the revision loop: a revision is defined by what
 * the model says the second time it is called.
 */
class FakeLLM implements LLMInterface
{
    /** @var array<int, array{system: string, user: string, schema: array, options: array}> */
    public array $calls = [];

    /** @var Collection<int, string> */
    private Collection $queue;

    /** @param array<int, string> $responses */
    public function __construct(array $responses = [])
    {
        $this->queue = collect($responses);
    }

    /** @param array<int, string> $responses */
    public static function withResponses(array $responses = []): self
    {
        return new self($responses);
    }

    public function push(string $response): self
    {
        $this->queue->push($response);

        return $this;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function sendPrompt(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        $this->calls[] = [
            'system' => $systemPrompt,
            'user' => $userPrompt,
            'schema' => [],
            'options' => $options,
        ];

        if ($this->queue->isEmpty()) {
            return '';
        }

        return (string) $this->queue->shift();
    }

    public function sendJson(string $systemPrompt, string $userPrompt, array $schema = [], array $options = []): array
    {
        $this->calls[] = [
            'system' => $systemPrompt,
            'user' => $userPrompt,
            'schema' => $schema,
            'options' => $options,
        ];

        if ($this->queue->isEmpty()) {
            return [];
        }

        $raw = (string) $this->queue->shift();

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function lastUserPrompt(): string
    {
        $last = end($this->calls);

        return $last === false ? '' : $last['user'];
    }

    public function lastSystemPrompt(): string
    {
        $last = end($this->calls);

        return $last === false ? '' : $last['system'];
    }
}