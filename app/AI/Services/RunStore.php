<?php

namespace App\AI\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * File-backed run store.
 *
 * The assignment does not need a database, and adding one would mean migrations,
 * a schema and a queue driver the reviewer has to set up. Runs are large JSON
 * documents that are written once and read once, so a file per run is both
 * simpler and sufficient. Swapping this class for an Eloquent model is the only
 * change needed if persistence requirements grow.
 *
 * The one exception is the queue: QUEUE_CONNECTION=database needs the jobs
 * tables, but that is Laravel's own migration, not this class.
 */
class RunStore
{
    /**
     * Zone used when rendering run timestamps. Presentation only.
     */
    private const DISPLAY_TIMEZONE = 'Asia/Kolkata';
    /**
     * Where run documents live. Overridable so tests can be pointed at a
     * temporary directory instead of deleting real runs.
     */
    public static function directory(): string
    {
        $configured = config('ai.storage.runs_path');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/\\');
        }

        return storage_path('app'.DIRECTORY_SEPARATOR.'runs');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $id, array $data): void
    {
        File::ensureDirectoryExists($this->path());

        File::put($this->file($id), json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        if (! File::exists($this->file($id))) {
            return null;
        }

        $decoded = json_decode((string) File::get($this->file($id)), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * List runs newest first, without loading chapter bodies.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(int $limit = 25): array
    {
        if (! File::isDirectory($this->path())) {
            return [];
        }

        $files = File::files($this->path());
        usort($files, static fn ($a, $b) => $b->getMTime() <=> $a->getMTime());

        $runs = [];

        foreach (array_slice($files, 0, $limit) as $file) {
            $run = $this->get($file->getFilenameWithoutExtension());

            if ($run === null) {
                continue;
            }

            $validation = $run['validation'] ?? [];

            $runs[] = [
                'id' => $run['id'] ?? $file->getFilenameWithoutExtension(),
                'title' => $run['brief']['title'] ?? 'Untitled',
                'status' => $run['status'] ?? 'unknown',
                'passed' => (bool) ($validation['passed'] ?? false),
                'chapters' => count($run['chapters'] ?? []),
                'words' => array_sum(array_map(
                    static fn (array $c): int => (int) ($c['word_count'] ?? 0),
                    $run['chapters'] ?? []
                )),
                'seconds' => $run['elapsed_seconds'] ?? null,
                'created_at' => $run['created_at'] ?? $file->getMTime(),

                // created_at is the moment the run was *started* while it is in
                // flight and overwritten with the finish time when it completes,
                // so it cannot serve both. finished_at is only written on the
                // final write; older records fall back to created_at, which for a
                // finished run already held the completion time.
                'finished_at' => ($run['status'] ?? '') === 'running'
                    ? null
                    : $this->formatTimestamp($run['finished_at'] ?? $run['created_at'] ?? null),
                'errors' => $validation['errors'] ?? [],
            ];
        }

        return $runs;
    }

    /**
     * Format a stored timestamp for display.
     *
     * Timestamps are persisted as absolute ISO-8601 in UTC, so the zone is a
     * presentation choice and never affects what was written. India Standard
     * Time is pinned here because the readers this book targets are in India,
     * and it should not shift just because APP_TIMEZONE or the server moves.
     *
     * @return string dd/mm/yy hh:mm:ss, or an em dash when unknown
     */
    private function formatTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            return Carbon::parse($value)->timezone(self::DISPLAY_TIMEZONE)->format('d/m/y H:i:s');
        } catch (\Throwable) {
            return '—';
        }
    }

    public function delete(string $id): bool
    {
        $path = $this->file($id);

        return File::exists($path) && File::delete($path);
    }

    private function path(): string
    {
        return self::directory();
    }

    private function file(string $id): string
    {
        // Only allow ids we generated, so this can never escape the directory.
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';

        return $this->path().DIRECTORY_SEPARATOR.$safe.'.json';
    }
}