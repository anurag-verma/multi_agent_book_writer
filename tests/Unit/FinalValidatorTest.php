<?php

namespace Tests\Unit;

use App\AI\Services\FinalValidator;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

/**
 * The Final Validator is the last gate before a book ships. Every rule here is
 * deterministic, which is why these tests never call a model.
 */
class FinalValidatorTest extends TestCase
{
    use BuildsFixtures;

    private FinalValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = $this->app->make(FinalValidator::class);
    }

    /**
     * A clean fact check for the given chapter numbers. A chapter with no fact
     * check at all is now a failure, so tests that are exercising some *other*
     * rule must supply one, exactly as the orchestrator does.
     *
     * @param  array<int, \App\AI\DTO\ChapterDraft>  $chapters
     * @return array<int, \App\AI\DTO\FactCheckResult>
     */
    private function passingFactChecks(array $chapters): array
    {
        $checks = [];

        foreach ($chapters as $draft) {
            $checks[$draft->chapterNumber] = new \App\AI\DTO\FactCheckResult(
                $draft->chapterNumber,
                [new \App\AI\DTO\FactCheckItem(
                    1,
                    'A supported claim [1].',
                    'https://npci.org.in/product/upi',
                    'NPCI',
                    \App\AI\DTO\FactCheckItem::PASS,
                    'The source states this.',
                    'support'
                )],
                0
            );
        }

        return $checks;
    }

    /** @param array<int, string> $sources */
    private function errorsFor(string $body, array $sources = []): string
    {
        $draft = $this->draft($body, $sources);
        [$enforced] = $this->app->make(\App\AI\Services\CitationService::class)
            ->enforce($draft, $this->package());

        return implode(' | ', $this->validator->validate(
            [$enforced],
            1,
            $this->passingFactChecks([$enforced])
        )->errors);
    }

    public function test_a_compliant_chapter_passes(): void
    {
        $chapter = $this->enforcedDraft($this->validBody(600));

        $report = $this->validator->validate(
            [$chapter],
            1,
            $this->passingFactChecks([$chapter])
        );

        $this->assertTrue($report->passed, implode(' | ', $report->errors));
        $this->assertSame([], $report->errors);
    }

    public function test_a_chapter_with_no_fact_check_is_not_treated_as_verified(): void
    {
        // If the FactChecker throws, the orchestrator catches it and carries on.
        // Without this the chapter would reach the validator looking identical to
        // a clean one and would ship with unverified citations.
        $report = $this->validator->validate([$this->enforcedDraft($this->validBody(600))], 1);

        $this->assertFalse($report->passed);
        $this->assertStringContainsString('no fact-check result', implode(' | ', $report->errors));
        $this->assertStringContainsString(
            'not run (verification did not complete)',
            json_encode($report->toArray())
        );
    }

    public function test_word_count_below_the_minimum_fails(): void
    {
        $errors = $this->errorsFor($this->validBody(120));

        $this->assertStringContainsString('below the 600 word minimum', $errors);
    }

    public function test_word_count_above_the_maximum_fails(): void
    {
        $errors = $this->errorsFor($this->validBody(1000));

        $this->assertStringContainsString('above the 900 word maximum', $errors);
    }

    public function test_a_chapter_without_citations_fails(): void
    {
        // Padding here must stay citation-free, otherwise the fixture would
        // accidentally acquire the [1] it is meant to be testing the absence of.
        $body = $this->pad(
            'Takeaway: Keep the setup simple. ',
            620,
            'UPI is a payment system that many people use every day. '
        );

        $errors = $this->errorsFor($body);

        $this->assertStringContainsString('contains no citations', $errors);
    }

    public function test_bullet_points_in_prose_fail(): void
    {
        $errors = $this->errorsFor($this->validBody(600)."\n\n- one thing\n- another thing");

        $this->assertStringContainsString('bullet-point list', $errors);
    }

    public function test_a_missing_takeaway_fails(): void
    {
        $body = str_replace('Takeaway: UPI is a cheap way', 'To summarise, UPI is a cheap way', $this->validBody(600));

        $this->assertStringContainsString('no line beginning with', $this->errorsFor($body));
    }

    public function test_two_takeaway_lines_fail(): void
    {
        $errors = $this->errorsFor($this->validBody(600)."\n\nTakeaway: A second summary line.");

        $this->assertStringContainsString('Takeaway lines', $errors);
    }

    public function test_an_empty_takeaway_fails(): void
    {
        $errors = $this->errorsFor(str_replace(
            'Takeaway: UPI is a cheap way for a small shop to accept digital payments.',
            'Takeaway:',
            $this->validBody(600)
        ));

        $this->assertStringContainsString('empty Takeaway', $errors);
    }

    public function test_prose_after_the_takeaway_fails(): void
    {
        $errors = $this->errorsFor($this->validBody(600)."\n\nOne more paragraph after the summary line.");

        $this->assertStringContainsString('between the Takeaway', $errors);
    }

    public function test_a_chapter_with_no_title_fails(): void
    {
        $draft = $this->enforcedDraft($this->validBody(600));
        $untitled = new \App\AI\DTO\ChapterDraft(1, '', $draft->body, $draft->sources);

        $this->assertStringContainsString('has no title', implode(' | ', $this->validator->validate(
            [$untitled],
            1,
            $this->passingFactChecks([$untitled])
        )->errors));
    }

    public function test_an_invalid_reference_url_fails(): void
    {
        $broken = new \App\AI\DTO\SourceItem(1, 'NPCI', 'Stats', 'not-a-url', ['claim'], 'text', true, '', 200);

        $errors = implode(' | ', $this->validator->validate([
            $this->draft($this->validBody(600), [$broken]),
        ], 1)->errors);

        $this->assertStringContainsString('no working URL', $errors);
    }

    public function test_wrong_chapter_count_fails(): void
    {
        $report = $this->validator->validate([$this->enforcedDraft($this->validBody(600))], 3);

        $this->assertFalse($report->passed);
        $this->assertStringContainsString('Expected exactly 3 chapters', implode(' | ', $report->errors));
    }

    public function test_each_chapter_gets_its_own_verdict(): void
    {
        $good = $this->enforcedDraft($this->validBody(600));

        $bad = new \App\AI\DTO\ChapterDraft(2, 'Too short', 'Short.', $good->sources);

        $report = $this->validator->validate([$good, $bad], 2, $this->passingFactChecks([$good, $bad]));

        $verdicts = array_column($report->chapters, 'validation', 'chapter');
        $this->assertSame('PASS', $verdicts[1]);
        $this->assertSame('FAIL', $verdicts[2]);
    }

    public function test_an_unresolved_fact_check_failure_fails_the_book(): void
    {
        $draft = $this->enforcedDraft($this->validBody(600));

        $factCheck = new \App\AI\DTO\FactCheckResult(1, [
            new \App\AI\DTO\FactCheckItem(
                1, 'UPI processed 16.58 billion transactions.',
                'https://npci.org.in/x', 'NPCI',
                \App\AI\DTO\FactCheckItem::FAIL,
                'The source text does not mention this figure.'
            ),
        ]);

        $report = $this->validator->validate([$draft], 1, [1 => $factCheck]);

        $this->assertFalse($report->passed);
        $this->assertStringContainsString('still fails fact checking', implode(' | ', $report->errors));
        $this->assertSame('FAIL', $report->chapters[0]['validation']);
    }

    public function test_an_uncertain_verdict_warns_but_does_not_fail(): void
    {
        $draft = $this->enforcedDraft($this->validBody(600));

        $factCheck = new \App\AI\DTO\FactCheckResult(1, [
            new \App\AI\DTO\FactCheckItem(
                1, 'A claim.', 'https://npci.org.in/x', 'NPCI',
                \App\AI\DTO\FactCheckItem::UNCERTAIN,
                'The page could not be read.'
            ),
        ]);

        $report = $this->validator->validate([$draft], 1, [1 => $factCheck]);

        $this->assertTrue($report->passed);
        $this->assertStringContainsString('could not settle', implode(' | ', $report->warnings));
    }

    public function test_summary_line_reports_totals(): void
    {
        $report = $this->validator->validate([$this->enforcedDraft($this->validBody(600))], 1);

        $this->assertStringContainsString('1 chapter', $report->summaryLine());
        $this->assertStringContainsString('words total', $report->summaryLine());
    }

    private function enforcedDraft(string $body): \App\AI\DTO\ChapterDraft
    {
        [$enforced] = $this->app->make(\App\AI\Services\CitationService::class)
            ->enforce($this->draft($body), $this->package());

        return $enforced;
    }
}