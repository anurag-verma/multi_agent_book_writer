<?php

namespace Tests\Feature;

use App\AI\DTO\BookBrief;
use App\AI\Services\BookGenerator;
use App\AI\Services\RunStore;
use Illuminate\Support\Facades\File;
use Tests\Fakes\FakeLLM;
use Tests\Fakes\FakeSearch;
use Tests\TestCase;

/**
 * Covers BookGenerator's own responsibilities, which the pipeline tests bypass:
 * it owns run identity, the pending record and the final persisted document.
 */
class BookGeneratorTest extends TestCase
{
    private string $runDir;

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Both the run documents and the rendered artifacts are real files, so
        // both are redirected. A test run must never touch storage/app/runs or
        // the deliverables in output/.
        $this->runDir = storage_path('framework/testing/runs-'.uniqid());
        $this->outputDir = storage_path('framework/testing/output-'.uniqid());

        File::ensureDirectoryExists($this->runDir);
        File::ensureDirectoryExists($this->outputDir);

        config([
            'ai.storage.runs_path' => $this->runDir,
            'ai.workflow.output_dir' => $this->outputDir,
            'ai.workflow.chapter_count' => 1,
            'ai.workflow.max_revisions' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runDir);
        File::deleteDirectory($this->outputDir);

        parent::tearDown();
    }

    private function generator(): BookGenerator
    {
        $llm = new FakeLLM($this->llmResponses());
        $this->app->instance(\App\AI\Interfaces\LLMInterface::class, $llm);

        $search = new FakeSearch();
        $search->alwaysReturns(FakeSearch::npciStatistics());
        $this->app->instance(\App\AI\Interfaces\SearchProviderInterface::class, $search);

        return $this->app->make(BookGenerator::class);
    }

    /** @return list<string> */
    private function llmResponses(): array
    {
        return [
            // Planner: a chapter outline with no usable chapters aborts the run.
            json_encode(['title' => 'T', 'chapters' => []]),
        ];
    }

    private function brief(): array
    {
        return [
            'title' => 'UPI for Small Business',
            'audience' => 'Shop owners',
            'tone' => 'Plain and practical',
        ];
    }

    /**
     * The bug this guards: the final write replaces the pending record instead
     * of merging into it, and the orchestrator aborts on a planner failure with
     * a result carrying no brief. The title was therefore lost and the runs
     * table fell back to "Untitled".
     */
    public function test_an_aborted_run_still_persists_the_title(): void
    {
        $result = $this->generator()->generate($this->brief());

        $this->assertFalse($result['success'], 'the run should have aborted');

        $stored = $this->app->make(RunStore::class)->get($result['id']);

        $this->assertIsArray($stored, 'the run document should exist');
        $this->assertSame(
            'UPI for Small Business',
            $stored['brief']['title'] ?? null,
            'an aborted run must keep its title'
        );

        $this->assertSame(
            'UPI for Small Business',
            $this->app->make(RunStore::class)->all()[0]['title'] ?? null,
            'the runs table should show the real title, not "Untitled"'
        );
    }

    public function test_a_finished_run_records_when_it_finished(): void
    {
        $result = $this->generator()->generate($this->brief());

        $this->assertArrayHasKey('finished_at', $result);
        $this->assertNotEmpty($result['finished_at']);

        $summary = $this->app->make(RunStore::class)->all()[0];

        // dd/mm/yy hh:mm:ss, India Standard Time.
        $this->assertMatchesRegularExpression(
            '/^\d{2}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $summary['finished_at']
        );
    }

    public function test_timestamps_are_rendered_in_india_standard_time(): void
    {
        // 2026-10-04 06:27:53 UTC is 11:57:53 the same day in Asia/Kolkata
        // (UTC+5:30). The stored value stays in UTC; only the display shifts.
        $stamp = '2026-10-04T06:27:53+00:00';

        $this->app->make(RunStore::class)->put('tz-check', [
            'id' => 'tz-check',
            'status' => 'completed',
            'brief' => ['title' => 'Timezone check'],
            'chapters' => [],
            'validation' => ['passed' => false],
            'created_at' => $stamp,
            'finished_at' => $stamp,
        ]);

        $summary = collect($this->app->make(RunStore::class)->all())
            ->firstWhere('id', 'tz-check');

        $this->assertSame('04/10/26 11:57:53', $summary['finished_at']);
    }

    public function test_a_run_in_flight_has_no_finished_time(): void
    {
        $this->app->make(RunStore::class)->put('running-check', [
            'id' => 'running-check',
            'status' => 'running',
            'brief' => ['title' => 'Still going'],
            'chapters' => [],
            'validation' => [],
            'created_at' => now()->toIso8601String(),
        ]);

        $summary = collect($this->app->make(RunStore::class)->all())
            ->firstWhere('id', 'running-check');

        // created_at is the start time mid-run, so it must not masquerade as a
        // completion time.
        $this->assertNull($summary['finished_at']);
    }

    public function test_a_record_with_no_timestamp_does_not_break_the_table(): void
    {
        $this->app->make(RunStore::class)->put('no-stamp', [
            'id' => 'no-stamp',
            'status' => 'completed',
            'brief' => ['title' => 'Unstamped'],
            'chapters' => [],
            'validation' => [],
        ]);

        $summary = collect($this->app->make(RunStore::class)->all())
            ->firstWhere('id', 'no-stamp');

        $this->assertSame('—', $summary['finished_at']);
    }

    public function test_the_brief_round_trips_its_overrides(): void
    {
        $brief = BookBrief::make($this->brief());

        $this->assertSame('UPI for Small Business', $brief->title);
        $this->assertSame('Shop owners', $brief->audience);
        $this->assertSame('Plain and practical', $brief->tone);
    }
}