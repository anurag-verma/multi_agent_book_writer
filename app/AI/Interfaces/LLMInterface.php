<?php

namespace App\AI\Interfaces;

interface LLMInterface
{
    /**
     * Send a prompt to the LLM and get raw text back.
     *
     * @param array<string, mixed> $options Provider overrides (model, temperature, max_tokens)
     */
    public function sendPrompt(string $systemPrompt, string $userPrompt, array $options = []): string;

    /**
     * Send a prompt and require a JSON object back.
     *
     * @param array<string, mixed> $schema  JSON Schema used for structured output when the provider supports it
     * @param array<string, mixed> $options Provider overrides
     * @return array<string, mixed>
     */
    public function sendJson(string $systemPrompt, string $userPrompt, array $schema = [], array $options = []): array;

    /**
     * Name of the configured provider, e.g. "openai".
     */
    public function provider(): string;
}