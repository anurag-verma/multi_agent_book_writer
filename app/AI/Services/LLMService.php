<?php

namespace App\AI\Services;

use App\AI\Exceptions\AgentException;
use App\AI\Interfaces\LLMInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single entry point every agent uses to talk to a language model.
 *
 * Agents depend on LLMInterface, never on a vendor SDK, so swapping OpenAI for
 * Anthropic (or a local model) is a config change rather than a rewrite.
 */
class LLMService implements LLMInterface
{
    private string $provider;

    private string $model;

    private string $apiKey;

    private string $baseUrl;

    private float $temperature;

    private int $maxTokens;

    private int $timeout;

    private int $retries;

    public function __construct()
    {
        $this->provider = (string) config('ai.llm.provider', 'openai');
        $this->model = (string) config('ai.llm.model', 'gpt-4o-mini');
        $this->apiKey = (string) config('ai.llm.api_key', '');
        $this->baseUrl = rtrim((string) config('ai.llm.base_url', 'https://api.openai.com/v1'), '/');
        $this->temperature = (float) config('ai.llm.temperature', 0.3);
        $this->maxTokens = (int) config('ai.llm.max_tokens', 4000);
        $this->timeout = (int) config('ai.llm.timeout', 120);
        $this->retries = (int) config('ai.llm.retries', 2);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function sendPrompt(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        if ($this->provider === 'null') {
            Log::info('[LLM] null provider, returning empty response');

            return '';
        }

        if ($this->provider !== 'anthropic' && $this->apiKey === '') {
            throw AgentException::llm('AI_LLM_API_KEY is not set. Add it to your .env file.');
        }

        $tiers = $this->outputTiers($options);

        $response = null;

        foreach ($tiers as $position => $tier) {
            $response = $this->client($options)->post(
                $this->endpoint(),
                $this->buildPayload($systemPrompt, $userPrompt, array_merge($options, $tier))
            );

            if (! $response->failed()) {
                break;
            }

            if ($position === array_key_last($tiers)) {
                break;
            }

            // Only a complaint about the response format itself is worth
            // retrying in a weaker shape.
            $body = strtolower($response->body());

            $schemaRejected = $response->status() === 400
                && ! empty($tier['json_mode'])
                && (str_contains($body, 'response_format')
                    || str_contains($body, 'json_schema')
                    || str_contains($body, 'structured output'));

            if (! $schemaRejected) {
                break;
            }

            Log::warning('[LLM] structured output rejected, degrading', [
                'model' => $this->model,
                'attempted' => ! empty($tier['schema']) ? 'json_schema' : 'json_object',
                'body' => Str::limit($response->body(), 300),
            ]);
        }

        if ($response->failed()) {
            throw AgentException::llm(
                sprintf('%s returned HTTP %d: %s', $this->provider, $response->status(), Str::limit($response->body(), 400)),
                ['provider' => $this->provider, 'model' => $this->model, 'status' => $response->status()]
            );
        }

        $text = $this->extractText($response->json());

        if (trim($text) === '') {
            throw AgentException::emptyResponse('The model returned no usable text.', [
                'provider' => $this->provider,
                'model' => $this->model,
            ]);
        }

        return $text;
    }

    public function sendJson(string $systemPrompt, string $userPrompt, array $schema = [], array $options = []): array
    {
        $system = $systemPrompt;

        if ($schema !== []) {
            $system .= "\n\nYou must reply with a single JSON object and nothing else. "
                .'No markdown fences, no commentary. It must validate against this JSON Schema: '
                .json_encode($schema, JSON_UNESCAPED_SLASHES);
        }

        $options['json_mode'] = $schema !== [];

        // buildPayload() only emits a response_format when a schema is present,
        // so the schema has to travel through options. Without this line the
        // request silently degrades to plain json_object mode.
        if ($schema !== []) {
            $options['schema'] = $schema;
        }

        $raw = $this->sendPrompt($system, $userPrompt, $options);

        return $this->decodeJson($raw);
    }

    /**
     * Models wrap JSON in prose or markdown fences often enough that we need a
     * forgiving decoder. Anything still unparsable is a hard error so the
     * orchestrator can retry instead of silently producing a broken chapter.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw): array
    {
        $candidates = [$raw];

        // Strip ```json ... ``` fences.
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $raw, $m) === 1) {
            array_unshift($candidates, trim($m[1]));
        }

        // Slice the outermost {...} or [...] block.
        if (preg_match('/(\{.*\}|\[.*\])/s', $raw, $m) === 1) {
            array_unshift($candidates, $m[1]);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode(trim($candidate), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        throw AgentException::invalidJson('Could not decode a JSON object from the model response.', [
            'preview' => Str::limit($raw, 500),
        ]);
    }

    /** @return array<string, mixed> */
    private function buildPayload(string $systemPrompt, string $userPrompt, array $options): array
    {
        $model = $options['model'] ?? $this->model;
        $temperature = $options['temperature'] ?? $this->temperature;
        $maxTokens = $options['max_tokens'] ?? $this->maxTokens;

        if ($this->provider === 'anthropic') {
            return [
                'model' => $model,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $userPrompt]],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ];
        }

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        // Structured output. Not every OpenAI-compatible server supports
        // json_schema, so we degrade to json_object and finally to prose.
        if (! empty($options['json_mode']) && ! empty($options['schema'])) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'agent_output',
                    'strict' => true,
                    'schema' => $options['schema'],
                ],
            ];
        } elseif (! empty($options['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * The ordered ladder of output modes to attempt.
     *
     * A schema is enforced first because it is the only tier that actually
     * guarantees the shape; json_object still lets a model return prose; plain
     * prose relies entirely on decodeJson() to find the JSON afterwards.
     *
     * @param  array<string, mixed>  $options
     * @return list<array{schema: array<string, mixed>, json_mode: bool}>
     */
    private function outputTiers(array $options): array
    {
        if (empty($options['json_mode'])) {
            return [['schema' => [], 'json_mode' => false]];
        }

        $schema = (array) ($options['schema'] ?? []);

        if ($schema === []) {
            return [['schema' => [], 'json_mode' => true]];
        }

        return [
            ['schema' => $schema, 'json_mode' => true],
            ['schema' => [], 'json_mode' => true],
            ['schema' => [], 'json_mode' => false],
        ];
    }

    private function endpoint(): string
    {
        return $this->provider === 'anthropic'
            ? $this->baseUrl.'/messages'
            : $this->baseUrl.'/chat/completions';
    }

    private function client(array $options): PendingRequest
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->provider === 'anthropic') {
            $headers['x-api-key'] = $this->apiKey;
            $headers['anthropic-version'] = '2023-06-01';
        } else {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        // OpenRouter uses these for attribution and rate limits.
        foreach ((array) config('ai.llm.extra_headers', []) as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }

        return Http::withHeaders($headers)
            ->timeout($options['timeout'] ?? $this->timeout)
            ->retry($this->retries, 500, throw: false);
    }

    /**
     * Pull the assistant text out of either provider's response shape.
     *
     * @param  array<string, mixed>|null  $json
     */
    private function extractText(?array $json): string
    {
        if (! is_array($json)) {
            return '';
        }

        // OpenAI compatible
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (is_string($content)) {
            return $content;
        }

        // Some servers return content parts instead of a plain string.
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }

            if ($text !== '') {
                return $text;
            }
        }

        // Anthropic
        $blocks = $json['content'] ?? null;
        if (is_array($blocks)) {
            $text = '';
            foreach ($blocks as $block) {
                if (isset($block['text']) && is_string($block['text'])) {
                    $text .= $block['text'];
                }
            }

            return $text;
        }

        return '';
    }
}