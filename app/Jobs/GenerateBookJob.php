<?php

namespace App\Jobs;

use App\AI\Services\BookGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the generation pipeline outside the HTTP request.
 *
 * The pipeline performs many live LLM and search calls and takes minutes, which
 * is far past a normal request timeout. Queuing it keeps the UI responsive and
 * means a browser refresh cannot kill a run that is halfway through.
 *
 * Set QUEUE_CONNECTION=database and run the jobs table migration if you want
 * queue-backed durability; the default 'sync' connection still works, it just
 * blocks the request that dispatched it.
 */
class GenerateBookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    /** @param array<string, mixed> $brief */
    public function __construct(public readonly array $brief) {}

    public function handle(BookGenerator $generator): void
    {
        $generator->generate($this->brief);
    }

    /**
     * Surfaced in the queue table so a failure is visible rather than silent.
     */
    public function failed(?\Throwable $exception): void
    {
        logger()->error('[GenerateBookJob] failed', [
            'exception' => $exception?->getMessage(),
        ]);
    }
}