<?php

namespace App\AI\Exceptions;

use RuntimeException;

/**
 * Every failure that can happen inside the AI pipeline is surfaced as this
 * exception so the orchestrator can log it, show it in the UI, and decide
 * whether to retry instead of crashing with a stack trace.
 */
class AgentException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, private array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /** @param array<string, mixed> $context */
    public static function llm(string $message, array $context = [], ?\Throwable $previous = null): self
    {
        return new self('LLM error: '.$message, $context, 0, $previous);
    }

    /** @param array<string, mixed> $context */
    public static function search(string $message, array $context = [], ?\Throwable $previous = null): self
    {
        return new self('Search error: '.$message, $context, 0, $previous);
    }

    /** @param array<string, mixed> $context */
    public static function invalidJson(string $message, array $context = []): self
    {
        return new self('Invalid JSON from LLM: '.$message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function emptyResponse(string $message, array $context = []): self
    {
        return new self('Empty LLM response: '.$message, $context);
    }
}