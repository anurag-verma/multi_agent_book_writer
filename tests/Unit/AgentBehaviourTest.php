<?php

namespace Tests\Unit;

use App\AI\Agents\FactCheckerAgent;
use App\AI\Agents\WriterAgent;
use App\AI\DTO\FactCheckItem;
use App\AI\Services\CitationService;
use Tests\Fakes\FakeLLM;
use Tests\Fakes\FakeSearch;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

/**
 * Agent behaviour with the model and the web replaced by doubles.
 *
 * These tests exist to pin down the rules that prompt text alone cannot
 * enforce: that a Writer cannot smuggle in a citation, and that a Fact Checker
 * verdict blocks the chapter.
 */
class AgentBehaviourTest extends TestCase
{
    use BuildsFixtures;

    private CitationService $citations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->citations = $this->app->make(CitationService::class);
    }

    private function jsonResponse(string $title, string $body): string
    {
        return json_encode(['title' => $title, 'body' => $body]);
    }

    // ---------------- Writer ----------------

    // ---------------- Researcher ----------------

    public function test_a_single_publisher_cannot_take_every_slot(): void
    {
        config(['ai.workflow.max_sources_per_domain' => 2]);

        // Six long PIB pages and two shorter pages from other publishers. Ranked
        // purely by content length the PIB pages would fill the whole chapter,
        // which is exactly what happened in live runs.
        $pib = [];
        for ($i = 0; $i < 6; $i++) {
            $pib[] = [
                'title' => 'PIB release '.$i,
                'url' => 'https://pib.gov.in/PressReleasePage.aspx?PRID=100'.$i,
                'snippet' => 'Statistics.',
                // Longer than the others, so length ranking would favour it.
                'raw_content' => str_repeat('UPI transaction volume and value data. ', 400),
                'score' => '0.9',
            ];
        }

        $other = [
            [
                'title' => 'NPCI UPI product page',
                'url' => 'https://npci.org.in/product/upi',
                'snippet' => 'About UPI.',
                'raw_content' => str_repeat('UPI is a real-time payment system. ', 120),
                'score' => '0.9',
            ],
            [
                'title' => 'RBI digital payments',
                'url' => 'https://rbi.org.in/Scripts/BS_PressReleaseDisplay.aspx',
                'snippet' => 'Guidance.',
                'raw_content' => str_repeat('Payment system operators must comply. ', 120),
                'score' => '0.9',
            ],
        ];

        $search = new FakeSearch();
        $search->alwaysReturns(array_merge($pib, $other));
        $this->app->instance(\App\AI\Interfaces\SearchProviderInterface::class, $search);

        // The model obligingly picks every PIB page it was offered.
        $selection = [];
        foreach (range(0, 5) as $i) {
            $selection[] = [
                'index' => $i,
                'organization' => 'Press Information Bureau',
                'claims_supported' => ['UPI transaction volumes rose.'],
            ];
        }

        $this->app->instance(
            \App\AI\Interfaces\LLMInterface::class,
            new FakeLLM([json_encode(['sources' => $selection])])
        );

        $researcher = $this->app->make(\App\AI\Agents\ResearcherAgent::class);
        $package = $researcher->research($this->brief(), $this->outline());

        $this->assertGreaterThan(0, count($package->sources));

        $domains = [];
        foreach ($package->sources as $source) {
            $host = strtolower((string) parse_url($source->url, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            $domains[] = implode('.', array_slice(explode('.', $host), -2));
        }

        $counts = array_count_values($domains);

        // The cap holds, and the surplus PIB pages the model asked for were
        // dropped rather than silently accepted.
        $this->assertLessThanOrEqual(2, max($counts));

        $pibSources = array_filter(
            $package->sources,
            static fn ($s): bool => str_contains($s->url, 'pib.gov.in')
        );

        $this->assertCount(2, $pibSources);
    }

    public function test_distinct_official_publishers_are_not_treated_as_one(): void
    {
        // The bug this guards: taking the last two labels folds NPCI, the RBI and
        // every other *.org.in host into "org.in", and folds PIB, the Finance
        // Ministry and data.gov.in into "gov.in". The diversity cap then rations
        // slots between different regulators as though they were one publisher.
        //
        // With a cap of one source per publisher, correct grouping leaves six
        // publishers standing. The old key produced three.
        config(['ai.workflow.max_sources_per_domain' => 1]);

        $pages = [
            'https://npci.org.in/product/upi' => 'NPCI',
            'https://pay.npci.org.in/technology' => 'NPCI subdomain',
            'https://rbi.org.in/Scripts/BS_PressReleaseDisplay.aspx' => 'RBI',
            'https://pib.gov.in/PressReleasePage.aspx?PRID=1' => 'PIB',
            'https://financialservices.gov.in/annual-report' => 'Ministry of Finance',
            'https://data.gov.in/dataset' => 'data.gov.in',
            'https://www.ecb.europa.eu/stats' => 'ECB',
        ];

        $results = [];

        foreach ($pages as $url => $label) {
            $results[] = [
                'title' => $label,
                'url' => $url,
                'snippet' => 'Payment data.',
                'raw_content' => str_repeat('UPI and payment system statistics. ', 120),
                'score' => '0.9',
            ];
        }

        $search = new FakeSearch();
        $search->alwaysReturns($results);
        $this->app->instance(\App\AI\Interfaces\SearchProviderInterface::class, $search);

        // Ask for every index so the only thing that can group or drop a source
        // is the publisher-identity logic itself.
        $selection = [];

        foreach (array_keys($results) as $i) {
            $selection[] = [
                'index' => $i,
                'organization' => 'Test',
                'claims_supported' => ['UPI statistics are published.'],
            ];
        }

        $this->app->instance(
            \App\AI\Interfaces\LLMInterface::class,
            new FakeLLM([json_encode(['sources' => $selection])])
        );

        $researcher = $this->app->make(\App\AI\Agents\ResearcherAgent::class);
        $package = $researcher->research($this->brief(), $this->outline());

        $hosts = [];

        foreach ($package->sources as $source) {
            $host = strtolower((string) parse_url($source->url, PHP_URL_HOST));
            $hosts[] = preg_replace('/^www\./', '', $host) ?? $host;
        }

        // Six publishers: the NPCI subdomain was folded into NPCI, and every
        // other host stood on its own.
        sort($hosts);
        $this->assertSame([
            'data.gov.in',
            'ecb.europa.eu',
            'financialservices.gov.in',
            'npci.org.in',
            'pib.gov.in',
            'rbi.org.in',
        ], $hosts);

        foreach (['npci.org.in', 'rbi.org.in', 'pib.gov.in', 'financialservices.gov.in', 'data.gov.in', 'ecb.europa.eu'] as $publisher) {
            $this->assertContains($publisher, $hosts, $publisher.' should stand on its own');
        }

        $this->assertNotContains('pay.npci.org.in', $hosts, 'a subdomain should not survive alongside its parent');
    }

    public function test_blocked_domains_are_never_offered_to_the_model(): void
    {
        $search = new FakeSearch();
        $search->alwaysReturns([
            [
                'title' => 'A content farm explainer',
                'url' => 'https://geeksforgeeks.org/upi-explained',
                'snippet' => 'UPI explained.',
                'raw_content' => str_repeat('UPI is a payment system. ', 300),
                'score' => '0.9',
            ],
            [
                'title' => 'NPCI UPI product page',
                'url' => 'https://npci.org.in/product/upi',
                'snippet' => 'About UPI.',
                'raw_content' => str_repeat('UPI is a real-time payment system. ', 120),
                'score' => '0.9',
            ],
        ]);

        $this->app->instance(\App\AI\Interfaces\SearchProviderInterface::class, $search);

        // The model is asked for index 0. Filtering happens before the catalogue is
        // built, so that index now points at the surviving NPCI page -- what
        // matters is that the blocked domain can never be selected.
        $this->app->instance(
            \App\AI\Interfaces\LLMInterface::class,
            new FakeLLM([json_encode(['sources' => [[
                'index' => 0,
                'organization' => 'National Payments Corporation of India',
                'claims_supported' => ['UPI is a real-time payment system.'],
            ]]])])
        );

        $researcher = $this->app->make(\App\AI\Agents\ResearcherAgent::class);
        $package = $researcher->research($this->brief(), $this->outline());

        $urls = array_map(static fn ($s): string => $s->url, $package->sources);

        foreach ($urls as $url) {
            $this->assertStringNotContainsString('geeksforgeeks.org', $url);
        }

        $this->assertNotSame([], $urls, 'the official source should still be usable');
        $this->assertStringContainsString('npci.org.in', implode(' ', $urls));
    }

    public function test_the_writer_cannot_emit_a_citation_the_researcher_never_found(): void
    {
        $llm = new FakeLLM([
            $this->jsonResponse('Getting started', 'UPI processed 16.58 billion transactions [1]. Someone also said 99 billion [4].'),
        ]);

        $writer = new WriterAgent($llm, $this->citations);

        [$draft, $stripped] = $writer->write($this->brief(), $this->outline(), $this->package());

        $this->assertSame([4], $stripped);
        $this->assertNotContains(4, $draft->citationsUsed());
        $this->assertStringContainsString('[1]', $draft->body);
    }

    public function test_a_reference_block_from_the_model_is_discarded(): void
    {
        $llm = new FakeLLM([
            $this->jsonResponse('T', "Body text with a citation [1].\n\nTakeaway: Try it.\n\nReferences\n[1] Invented. \"Fake\". https://example.com/fake"),
        ]);

        $writer = new WriterAgent($llm, $this->citations);
        [$draft] = $writer->write($this->brief(), $this->outline(), $this->package());

        $this->assertStringNotContainsString('example.com/fake', $draft->body);
        $this->assertStringNotContainsString('Invented', $draft->body);
    }

    public function test_duplicate_takeaway_lines_are_reduced_to_one(): void
    {
        $llm = new FakeLLM([
            $this->jsonResponse('T', "Prose [1].\n\nTakeaway: First one.\n\nMore prose [1].\n\nTakeaway: The real final one."),
        ]);

        $writer = new WriterAgent($llm, $this->citations);
        [$draft] = $writer->write($this->brief(), $this->outline(), $this->package());

        $this->assertSame(1, preg_match_all('/^\s*Takeaway:/mi', $draft->body));
        $this->assertStringContainsString('The real final one', $draft->body);
    }

    public function test_revision_feedback_is_passed_to_the_model(): void
    {
        $llm = new FakeLLM([
            $this->jsonResponse('T', 'Fixed prose [1]. Takeaway: Done.'),
        ]);

        $writer = new WriterAgent($llm, $this->citations);

        $writer->write(
            $this->brief(),
            $this->outline(),
            $this->package(),
            'Citation [1] was marked FAIL. Reason: unsupported figure.',
            'The old broken draft.'
        );

        $prompt = $llm->lastUserPrompt();
        $this->assertStringContainsString('REVISION REQUIRED', $prompt);
        $this->assertStringContainsString('unsupported figure', $prompt);
        $this->assertStringContainsString('old broken draft', $prompt);
    }

    // ---------------- Fact Checker ----------------

    private function factChecker(FakeLLM $llm): FactCheckerAgent
    {
        return new FactCheckerAgent(
            $llm,
            $this->citations,
            $this->app->make(\App\AI\Services\SourceVerifier::class),
        );
    }

    public function test_a_failing_citation_blocks_the_chapter(): void
    {
        $verdict = json_encode(['verdicts' => [[
            'citation' => 1,
            'status' => 'FAIL',
            'reason' => 'The page does not mention this figure.',
        ]]]);

        $result = $this->factChecker(new FakeLLM([$verdict]))
            ->check($this->draft('UPI processed 16.58 billion transactions [1].'), $this->package());

        $this->assertTrue($result->hasFailures());
        $this->assertSame(1, $result->failedCount());
        $this->assertStringContainsString('does not mention', $result->toRevisionFeedback());
    }

    public function test_a_citation_the_model_never_judged_is_not_reported_as_passed(): void
    {
        // The model returns a verdict for citation [1] only. Citation [2] exists
        // and its URL is reachable, but support for it was never established.
        // Found in a live run, where [2] was reported PASS with the reason
        // "Pending semantic support check" -- a silent pass.
        $verdict = json_encode(['verdicts' => [[
            'citation' => 1,
            'status' => 'PASS',
            'reason' => 'The page states this directly.',
        ]]]);

        $body = 'UPI processed 16.58 billion transactions [1]. '
            .'UPI also reached 200 million users [2].';

        $result = $this->factChecker(new FakeLLM([$verdict]))->check($this->draft($body), $this->package());

        // [1] was genuinely judged, so it passes.
        $this->assertSame(1, $result->passedCount());

        // [2] was not, so it must not be counted as a pass.
        $this->assertSame(1, $result->uncertainCount());
        $this->assertFalse($result->hasFailures());

        $unjudged = null;
        foreach ($result->items as $item) {
            if ($item->citation === 2) {
                $unjudged = $item;
            }
        }

        $this->assertNotNull($unjudged);
        $this->assertNotSame('PASS', $unjudged->status);
        $this->assertStringNotContainsString('Pending semantic', $unjudged->reason);
    }

    public function test_an_uncertain_verdict_is_reported_but_does_not_block(): void
    {
        $verdict = json_encode(['verdicts' => [[
            'citation' => 1,
            'status' => 'UNCERTAIN',
            'reason' => 'The page could not be read.',
        ]]]);

        $result = $this->factChecker(new FakeLLM([$verdict]))
            ->check($this->draft('UPI processed 16.58 billion transactions [1].'), $this->package());

        $this->assertFalse($result->hasFailures());
        $this->assertSame(1, $result->uncertainCount());
        // Still surfaced to the writer and the UI.
        $this->assertStringContainsString('UNCERTAIN', $result->toRevisionFeedback());
    }

    public function test_a_citation_with_no_source_fails_without_consulting_the_model(): void
    {
        $llm = new FakeLLM([]);

        // [9] is not in the package, so citation enforcement already removed it.
        // Build the inconsistent draft directly to isolate the check.
        $draft = $this->draft('A claim [9].', []);

        $result = $this->factChecker($llm)->check($draft, $this->package());

        $this->assertTrue($result->hasFailures());
        $this->assertSame(0, $llm->callCount(), 'The LLM must not be called when no source exists.');
        $this->assertSame('existence', $result->failures()[0]->checkType);
    }

    public function test_a_chapter_with_no_citations_at_all_fails(): void
    {
        $llm = new FakeLLM([]);
        $draft = $this->draft('UPI is a payment system. It costs nothing.');

        $result = $this->factChecker($llm)->check($draft, $this->package());

        $this->assertTrue($result->hasFailures());
        $this->assertSame(FactCheckItem::FAIL, $result->failures()[0]->status);
        $this->assertStringContainsString('no citations', $result->failures()[0]->reason);
        $this->assertSame(0, $llm->callCount());
    }

    public function test_a_deterministic_failure_outweighs_a_model_pass(): void
    {
        // The model claims PASS, but the source has no usable URL text, so the
        // deterministic layer must still win.
        $verdict = json_encode(['verdicts' => [[
            'citation' => 1,
            'status' => 'PASS',
            'reason' => 'Looks fine to me.',
        ]]]);

        $broken = new \App\AI\DTO\SourceItem(
            1, 'NPCI', 'Stats', 'not-a-valid-url', ['claim'], '', false, '', 0
        );

        $result = $this->factChecker(new FakeLLM([$verdict]))->check(
            $this->draft('A claim [1].', [$broken]),
            new \App\AI\DTO\ResearchPackage(1, 'T', [$broken]),
        );

        $this->assertTrue($result->hasFailures());
    }

    public function test_revision_feedback_names_the_citation_and_the_reason(): void
    {
        $verdict = json_encode(['verdicts' => [[
            'citation' => 1,
            'status' => 'FAIL',
            'reason' => 'Source contradicts the claim.',
        ]]]);

        $result = $this->factChecker(new FakeLLM([$verdict]))
            ->check($this->draft('A claim [1].'), $this->package());

        $feedback = $result->toRevisionFeedback();
        $this->assertStringContainsString('[1]', $feedback);
        $this->assertStringContainsString('contradicts', $feedback);
    }
}