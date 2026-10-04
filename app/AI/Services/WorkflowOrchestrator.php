<?php

namespace App\AI\Services;

use App\AI\Agents\EditorAgent;
use App\AI\Agents\FactCheckerAgent;
use App\AI\Agents\PlannerAgent;
use App\AI\Agents\ResearcherAgent;
use App\AI\Agents\WriterAgent;
use App\AI\DTO\BookBrief;
use App\AI\DTO\ChapterDraft;
use App\AI\DTO\ChapterOutline;
use App\AI\DTO\FactCheckResult;
use App\AI\DTO\ResearchPackage;
use App\AI\DTO\SourceItem;
use App\AI\DTO\ValidationReport;
use App\AI\Exceptions\AgentException;
use Illuminate\Support\Facades\Log;

/**
 * Runs the whole pipeline and owns the revision loop.
 *
 *   Brief -> Planner -> Researcher -> Writer -> FactChecker
 *                                            |            |
 *                                       FAIL |            | PASS
 *                                            v            v
 *                                     Researcher/Writer  Editor
 *                                     (bounded retries)     |
 *                                            ^             v
 *                                            +---- FinalValidator -> Book
 *
 * Two rules keep this predictable:
 *
 *  1. The loop is bounded per chapter by MAX_REVISIONS. When the budget is spent
 *     the chapter stops being retried and is carried forward with its problems
 *     recorded. Nothing loops forever.
 *  2. An agent that throws never takes the run down. The failure is recorded,
 *     logged and surfaced in the report so the user sees what broke.
 */
