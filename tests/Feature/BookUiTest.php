<?php

namespace Tests\Feature;

use App\AI\Services\RunStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * HTTP-level checks. The pipeline itself is covered by RevisionLoopTest, so
 * these focus on wiring, validation and rendering.
 */
class BookUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Point run storage at a temp directory. Cleaning the real one would
        // delete actual generation results.
        $this->runDir = storage_path('framework/testing/runs-'.uniqid());
        File::ensureDirectoryExists($this->runDir);
        config(['ai.storage.runs_path' => $this->runDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runDir);

        parent::tearDown();
    }

    private string $runDir;

    public function test_the_form_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Generate a book')
            ->assertSee('Book title');
    }

    public function test_generation_can_be_started_with_a_valid_brief(): void
    {
        Queue::fake();

        $response = $this->post('/', [
            'title' => 'UPI for Small Business',
            'audience' => 'Shop owners',
            'tone' => 'Warm',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('status');

        Queue::assertPushed(\App\Jobs\GenerateBookJob::class);
    }

    public function test_a_brief_without_a_title_is_rejected(): void
    {
        $this->post('/', ['audience' => 'Shop owners'])
            ->assertSessionHasErrors('title');
    }

    public function test_a_missing_run_returns_a_friendly_redirect(): void
    {
        $this->get('/runs/does-not-exist')
            ->assertRedirect('/')
            ->assertSessionHas('error');
    }

    public function test_a_stored_run_is_rendered_with_its_chapters(): void
    {
        $app = $this->app->make(RunStore::class);

        $app->put('testrun', [
            'id' => 'testrun',
            'status' => 'completed',
            'success' => true,
            'brief' => ['title' => 'UPI for Small Business', 'audience' => 'Shop owners'],
            'chapters' => [[
                'chapter_number' => 1,
                'title' => 'Getting started',
                'body' => "UPI is a payment system [1].\n\nTakeaway: Try it.",
                'word_count' => 640,
                'citations_used' => [1],
                'sources' => [[
                    'organization' => 'NPCI',
                    'title' => 'UPI Statistics',
                    'url' => 'https://npci.org.in/x',
                ]],
                'warnings' => [],
            ]],
            'fact_checks' => [[
                'chapter' => 1,
                'total' => 1, 'passed' => 1, 'failed' => 0, 'uncertain' => 0,
                'citations' => [[
                    'citation' => 1, 'claim' => 'UPI is a payment system',
                    'status' => 'PASS', 'reason' => 'Supported by the source.',
                ]],
            ]],
            'validation' => [
                'passed' => true,
                'errors' => [],
                'warnings' => [],
                'chapters' => [[
                    'chapter' => 1, 'title' => 'Getting started', 'word_count' => 640,
                    'citations' => 1, 'references' => 1,
                    'takeaway' => 'PASS', 'validation' => 'PASS',
                ]],
            ],
            'events' => [[
                'type' => 'planner', 'message' => '[Planner] Completed',
                'context' => [], 'at' => '2026-01-01 10:00:00',
            ]],
            'elapsed_seconds' => 12.5,
        ]);

        $this->get('/runs/testrun')
            ->assertOk()
            ->assertSee('UPI for Small Business')
            ->assertSee('Getting started')
            ->assertSee('VALIDATION PASSED')
            ->assertSee('Takeaway:')
            ->assertSee('https://npci.org.in/x')
            ->assertSee('[Planner] Completed');
    }

    public function test_the_runs_table_shows_a_real_title_and_a_finished_timestamp(): void
    {
        $store = $this->app->make(\App\AI\Services\RunStore::class);

        $store->put('finishedrun', [
            'id' => 'finishedrun',
            'status' => 'completed',
            'brief' => ['title' => 'UPI for Street Vendors'],
            'chapters' => [],
            'validation' => ['passed' => true, 'errors' => [], 'warnings' => []],
            'created_at' => '2026-10-04T06:27:53+00:00',
            'finished_at' => '2026-10-04T06:31:12+00:00',
        ]);

        $response = $this->get('/');

        $response->assertOk()
            // The column exists and is labelled.
            ->assertSee('Finished')
            // The title survives, rather than falling back to "Untitled".
            ->assertSee('UPI for Street Vendors')
            ->assertDontSee('Untitled')
            // 06:31:12 UTC is 12:01:12 in Asia/Kolkata (UTC+5:30).
            ->assertSee('04/10/26 12:01:12');
    }

    public function test_the_status_endpoint_returns_json_progress(): void
    {
        $this->app->make(RunStore::class)->put('run2', [
            'id' => 'run2',
            'status' => 'running',
            'events' => [['type' => 'writer', 'message' => '[Writer] Chapter 1 started', 'context' => []]],
        ]);

        $this->getJson('/runs/run2/status')
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonCount(1, 'events');
    }

    public function test_the_status_endpoint_404s_for_an_unknown_run(): void
    {
        $this->getJson('/runs/nope/status')->assertNotFound();
    }

    public function test_a_run_can_be_deleted(): void
    {
        $store = $this->app->make(RunStore::class);
        $store->put('run3', ['id' => 'run3', 'status' => 'completed']);

        $this->delete('/runs/run3')->assertRedirect('/');

        $this->assertNull($store->get('run3'));
    }

    public function test_run_ids_cannot_escape_the_storage_directory(): void
    {
        $store = $this->app->make(RunStore::class);

        // A traversal attempt must be neutralised, not written outside the dir.
        $store->put('../../escape', ['id' => 'escape']);

        $this->assertFileDoesNotExist(base_path('escape.json'));
        $this->assertNotEmpty($store->get('../../escape'));
    }

    public function test_the_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}