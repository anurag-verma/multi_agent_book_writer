<?php

namespace App\AI\Services;

use App\AI\DTO\BookBrief;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Convenience facade over the orchestrator that adds run bookkeeping: a run id,
 * timestamps, status transitions and persistence to the RunStore.
 *
 * Controllers talk to this class, never to WorkflowOrchestrator directly, so
 * storage concerns do not leak into the pipeline.
 */
class BookGenerator
{
    public function __construct(
        private readonly WorkflowOrchestrator $orchestrator,
        private readonly RunStore $runs,
    ) {}

    /**
     * Run the full pipeline synchronously and persist the outcome.
     *
     * @param  array<string, mixed>  $overrides
     * @param  callable|null  $onEvent  Receives (string $type, string $message, array $context)
     * @return array<string, mixed>
     */
    public function generate(array $overrides = [], ?callable $onEvent = null): array
    {
        $id = date('Ymd-His').'-'.Str::lower(Str::random(6));

        $brief = BookBrief::make($overrides);

        Log::info('[Generator] run started', ['run' => $id, 'title' => $brief->title]);

        // Progress is written on every event so a polling UI can show the
        // pipeline advancing instead of a spinner for three minutes. The
        // pending record exists before any LLM call, so the UI has something to
        // show even if the very first agent throws.
        $record = [
            'id' => $id,
            'status' => 'running',
            'created_at' => now()->toIso8601String(),
            'brief' => $brief->toArray(),
            'chapters' => [],
            'events' => [],
        ];

        $this->runs->put($id, $record);

        $progress = function (string $type, string $message, array $context = []) use ($id, &$record): void {
            $record['events'][] = [
                'type' => $type,
                'message' => $message,
                'context' => $context,
                'at' => now()->toDateTimeString(),
            ];

            $this->runs->put($id, $record);
        };

        // Chain any caller-supplied listener to the persistence listener.
        $listener = $progress;

        if ($onEvent !== null) {
            $listener = function (string $type, string $message, array $context = []) use ($onEvent, $progress): void {
                $onEvent($type, $message, $context);
                $progress($type, $message, $context);
            };
        }

        try {
            $result = $this->orchestrator->run($brief, $listener);
        } catch (\Throwable $e) {
            Log::error('[Generator] run crashed', ['run' => $id, 'error' => $e->getMessage()]);

            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'failed_agent' => 'Pipeline',
                'chapters' => [],
                'events' => [],
            ];
        }

        $result['id'] = $id;
        $result['status'] = ($result['success'] ?? false) ? 'completed' : 'failed';
        $result['created_at'] = now()->toIso8601String();
        $result['finished_at'] = now()->toIso8601String();

        // This write replaces the pending record rather than merging into it, and
        // the orchestrator aborts early on a planner or researcher failure with a
        // result that carries no brief. Without re-attaching it here the title
        // was lost and every such run listed as "Untitled".
        $result['brief'] = $brief->toArray();

        $this->runs->put($id, $result);

        Log::info('[Generator] run finished', [
            'run' => $id,
            'status' => $result['status'],
            'seconds' => $result['elapsed_seconds'] ?? null,
        ]);

        return $result;
    }

    public function find(string $id): ?array
    {
        return $this->runs->get($id);
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 25): array
    {
        return $this->runs->all($limit);
    }

    public function forget(string $id): bool
    {
        return $this->runs->delete($id);
    }
}