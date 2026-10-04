<?php

namespace Tests\Unit;

use App\AI\Exceptions\AgentException;
use App\AI\Services\LLMService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LLMServiceTest extends TestCase
{
    private array $schema = [
        'type' => 'object',
        'properties' => ['title' => ['type' => 'string']],
        'required' => ['title'],
        'additionalProperties' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.llm.provider' => 'openai',
            'ai.llm.model' => 'test-model',
            'ai.llm.api_key' => 'test-key',
            'ai.llm.base_url' => 'https://llm.test/v1',
            'ai.llm.retries' => 0,
        ]);
    }

    private function completion(string $content): array
    {
        return [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $content],
            ]],
        ];
    }

    private function service(): LLMService
    {
        return $this->app->make(LLMService::class);
    }

    public function test_a_schema_is_enforced_on_the_first_attempt(): void
    {
        Http::fake([
            '*' => Http::response($this->completion('{"title":"Accepting UPI"}'), 200),
        ]);

        $result = $this->service()->sendJson('system', 'user', $this->schema);

        $this->assertSame(['title' => 'Accepting UPI'], $result);

        Http::assertSentCount(1);

        Http::assertSent(function ($request): bool {
            return ($request['response_format']['type'] ?? null) === 'json_schema';
        });
    }

    public function test_a_server_that_rejects_json_schema_degrades_to_json_object(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => "Unsupported response_format type 'json_schema'"]], 400)
                ->push($this->completion('{"title":"Accepting UPI"}'), 200),
        ]);

        $result = $this->service()->sendJson('system', 'user', $this->schema);

        $this->assertSame(['title' => 'Accepting UPI'], $result);
        Http::assertSentCount(2);

        $types = [];

        Http::assertSent(function ($request) use (&$types): bool {
            $types[] = $request['response_format']['type'] ?? 'prose';

            return true;
        });

        $this->assertSame(['json_schema', 'json_object'], $types);
    }

    public function test_the_ladder_falls_all_the_way_through_to_plain_prose(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'json_schema is not supported']], 400)
                ->push(['error' => ['message' => 'response_format json_object is not supported']], 400)
                ->push($this->completion('Here is the result: {"title":"Accepting UPI"}'), 200),
        ]);

        $result = $this->service()->sendJson('system', 'user', $this->schema);

        // decodeJson() still recovers the payload when the model wraps it.
        $this->assertSame(['title' => 'Accepting UPI'], $result);
        Http::assertSentCount(3);

        $types = [];

        Http::assertSent(function ($request) use (&$types): bool {
            $types[] = $request['response_format']['type'] ?? 'prose';

            return true;
        });

        $this->assertSame(['json_schema', 'json_object', 'prose'], $types);
    }

    public function test_a_non_schema_failure_is_not_retried_in_weaker_shapes(): void
    {
        // Out of credit. Retrying this three times spends the same money to
        // reach the same wall, so exactly one request should be made.
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'This request requires more credits']], 402),
        ]);

        try {
            $this->service()->sendJson('system', 'user', $this->schema);
            $this->fail('Expected an AgentException.');
        } catch (AgentException $e) {
            $this->assertStringContainsString('402', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_a_bad_request_unrelated_to_output_shape_is_not_retried(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => "Unknown model 'test-model'"]], 400),
        ]);

        try {
            $this->service()->sendJson('system', 'user', $this->schema);
            $this->fail('Expected an AgentException.');
        } catch (AgentException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
        }

        Http::assertSentCount(1);
    }
}