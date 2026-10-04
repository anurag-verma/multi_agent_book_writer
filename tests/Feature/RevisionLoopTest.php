<?php

namespace Tests\Feature;

use App\AI\Services\WorkflowOrchestrator;
use Illuminate\Support\Facades\File;
use Tests\Fakes\FakeLLM;
use Tests\Fakes\FakeSearch;
use Tests\TestCase;

/**
 * The orchestrator is where the assignment's hardest requirement lives: a
 * revision loop that is genuinely bounded.
 *
 * These tests drive the whole pipeline with doubles, so the loop can be driven
 * through several iterations in milliseconds instead of waiting on real API
 * calls.
 */
class RevisionLoopTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        // The renderer writes real files. Point it at a temp directory so a test
        // run never overwrites the deliverables in output/.
        $this->outputDir = storage_path('framework/testing/output-'.uniqid());
        File::ensureDirectoryExists($this->outputDir);
        config(['ai.workflow.output_dir' => $this->outputDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDir);

        parent::tearDown();
    }
    /**
     * Responses for one chapter through the Writer, in call order. Index 0 is
     * the first draft; each later entry is one revision.
     *
     * @param  array<int, array<string, string>>  $bodies
     * @return array<int, string>
     */
    private function writerResponses(array $bodies): array
    {
        $out = [];

        foreach ($bodies as $title => $body) {
            $out[] = json_encode(['title' => $title, 'body' => $body]);
        }

        return $out;
    }

    private function factVerdict(int $citation, string $status, string $reason): string
    {
        return json_encode(['verdicts' => [[
            'citation' => $citation,
            'status' => $status,
            'reason' => $reason,
        ]]]);
    }

    /** Planner response with one chapter, using the DTO's real field names. */
    private function planResponse(): string
    {
        return json_encode([
            'title' => 'UPI for Small Business',
            'audience' => 'Shop owners',
            'chapters' => [[
                'title' => 'Getting started with UPI',
                'purpose' => 'Explain the basics',
                'key_topics' => ['what UPI is', 'how a customer pays'],
                'important_questions' => ['What is UPI?', 'How do I accept it?'],
                'suggested_research_areas' => ['UPI transaction statistics'],
            ]],
        ]);
    }

    /**
     * Researcher selection response. Sources are referenced by the candidate
     * index assigned during verification, which is always 0 for the first
     * verified candidate.
     */
    private function researchResponse(int $selected = 1): string
    {
        $sources = [];

        for ($i = 0; $i < $selected; $i++) {
            $sources[] = [
                'index' => $i,
                'organization' => $i === 0
                    ? 'National Payments Corporation of India'
                    : 'Reserve Bank of India',
                'claims_supported' => [$i === 0
                    ? 'UPI processed 16.58 billion transactions in the month.'
                    : 'Payment system operators must follow the issued guidelines.'],
            ];
        }

        return json_encode(['sources' => $sources]);
    }

    /**
     * Bind doubles and run a single-chapter pipeline.
     *
     * @param  array<int, string>  $llmResponses
     * @return array<string, mixed>
     */
    private function runWith(array $llmResponses, ?array $searchResults = null): array
    {
        $llm = new FakeLLM($llmResponses);
        $this->app->instance(\App\AI\Interfaces\LLMInterface::class, $llm);

        $search = new FakeSearch();
        $search->alwaysReturns($searchResults ?? FakeSearch::npciStatistics());
        $this->app->instance(\App\AI\Interfaces\SearchProviderInterface::class, $search);

        config([
            'ai.workflow.chapter_count' => 1,
            'ai.workflow.max_revisions' => 2,
        ]);

        $brief = \App\AI\DTO\BookBrief::make([
            'title' => 'UPI for Small Business',
            'audience' => 'Shop owners',
        ]);

        // Rebuild the pipeline so the fakes are injected into the agents.
        $orchestrator = new WorkflowOrchestrator(
            new \App\AI\Agents\PlannerAgent($llm),
            new \App\AI\Agents\ResearcherAgent($llm, $search, $this->app->make(\App\AI\Services\SourceVerifier::class)),
            new \App\AI\Agents\WriterAgent($llm, $this->app->make(\App\AI\Services\CitationService::class)),
            new \App\AI\Agents\FactCheckerAgent(
                $llm,
                $this->app->make(\App\AI\Services\CitationService::class),
                $this->app->make(\App\AI\Services\SourceVerifier::class),
            ),
            new \App\AI\Agents\EditorAgent($llm, $this->app->make(\App\AI\Services\CitationService::class)),
            $this->app->make(\App\AI\Services\FinalValidator::class),
            $this->app->make(\App\AI\Services\BookRenderer::class),
        );

        $result = $orchestrator->run($brief);
        $result['_llm_calls'] = $llm->callCount();

        return $result;
    }

    public function test_a_single_chapter_generates_end_to_end(): void
    {
        // Planner -> Researcher -> Writer -> FactChecker -> Editor.
        $body = str_repeat('UPI payments are quick and cheap for a small shop [1]. ', 12)
            ."\n\nTakeaway: Start small and accept UPI.";

        $responses = [
            $this->planResponse(),
            $this->researchResponse(),
            // Writer draft
            json_encode(['title' => 'Getting started with UPI', 'body' => $body]),
            $this->factVerdict(1, 'PASS', 'The source states the transaction volume.'),
            // Editor
            json_encode(['title' => 'Getting started with UPI', 'body' => $body]),
        ];

        $result = $this->runWith($responses);

        $this->assertCount(1, $result['chapters'] ?? []);
        $this->assertArrayHasKey('validation', $result);
        $this->assertArrayHasKey('events', $result);
    }

    public function test_the_loop_stops_after_max_revisions_even_when_every_draft_fails(): void
    {
        config(['ai.workflow.max_revisions' => 2]);

        $badBody = "A claim that fails [1].\n\nTakeaway: Nothing worked.";

        $responses = [
            $this->planResponse(),
            $this->researchResponse(),
            // Writer draft 1
            json_encode(['title' => 'Ch', 'body' => $badBody]),
            // FactChecker 1 -> FAIL
            $this->factVerdict(1, 'FAIL', 'Source does not contain the claim.'),
            // Writer revision 1
            json_encode(['title' => 'Ch', 'body' => $badBody]),
            // FactChecker 2 -> FAIL
            $this->factVerdict(1, 'FAIL', 'Source still does not contain the claim.'),
            // Writer revision 2 (final allowed)
            json_encode(['title' => 'Ch', 'body' => $badBody]),
            // FactChecker 3 -> FAIL. Budget spent, so no further writer call.
            $this->factVerdict(1, 'FAIL', 'Source still does not contain the claim.'),
        ];

        $result = $this->runWith($responses);

        $revisionEvents = array_values(array_filter(
            $result['events'],
            static fn (array $e): bool => $e['type'] === 'revision'
        ));

        // Two revisions is the whole budget; the loop must not start a third.
        $this->assertCount(2, $revisionEvents);

        // The unresolved failure is reported rather than silently swallowed.
        $messages = implode(' ', array_column($result['events'], 'message'));
        $this->assertStringContainsString('unresolved citation', $messages);
        $this->assertStringContainsString('rather than looping', $messages);

        // A chapter is still returned, with the problem recorded.
        $this->assertCount(1, $result['chapters']);
        $this->assertFalse($result['success']);
    }

    public function test_a_clean_chapter_is_not_revised(): void
    {
        config(['ai.workflow.max_revisions' => 2]);

// Long enough to clear the 600-word minimum, so the only variable under test is
// whether a passing chapter avoids the revision loop.
$sentence = 'UPI payments are quick and cheap for a small shop [1]. ';
$body = str_repeat($sentence, (int) ceil(610 / 9))."\n\nTakeaway: Start small.";

$responses = [
            $this->planResponse(),
            $this->researchResponse(),
            json_encode(['title' => 'Ch', 'body' => $body]),
            $this->factVerdict(1, 'PASS', 'Supported.'),
            json_encode(['title' => 'Ch', 'body' => $body]),
        ];

        $result = $this->runWith($responses);

        $revisionEvents = array_filter($result['events'], static fn (array $e): bool => $e['type'] === 'revision');
        $this->assertCount(0, $revisionEvents);
    }

    public function test_a_missing_takeaway_triggers_a_revision_that_fixes_it(): void
    {
        config(['ai.workflow.max_revisions' => 2]);

        // Fact checking passes on every attempt, so the only thing that can
        // trigger a revision is the missing Takeaway line. Found in a live run:
        // without this the loop converged on a chapter the validator rejects.
        $sentence = 'UPI payments are quick and cheap for a small shop [1]. ';
        $noTakeaway = str_repeat($sentence, (int) ceil(610 / 9));
        $withTakeaway = $noTakeaway."\n\nTakeaway: Start small.";

        $responses = [
            $this->planResponse(),
            $this->researchResponse(),
            json_encode(['title' => 'Ch', 'body' => $noTakeaway]),
            $this->factVerdict(1, 'PASS', 'Supported.'),
            json_encode(['title' => 'Ch', 'body' => $withTakeaway]),
            $this->factVerdict(1, 'PASS', 'Supported.'),
        ];

        $result = $this->runWith($responses);

        $revisionEvents = array_values(array_filter(
            $result['events'],
            static fn (array $e): bool => $e['type'] === 'revision'
        ));

        $this->assertCount(1, $revisionEvents);
        $this->assertStringContainsString('Takeaway', $revisionEvents[0]['message']);
        $this->assertTrue($result['success']);
    }

    public function test_text_after_the_takeaway_triggers_a_revision(): void
    {
        config(['ai.workflow.max_revisions' => 2]);

        $sentence = 'UPI payments are quick and cheap for a small shop [1]. ';
        $misplaced = str_repeat($sentence, (int) ceil(610 / 9))
            ."\n\nTakeaway: Start small.\n\nBut shopkeepers should always test first.";
        $correct = str_repeat($sentence, (int) ceil(610 / 9))."\n\nTakeaway: Start small.";

        $responses = [
            $this->planResponse(),
            $this->researchResponse(),
            json_encode(['title' => 'Ch', 'body' => $misplaced]),
            $this->factVerdict(1, 'PASS', 'Supported.'),
            json_encode(['title' => 'Ch', 'body' => $correct]),
            $this->factVerdict(1, 'PASS', 'Supported.'),
        ];

        $result = $this->runWith($responses);

        $this->assertTrue($result['success']);
    }

    public function test_an_edit_that_destroys_the_takeaway_is_discarded(): void
    {
        config(['ai.workflow.max_revisions' => 2]);

        // The Editor is an optional improvement, so it must never be able to
        // ship a chapter that breaks a hard rule. Found in a live run, where a
        // clean Writer draft lost its Takeaway during editing.
        $sentence = 'UPI payments are quick and cheap for a small shop [1]. ';
        $good = str_repeat($sentence, (int) ceil(610 / 9))."\n\nTakeaway: Start small.";
        $mangled = str_repeat($sentence, (int) ceil(610 / 9));

        $responses = [
            $this->planResponse(),
            $this->researchResponse(),
            json_encode(['title' => 'Ch', 'body' => $good]),   // Writer: correct
            $this->factVerdict(1, 'PASS', 'Supported.'),
            json_encode(['title' => 'Ch', 'body' => $mangled]), // Editor: drops it
        ];

        $result = $this->runWith($responses);

        // The verified draft survives rather than the broken edit.
        $this->assertStringContainsString('Takeaway:', $result['chapters'][0]['body']);

        $messages = implode(' ', array_column($result['events'], 'message'));
        $this->assertStringContainsString('broke required formatting', $messages);
        $this->assertStringContainsString('Keeping the verified draft', $messages);

        $this->assertTrue($result['success']);
    }

    public function test_a_length_check_counts_prose_not_the_takeaway_line(): void
    {
        config(['ai.workflow.max_revisions' => 2]);
        config(['ai.workflow.min_chapter_words' => 600]);

        // 595 words of prose plus a 25 word Takeaway is 620 words of body. The
        // shipping policy is defined on prose, so this chapter is too short and
        // must be sent back. Measuring the raw body let it through, and the
        // validator then rejected it -- a live bug.
        // 10 words per sentence once the [1] marker is stripped. 59 repetitions is 590
        // words of prose, under the 600 minimum, but 605 words of body once the
        // Takeaway is added.
        $sentence = 'UPI payments are quick and cheap for a small shop [1]. ';
        $prose = str_repeat($sentence, 59);
        $tooShort = $prose."\n\nTakeaway: ".trim(str_repeat('Start small today. ', 3));
        $longEnough = str_repeat($sentence, 62)."\n\nTakeaway: Start small.";

        $measured = (new \App\AI\DTO\ChapterDraft(1, 'Ch', $tooShort, []))->wordCount();
        $this->assertLessThan(600, $measured, 'fixture must be under the prose minimum');

        $responses = [
            $this->planResponse(),
            $this->researchResponse(),
            json_encode(['title' => 'Ch', 'body' => $tooShort]),
            $this->factVerdict(1, 'PASS', 'Supported.'),
            json_encode(['title' => 'Ch', 'body' => $longEnough]),
            $this->factVerdict(1, 'PASS', 'Supported.'),
        ];

        $result = $this->runWith($responses);

        $messages = implode(' ', array_column($result['events'], 'message'));
        $this->assertStringContainsString('Revision 1 required', $messages);
        $this->assertTrue($result['success']);
    }

    public function test_an_edit_that_drops_a_citation_is_discarded(): void
    {
        config(['ai.workflow.max_revisions' => 2]);

        // Fact checking already passed, so losing a marker during editing turns
        // a verified claim into an uncited one.
        // "UPI settled 16.58 billion transactions in October 2024 [1]." is 8 words,
        // "UPI is accepted across India [2]." is 5, so 13 per pair.
        $first = 'UPI settled 16.58 billion transactions in October 2024 [1]. ';
        $second = 'UPI is accepted across India [2]. ';
        $good = str_repeat($first.$second, 48)."\n\nTakeaway: Start small.";
        $stripped = str_repeat($first, 78)."\n\nTakeaway: Start small.";

        $responses = [
            $this->planResponse(),
            $this->researchResponse(2),
            json_encode(['title' => 'Ch', 'body' => $good]),
            $this->factVerdict(1, 'PASS', 'Supported.'),
            json_encode(['title' => 'Ch', 'body' => $stripped]),
        ];

        $result = $this->runWith(
            $responses,
            array_merge(FakeSearch::npciStatistics(), FakeSearch::rbiGuidance())
        );

        $messages = implode(' ', array_column($result['events'], 'message'));
        $this->assertStringContainsString('dropped citation', $messages);
        $this->assertStringContainsString('Keeping the verified draft', $messages);

        // The surviving chapter still cites both sources.
        $this->assertStringContainsString('[2]', $result['chapters'][0]['body']);
    }

    public function test_an_edit_that_fails_reverification_is_discarded(): void
    {
        config(['ai.workflow.max_revisions' => 2]);

        // The edit keeps its structure and its citations, so only a second
        // Fact Checker pass can catch that the rewrite drifted away from the
        // source. Without re-verification this chapter ships unverified.
        $sentence = 'UPI payments are quick and cheap for a small shop [1]. ';
        $before = str_repeat($sentence, 66)."\n\nTakeaway: Start small.";
        $after = str_repeat($sentence, 66)."\n\nTakeaway: Start small today, at your own pace.";

        $responses = [
            $this->planResponse(),
            $this->researchResponse(),
            json_encode(['title' => 'Ch', 'body' => $before]),
            $this->factVerdict(1, 'PASS', 'The source states this.'),
            json_encode(['title' => 'Ch', 'body' => $after]),
            $this->factVerdict(1, 'FAIL', 'The source does not support the reworded claim.'),
        ];

        $result = $this->runWith($responses);

        $messages = implode(' ', array_column($result['events'], 'message'));
        $this->assertStringContainsString('re-checked after editing', $messages);
        $this->assertStringContainsString('did not survive re-verification', $messages);
        $this->assertStringContainsString('Keeping the verified draft', $messages);

        // The pre-edit draft is what ships.
        $this->assertSame(
            rtrim($before),
            rtrim($result['chapters'][0]['body'])
        );

        // The book still passes, because the text that ships is the draft that
        // was verified. The rejected edit is recorded as a warning rather than
        // being allowed to block on a problem that no longer exists in the book.
        $this->assertTrue($result['success']);

        $warnings = array_values(array_filter(
            $result['events'],
            static fn (array $e): bool => str_contains($e['message'], 'did not survive re-verification')
        ));
        $this->assertNotSame([], $warnings, 'the rejected edit must stay visible in the log');
    }

    public function test_a_planner_failure_aborts_the_run_without_crashing(): void
    {
        $result = $this->runWith([
            json_encode(['title' => 'T', 'chapters' => []]), // no usable chapters
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Planner', $result['failed_agent']);
    }
}