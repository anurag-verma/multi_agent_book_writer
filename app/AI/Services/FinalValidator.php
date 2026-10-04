<?php

namespace App\AI\Services;

use App\AI\DTO\ChapterDraft;
use App\AI\DTO\FactCheckResult;
use App\AI\DTO\ValidationReport;
use App\AI\Helpers\TextUtils;
use Illuminate\Support\Facades\Log;

/**
 * The Final Validator.
 *
 * Every check here is deterministic PHP. None of it calls an LLM, because none of
 * it needs one: counting words, matching [n] markers to reference entries and
 * locating the Takeaway line are string operations. Spending a model call to
 * discover a missing citation would be slower, costlier and less reliable.
 *
 * The one thing it does consume is the Fact Checker's already-computed verdicts.
 * Those are not re-derived, they are folded in, because a book that still has an
 * unresolved FAIL must not be reported as a success.
 */
class FinalValidator
{
    public function __construct(private readonly CitationService $citations) {}

    /**
     * @param  array<int, ChapterDraft>              $chapters
     * @param  array<int, FactCheckResult>           $factChecks  Keyed by chapter number.
     */
    public function validate(array $chapters, int $expectedChapters = 3, array $factChecks = []): ValidationReport
    {
        $config = config('ai.workflow');
        $min = (int) $config['min_chapter_words'];
        $max = (int) $config['max_chapter_words'];

        $errors = [];
        $warnings = [];
        $report = [];

        // Chapter count.
        if (count($chapters) !== $expectedChapters) {
            $errors[] = sprintf(
                'Expected exactly %d chapters, found %d.',
                $expectedChapters,
                count($chapters)
            );
        }

        foreach ($chapters as $chapter) {
            $number = $chapter->chapterNumber;
            $body = $chapter->body;

            // Errors are collected per chapter so each chapter gets an accurate
            // verdict instead of a global error list being pattern-matched.
            $chapterErrors = [];

            $prose = $chapter->proseOnly();
            $words = TextUtils::countWords($prose);
            $used = $chapter->citationsUsed();
            $referenced = array_map(
                static fn ($s): int => $s->citationId,
                $chapter->sources
            );

            // --- Title ---
            if (trim($chapter->title) === '') {
                $chapterErrors[] = "Chapter {$number} has no title.";
            }

            // --- Word count ---
            if ($words < $min) {
                $chapterErrors[] = sprintf('Chapter %d is %d words, below the %d word minimum.', $number, $words, $min);
            } elseif ($words > $max) {
                $chapterErrors[] = sprintf('Chapter %d is %d words, above the %d word maximum.', $number, $words, $max);
            }

            // --- Citations present at all ---
            if ($used === []) {
                $chapterErrors[] = "Chapter {$number} contains no citations.";
            }

            // --- Citation / reference consistency ---
            foreach ($this->citations->validate($chapter) as $message) {
                $chapterErrors[] = "Chapter {$number}: ".$message;
            }

            // --- Empty citation marker ---
            if (str_contains($body, '[]')) {
                $chapterErrors[] = "Chapter {$number} contains an empty citation marker '[]'.";
            }

            // --- Takeaway placement ---
            $takeawayOk = $this->validateTakeaway($chapter, $chapterErrors, $warnings);

            // --- No bullets in prose ---
            if (TextUtils::hasBulletPoints($prose)) {
                $chapterErrors[] = "Chapter {$number} contains a bullet-point list inside the chapter prose, which is not allowed.";
            }

            // --- Reference completeness ---
            foreach ($chapter->sources as $source) {
                if (! TextUtils::isValidUrl($source->url)) {
                    $chapterErrors[] = sprintf('Chapter %d reference [%d] has no working URL.', $number, $source->citationId);
                }
                if (trim($source->title) === '') {
                    $chapterErrors[] = sprintf('Chapter %d reference [%d] has no title.', $number, $source->citationId);
                }
                if (trim($source->organization) === '') {
                    $warnings[] = sprintf('Chapter %d reference [%d] has no organisation name.', $number, $source->citationId);
                }
            }

            // --- Chapter number sanity ---
            if ($number < 1) {
                $chapterErrors[] = sprintf('Chapter number %d is invalid.', $number);
            }

            // --- Unresolved fact-check failures block the book ---
            //
            // A chapter with no fact-check result at all is treated as a failure,
            // not a pass. The FactChecker runs inside a try/catch so one bad
            // chapter cannot take the run down, which means a crash there would
            // otherwise reach this point looking identical to a clean chapter.
            $factCheck = $factChecks[$number] ?? null;

            if (! $factCheck instanceof FactCheckResult) {
                $chapterErrors[] = sprintf(
                    'Chapter %d has no fact-check result, so its citations were never verified.',
                    $number
                );
            }

            if ($factCheck instanceof FactCheckResult && $factCheck->hasFailures()) {
                foreach ($factCheck->failures() as $failure) {
                    $chapterErrors[] = sprintf(
                        'Chapter %d: citation [%d] still fails fact checking after all revisions. Claim: "%s". Reason: %s',
                        $number,
                        $failure->citation,
                        mb_substr($failure->claim, 0, 120),
                        $failure->reason
                    );
                }
            }

            if ($factCheck instanceof FactCheckResult && $factCheck->uncertainCount() > 0) {
                $warnings[] = sprintf(
                    'Chapter %d has %d citation(s) the fact checker could not settle.',
                    $number,
                    $factCheck->uncertainCount()
                );
            }

            $report[] = [
                'chapter' => $number,
                'title' => $chapter->title,
                'word_count' => $words,
                'citations' => count($used),
                'references' => count($referenced),
                'takeaway' => $takeawayOk ? 'PASS' : 'FAIL',
                'fact_check' => $factCheck instanceof FactCheckResult
                    ? sprintf('%d passed, %d failed, %d uncertain', $factCheck->passedCount(), $factCheck->failedCount(), $factCheck->uncertainCount())
                    : 'not run (verification did not complete)',
                'validation' => $chapterErrors === [] ? 'PASS' : 'FAIL',
            ];

            $errors = array_merge($errors, $chapterErrors);
        }

        // Artifact presence is deliberately not checked here. validate() runs *before*
        // BookRenderer writes the files, so asserting their existence at this
        // point would always warn on a first run and silently read a stale file
        // from a previous run afterwards. The renderer reports its own paths.

        $passed = $errors === [];

        Log::info('[Validator] report', [
            'passed' => $passed,
            'errors' => count($errors),
            'warnings' => count($warnings),
        ]);

        return new ValidationReport($passed, $report, $errors, $warnings);
    }

