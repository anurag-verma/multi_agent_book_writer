<?php

namespace Tests\Unit;

use App\AI\DTO\ResearchPackage;
use App\AI\DTO\SourceItem;
use App\AI\Services\CitationService;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

/**
 * The central guarantee of the whole project: the Writer cannot produce a
 * citation that the Researcher did not verify.
 */
class CitationEnforcementTest extends TestCase
{
    use BuildsFixtures;

    private CitationService $citations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->citations = $this->app->make(CitationService::class);
    }

    public function test_a_citation_with_no_matching_source_is_removed(): void
    {
        [$draft, $stripped] = $this->citations->enforce(
            $this->draft('Volume reached 16.58 billion [1] but also 99 billion [7].'),
            $this->package(),
        );

        $this->assertSame([7], $stripped);
        $this->assertNotContains(7, $draft->citationsUsed());
        $this->assertContains(1, $draft->citationsUsed());
    }

    public function test_a_reference_for_a_dropped_citation_is_removed_too(): void
    {
        [$draft] = $this->citations->enforce(
            $this->draft('Only [1] is real here.'),
            $this->package(),
        );

        // Reference [2] existed in the package but the prose never cites it.
        $this->assertSame([1], $draft->citationsUsed());
        $this->assertCount(1, $draft->sources);
        $this->assertSame(1, $draft->sources[0]->citationId);
    }

    public function test_unused_researched_sources_produce_a_warning(): void
    {
        [$draft] = $this->citations->enforce(
            $this->draft('Only [1] is cited.'),
            $this->package(),
        );

        $warnings = implode(' ', $draft->warnings);
        $this->assertStringContainsString('[2]', $warnings);
        $this->assertStringContainsString('not cited', $warnings);
    }

    public function test_gaps_in_reference_numbering_are_closed_by_renumbering(): void
    {
        // The Researcher numbers every source it verifies, so a chapter citing
        // only some of them would otherwise ship a list like [1], [2], [6].
        $package = new ResearchPackage(1, 'T', [
            $this->source(1),
            $this->source(2),
            $this->source(3),
            $this->source(4),
            $this->source(5),
            $this->source(6),
        ]);

        [$draft] = $this->citations->enforce(
            $this->draft('A fact [6]. Another [1]. And [2].'),
            $package,
        );

        $this->assertSame([1, 2, 3], $draft->citationsUsed());
        $this->assertSame([1, 2, 3], array_map(
            static fn (SourceItem $s): int => $s->citationId,
            $draft->sources
        ));

        // The body markers must be rewritten to match, not just the reference list.
        $this->assertStringContainsString('[1]', $draft->body);
        $this->assertStringNotContainsString('[6]', $draft->body);
        $this->assertStringNotContainsString('[4]', $draft->body);
    }

    public function test_renumbering_does_not_corrupt_markers_when_numbers_shift(): void
    {
        // [1] becomes [2] while [2] becomes [3]; a naive in-place replace would
        // cascade these into the wrong labels.
        $package = new ResearchPackage(1, 'T', [
            $this->source(3),
            $this->source(4),
            $this->source(5),
        ]);

        [$draft] = $this->citations->enforce(
            $this->draft('One [3]. Two [4]. Three [5].'),
            $package,
        );

        $this->assertSame([1, 2, 3], $draft->citationsUsed());
        $this->assertSame('One [1]. Two [2]. Three [3].', $draft->body);
    }

    public function test_citations_are_numbered_by_order_of_first_use(): void
    {
        [$draft] = $this->citations->enforce(
            $this->draft('Second one first [2]. Then the other [1].'),
            $this->package(),
        );

        // [2] appears first, so it becomes [1].
        $this->assertSame([1, 2], $draft->citationsUsed());
        $this->assertSame('Second one first [1]. Then the other [2].', $draft->body);
    }

    public function test_renumbering_is_reported_as_a_warning(): void
    {
        $package = new ResearchPackage(1, 'T', [
            $this->source(1),
            $this->source(2),
            $this->source(6),
        ]);

        [$draft] = $this->citations->enforce(
            $this->draft('A fact [6]. And [1].'),
            $package,
        );

        $this->assertStringContainsString('made sequential', implode(' ', $draft->warnings));
    }

    public function test_reference_list_always_matches_the_citations_used(): void
    {
        [$draft] = $this->citations->enforce(
            $this->draft('Two facts: [1] and [2].'),
            $this->package(),
        );

        $this->assertSame($draft->citationsUsed(), array_map(
            static fn (SourceItem $s): int => $s->citationId,
            $draft->sources,
        ));
    }

    public function test_punctuation_is_tidied_after_a_marker_is_removed(): void
    {
        [$draft] = $this->citations->enforce(
            $this->draft('A claim [7] .Then more text.'),
            $this->package(),
        );

        $this->assertStringNotContainsString('  ', $draft->body);
        $this->assertStringNotContainsString(' .', $draft->body);
        $this->assertStringNotContainsString('[]', $draft->body);
    }

    public function test_claim_map_groups_sentences_by_citation(): void
    {
        $map = $this->citations->claimMap(
            $this->draft("First fact [1]. Second fact [2]. Third fact [1].")
        );

        $this->assertSame([1, 2], array_keys($map));
        $this->assertCount(2, $map[1]);
        $this->assertCount(1, $map[2]);
        $this->assertStringContainsString('First fact', $map[1][0]);
    }

    public function test_validate_reports_a_marker_missing_from_the_reference_list(): void
    {
        // Built by hand to bypass enforce(), simulating an inconsistent draft.
        $inconsistent = new \App\AI\DTO\ChapterDraft(
            1,
            'T',
            'Text with [1] and [2].',
            [$this->source(1)],
        );

        $errors = $this->citations->validate($inconsistent);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('[2]', implode(' ', $errors));
    }

    public function test_validate_reports_a_reference_never_cited(): void
    {
        $orphaned = new \App\AI\DTO\ChapterDraft(1, 'T', 'Text with [1].', [
            $this->source(1),
            $this->source(2),
        ]);

        $errors = $this->citations->validate($orphaned);

        $this->assertStringContainsString('never cited', implode(' ', $errors));
    }

    public function test_a_source_outside_the_package_cannot_be_cited_even_if_it_exists_elsewhere(): void
    {
        // The same URL exists in a different chapter's package. Citations are
        // scoped to the chapter, so this must still be stripped.
        $otherChapter = new ResearchPackage(2, 'Another chapter', [$this->source(9)]);

        [, $stripped] = $this->citations->enforce(
            $this->draft('A fact from another chapter [9].'),
            $this->package(),
        );

        $this->assertSame([9], $stripped);
        $this->assertNotSame([], $otherChapter->sources);
    }
}