class WorkflowOrchestrator
{
    public function __construct(
        private readonly PlannerAgent $planner,
        private readonly ResearcherAgent $researcher,
        private readonly WriterAgent $writer,
        private readonly FactCheckerAgent $factChecker,
        private readonly EditorAgent $editor,
        private readonly FinalValidator $validator,
        private readonly BookRenderer $renderer,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void|null  $onEvent
     * @return array<string, mixed>
     */
    public function run(BookBrief $brief, ?callable $onEvent = null): array
    {
        $maxRevisions = (int) config('ai.workflow.max_revisions', 2);
        $started = microtime(true);

        $events = [];
        $emit = function (string $type, string $message, array $context = []) use (&$events, $onEvent): void {
            $entry = [
                'type' => $type,
                'message' => $message,
                'context' => $context,
                'at' => now()->toDateTimeString(),
            ];
            $events[] = $entry;
            Log::info($message, $context);
            if ($onEvent !== null) {
                $onEvent($type, $message, $context);
            }
        };

        $emit('started', '[Workflow] Started', ['title' => $brief->title]);

        // ---- Planner ----
        try {
            $outline = $this->planner->plan($brief);
        } catch (\Throwable $e) {
            return $this->abort($emit, $e, 'Planner', $started, $events);
        }

        $emit('planner', '[Planner] Completed', [
            'chapters' => $outline->count(),
            'titles' => array_map(static fn ($c): string => $c->title, $outline->chapters),
        ]);

        $chapters = [];
        $factReports = [];

        foreach ($outline->chapters as $chapterOutline) {
            $number = $chapterOutline->chapterNumber;

            $emit('researcher', "[Researcher] Chapter {$number} research started", [
                'chapter' => $number,
            ]);

            try {
                $package = $this->researcher->research($brief, $chapterOutline);
            } catch (\Throwable $e) {
                $emit('error', "[Researcher] Chapter {$number} failed: ".$e->getMessage(), [
                    'chapter' => $number,
                ]);
                continue;
            }

            $emit('researcher', "[Researcher] Found ".count($package->sources)." sources", [
                'chapter' => $number,
                'sources' => array_map(static fn ($s): string => $s->organization, $package->sources),
            ]);

            $chapter = $this->writeAndVerify(
                $brief, $chapterOutline, $package, $maxRevisions, $emit, $factReports
            );

            if ($chapter === null) {
                continue;
            }

            // ---- Editor ----
            //
            // The Editor is an optional improvement, never a licence to break a
            // hard rule. A live run had a clean Writer draft lose its Takeaway
            // here, so every edit is re-checked and discarded if it regressed.
            $factCheckedDraft = $chapter;

            try {
                $edited = $this->editor->edit($brief, $chapter, $package, $factReports[$number]);

                $regressions = $this->editRegressions($factCheckedDraft, $edited);

                if ($regressions !== []) {
                    $emit('warning', sprintf(
                        '[Editor] Chapter %d edit broke required formatting (%s). '
                        .'Keeping the verified draft instead.',
                        $number,
                        implode(' ', $regressions)
                    ), ['chapter' => $number]);

                    $chapter = $factCheckedDraft;
                } else {
                    // Structural rules are necessary but not sufficient: an edit
                    // can keep every citation and still drift away from what the
                    // source says. Re-verify, and fall back to the verified draft
                    // if the edit introduced anything the Fact Checker rejects.
                    $recheck = $this->recheckEditedDraft($edited, $package, $number, $emit);

                    // A failed re-check *or* an inability to re-check both mean
                    // the edit is not something we have verified.
                    if (! $recheck instanceof FactCheckResult || $recheck->hasFailures()) {
                        $emit('warning', sprintf(
                            '[Editor] Chapter %d edit did not survive re-verification%s. '
                            .'Keeping the verified draft instead.',
                            $number,
                            $recheck instanceof FactCheckResult
                                ? ' ('.$recheck->failedCount().' citation failure(s))'
                                : ''
                        ), ['chapter' => $number]);

                        $chapter = $factCheckedDraft;
                    } else {
                        $chapter = $edited;

                        // The shipped text is the edited text, so the verdict we
                        // report must be the one that describes it.
                        $factReports[$number] = $recheck;

                        $emit('editor', "[Editor] Chapter {$number} completed and re-verified", [
                            'chapter' => $number,
                            'words' => $chapter->wordCount(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $emit('warning', "[Editor] Chapter {$number} skipped: ".$e->getMessage(), [
                    'chapter' => $number,
                ]);

                $chapter = $factCheckedDraft;
            }

            $chapters[] = $chapter;
        }

        // ---- Final Validator ----
        // Fact-check verdicts are folded in so an unresolved FAIL cannot be
        // reported as a successful run.
        $report = $this->validator->validate($chapters, $brief->chapterCount, $factReports);

        $paths = $this->renderer->writeArtifacts($brief, $chapters, $report);

        $emit('validator', '[Validator] '.$report->summaryLine(), [
            'passed' => $report->passed,
        ]);

        $elapsed = round(microtime(true) - $started, 1);

        $emit('finished', '[Workflow] Finished', ['seconds' => $elapsed]);

        return [
            'success' => $report->passed,
            'brief' => $brief->toArray(),
            'outline' => $outline->toArray(),
            'chapters' => array_map(static fn (ChapterDraft $c): array => $c->toArray(), $chapters),
            'fact_checks' => array_map(static fn (FactCheckResult $r): array => $r->toArray(), $factReports),
            'validation' => $report->toArray(),
            'artifacts' => $paths,
            'events' => $events,
            'elapsed_seconds' => $elapsed,
        ];
    }

    /**
     * Writer -> FactChecker -> revise, bounded by $maxRevisions.
     *
     * @param  array<int, FactCheckResult>  $factReports
     */
    private function writeAndVerify(
        BookBrief $brief,
        ChapterOutline $chapterOutline,
        ResearchPackage $package,
        int $maxRevisions,
        callable $emit,
        array &$factReports,
    ): ?ChapterDraft {
        $number = $chapterOutline->chapterNumber;

        $draft = null;
        $factCheck = null;
        $feedback = '';
        $body = '';
        $extraQueries = [];

        for ($attempt = 0; $attempt <= $maxRevisions; $attempt++) {
            $isRevision = $attempt > 0;

            $emit('writer', "[Writer] Chapter {$number}".($isRevision ? " revision {$attempt}" : '').' started', [
                'chapter' => $number,
                'attempt' => $attempt,
            ]);

            try {
                [$candidate, $stripped] = $this->writer->write(
                    $brief,
                    $chapterOutline,
                    $package,
                    $feedback,
                    $body
                );
            } catch (\Throwable $e) {
                $emit('error', "[Writer] Chapter {$number} failed: ".$e->getMessage(), [
                    'chapter' => $number,
                    'attempt' => $attempt,
                ]);

                return null;
            }

            if ($stripped !== []) {
                $emit('warning', "[Writer] Chapter {$number}: removed unverifiable citation(s) "
                    .implode(', ', array_map(static fn (int $c): string => "[{$c}]", $stripped)), [
                        'chapter' => $number,
                    ]);
            }

            $draft = $candidate;
            $body = $candidate->body;

            $emit('writer', "[Writer] Chapter {$number} completed", [
                'chapter' => $number,
                'words' => $draft->wordCount(),
                'citations' => count($draft->citationsUsed()),
            ]);

            // ---- Fact Checker ----
            $emit('factchecker', "[FactChecker] Chapter {$number} checking", ['chapter' => $number]);

            try {
                $factCheck = $this->factChecker->check($draft, $package);
            } catch (\Throwable $e) {
                $emit('warning', "[FactChecker] Chapter {$number} could not run: ".$e->getMessage(), [
                    'chapter' => $number,
                ]);
                break;
            }

            $factReports[$number] = $factCheck;

            $emit('factchecker', sprintf(
                '[FactChecker] %d citations checked for chapter %d: %d passed, %d failed, %d uncertain',
                $factCheck->total(),
                $number,
                $factCheck->passedCount(),
                $factCheck->failedCount(),
                $factCheck->uncertainCount()
            ), ['chapter' => $number]);

            // Two independent reasons to revise: factual problems, or a defect the
            // final validator will reject. Both are worth a retry, because
            // otherwise the loop converges on a chapter that can never ship.
            $defects = array_merge(
                $this->lengthProblem($brief, $draft),
                $this->structureProblems($draft),
            );

            if (! $factCheck->hasFailures() && $defects === []) {
                return $draft;
            }

            if ($attempt === $maxRevisions) {
                break;
            }

            $reasons = [];

            if ($factCheck->hasFailures()) {
                $reasons[] = 'Fact checking failed:';
                $reasons[] = $factCheck->toRevisionFeedback();
            }

            if ($defects !== []) {
                $reasons[] = 'Formatting requirements not met:';
                $reasons[] = implode("\n", array_map(
                    static fn (string $d): string => '- '.$d,
                    $defects
                ));
            }

            $feedback = implode("\n\n", $reasons);

            $emit('revision', "[Writer] Revision ".($attempt + 1)." required for chapter {$number}: "
                .($factCheck->hasFailures()
                    ? $factCheck->failedCount().' citation failure(s)'
                    : implode(' ', $defects)), [
                'chapter' => $number,
                'failures' => $factCheck->failedCount(),
                'defects' => $defects,
            ]);

            // If the failures suggest missing evidence rather than bad phrasing,
            // widen the research before rewriting.
            if ($this->needsMoreResearch($factCheck)) {
                $extraQueries = $this->followUpQueries($factCheck);
                if ($extraQueries !== []) {
                    $emit('researcher', "[Researcher] Chapter {$number} supplementary research", [
                        'chapter' => $number,
                        'queries' => $extraQueries,
                    ]);
                    try {
                        $extra = $this->researcher->research($brief, $chapterOutline, $extraQueries);
                        $package = $this->mergePackages($package, $extra);
                    } catch (\Throwable $e) {
                        $emit('warning', "[Researcher] supplementary pass failed: ".$e->getMessage(), [
                            'chapter' => $number,
                        ]);
                    }
                }
            }
        }

        if ($factCheck !== null && $factCheck->hasFailures()) {
            $emit('warning', sprintf(
                '[FactChecker] Chapter %d still has %d unresolved citation(s) after %d revision(s). '
                .'Moving on with the issues recorded rather than looping.',
                $number,
                $factCheck->failedCount(),
                $maxRevisions
            ), ['chapter' => $number]);
        }

        return $draft;
    }

    /**
     * Re-run the Fact Checker on an edited draft.
     *
     * Returns null when the check itself could not run. The caller must treat
     * that as a reason to keep the pre-edit draft: an edit we cannot verify is
     * an edit we do not ship.
     */
    private function recheckEditedDraft(
        ChapterDraft $edited,
        ResearchPackage $package,
        int $number,
        callable $emit,
    ): ?FactCheckResult {
        try {
            $result = $this->factChecker->check($edited, $package);

            $emit('factchecker', sprintf(
                '[FactChecker] Chapter %d re-checked after editing: %d passed, %d failed, %d uncertain',
                $number,
                $result->passedCount(),
                $result->failedCount(),
                $result->uncertainCount()
            ), ['chapter' => $number, 'phase' => 'post-edit']);

            return $result;
        } catch (\Throwable $e) {
            $emit('warning', "[FactChecker] Chapter {$number} could not re-verify the edit: ".$e->getMessage(), [
                'chapter' => $number,
            ]);

            return null;
        }
    }

    private function lengthProblem(BookBrief $brief, ChapterDraft $draft): array
    {
        $words = $draft->wordCount();
        $problems = [];

        if ($words < $brief->minWordsPerChapter) {
            $problems[] = sprintf(
                'The chapter is %d words but must be at least %d. Expand it with more explanation and examples drawn from the provided sources.',
                $words,
                $brief->minWordsPerChapter
            );
        }

        if ($words > $brief->maxWordsPerChapter) {
            $problems[] = sprintf(
                'The chapter is %d words but must be at most %d. Tighten the prose and cut the least useful passages.',
                $words,
                $brief->maxWordsPerChapter
            );
        }

        return $problems;
    }

    /**
     * Everything an edit must not break: the structural rules, plus the set of
     * citations. Fact checking has already run, so losing a marker means a
     * verified claim silently became uncited.
     *
     * @return array<int, string>
     */
    private function editRegressions(ChapterDraft $before, ChapterDraft $after): array
    {
        $regressions = $this->structureProblems($after);

        $dropped = array_values(array_diff($before->citationsUsed(), $after->citationsUsed()));

        if ($dropped !== []) {
            $regressions[] = sprintf(
                'it dropped citation(s) [%s]',
                implode(', ', $dropped)
            );
        }

        return $regressions;
    }

    /**
     * Structural rules the Final Validator will enforce. If these are not
     * revision triggers, the loop can converge on a chapter that can never ship.
     *
     * @return array<int, string>
     */
    private function structureProblems(ChapterDraft $draft): array
    {
        $problems = [];
        $body = $draft->body;
        $number = $draft->chapterNumber;

        $takeaways = preg_match_all('/^\s*Takeaway:/mi', $body);

        if ($takeaways === 0) {
            $problems[] = 'The chapter has no line beginning with "Takeaway:". End the chapter with exactly one Takeaway line containing a single encouraging sentence.';
        } elseif ($takeaways > 1) {
            $problems[] = sprintf('The chapter has %d Takeaway lines. Keep exactly one, as the final line.', $takeaways);
        } elseif (preg_match('/^\s*Takeaway:\s*\S+/mi', $body) !== 1) {
            $problems[] = 'The Takeaway line is empty. Write one encouraging sentence after the word "Takeaway:".';
        } else {
            // The Takeaway must be the final line: nothing may follow it.
            $lines = preg_split('/\R/', $body) ?: [];
            $index = null;

            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*Takeaway:/i', $line) === 1) {
                    $index = $i;
                    break;
                }
            }

            $after = $index === null ? [] : array_slice($lines, $index + 1);
            $after = trim(implode('', $after));

            if ($after !== '') {
                $problems[] = 'There is text after the Takeaway line. The Takeaway must be the last line of the chapter.';
            }
        }

        if ($draft->citationsUsed() === []) {
            $problems[] = 'The chapter contains no citations at all. Every factual claim needs a numbered citation such as [1].';
        }

        return $problems;
    }

/**
 * Phrasings the Fact Checker uses when a source simply does not carry the
 * evidence, as opposed to the writer phrasing a claim badly. These trigger a
 * supplementary research pass instead of a blind rewrite.
 *
 * Matching on prose is inherently fragile, so this list covers the reason
 * templates in FactCheckerAgent rather than one exact string.
 */
private const EVIDENCE_GAP_PHRASES = [
    'does not mention',
    'does not contain',
    'does not state',
    'no evidence',
    'no page text',
    'could not be read',
    'could not be verified',
    'not settle',
    'cannot be confirmed',
    'no information',
    'unrelated',
];

/**
 * A citation that failed because the source could not back it may be fixed
 * with better evidence, but one that failed on wording does not need more.
 */
private function needsMoreResearch(FactCheckResult $result): bool
{
    foreach ($result->failures() as $failure) {
        $reason = mb_strtolower($failure->reason);

        foreach (self::EVIDENCE_GAP_PHRASES as $phrase) {
            if (str_contains($reason, $phrase)) {
                return true;
            }
        }
    }

    return false;
}

    /** @return array<int, string> */
    private function followUpQueries(FactCheckResult $result): array
    {
        $queries = [];

        foreach ($result->failures() as $failure) {
            $query = $this->keywords($failure->claim);
            if ($query !== '') {
                $queries[] = $query;
            }
        }

        return array_slice(array_values(array_unique($queries)), 0, 3);
    }

    private function keywords(string $claim): string
    {
        $stop = ['the', 'and', 'for', 'that', 'this', 'with', 'from', 'was', 'were', 'are', 'has', 'have', 'its'];

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($claim)) ?: [];
        $kept = array_values(array_filter(
            array_slice($words, 0, 8),
            static fn (string $w): bool => $w !== '' && ! in_array($w, $stop, true) && mb_strlen($w) > 2
        ));

        return implode(' ', $kept);
    }

    /**
     * Append supplementary sources, renumbering so citations stay dense and
     * sequential. Existing sources keep their numbers because the Writer may
     * already have referenced them in the draft being revised.
     */
    private function mergePackages(ResearchPackage $base, ResearchPackage $extra): ResearchPackage
    {
        $known = array_map(static fn ($s): string => $s->url, $base->sources);
        $maxId = (int) max(array_merge([0], $base->validCitationIds()));

        $merged = $base->sources;

        foreach ($extra->sources as $source) {
            if (in_array($source->url, $known, true)) {
                continue;
            }

            $merged[] = new SourceItem(
                $maxId + 1,
                $source->organization,
                $source->title,
                $source->url,
                $source->claimsSupported,
                $source->retrievedContent,
                $source->verified,
                $source->verificationNote,
                $source->httpStatus,
            );

            $maxId++;
        }

        return new ResearchPackage(
            $base->chapterNumber,
            $base->chapterTitle,
            $merged,
            array_merge($base->notes, ['Supplementary sources were added on a revision pass.'])
        );
    }

    /**
     * Turn an early failure into a structured, presentable result.
     *
     * @param  callable  $emit
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function abort(callable $emit, \Throwable $e, string $agent, float $started, array $events): array
    {
        $emit('error', "[{$agent}] failed: ".$e->getMessage(), [
            'exception' => $e::class,
            'context' => $e instanceof AgentException ? $e->context() : [],
        ]);

        $report = new ValidationReport(false, [], ["The {$agent} failed: ".$e->getMessage()], []);

        return [
            'success' => false,
            'failed_agent' => $agent,
            'error' => $e->getMessage(),
            'validation' => $report->toArray(),
            'events' => $events,
            'elapsed_seconds' => round(microtime(true) - $started, 1),
        ];
    }
}