    /**
     * Exactly one Takeaway line, and it must come immediately before the
     * reference list.
     *
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    private function validateTakeaway(ChapterDraft $chapter, array &$errors, array &$warnings): bool
    {
        $body = $chapter->body;

        $count = preg_match_all('/^\s*Takeaway:/mi', $body);

        if ($count === 0) {
            $errors[] = "Chapter {$chapter->chapterNumber} has no line beginning with 'Takeaway:'.";

            return false;
        }

        if ($count > 1) {
            $errors[] = sprintf(
                'Chapter %d has %d Takeaway lines; exactly one is required.',
                $chapter->chapterNumber,
                $count
            );

            return false;
        }

        if (preg_match('/^\s*Takeaway:\s*\S+/mi', $body) !== 1) {
            $errors[] = "Chapter {$chapter->chapterNumber} has an empty Takeaway line.";

            return false;
        }

        // Must be the last prose line. The reference list is deliberately not part of
        // the body: it is rendered from the chapter's verified SourceItems, so an
        // empty tail is the expected shape here, not a missing reference list.
        $after = $this->textAfterTakeaway($body);

        if (trim($after) === '') {
            return true;
        }

        // Anything that does appear after the Takeaway must look like references,
        // which catches a model that appended a stray paragraph.
        foreach (preg_split('/\R/u', $after) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\[\d+\]\s+\S+/u', $line) !== 1) {
                $errors[] = sprintf(
                    'Chapter %d has content between the Takeaway line and the reference list: "%s".',
                    $chapter->chapterNumber,
                    mb_substr($line, 0, 60)
                );

                return false;
            }
        }

        return true;
    }

    private function textAfterTakeaway(string $body): string
    {
        if (preg_match('/^\s*Takeaway:.*$/mi', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        return substr($body, $m[0][1] + strlen($m[0][0]));
    }
}