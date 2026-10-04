<?php

namespace App\AI\Services;

use App\AI\DTO\ChapterDraft;
use App\AI\DTO\ResearchPackage;
use App\AI\DTO\SourceItem;
use App\AI\Helpers\TextUtils;
use Illuminate\Support\Facades\Log;

/**
 * The enforcement layer between the Writer and the book.
 *
 * The single most important guarantee in this project lives here: a citation
 * marker only survives if it resolves to a SourceItem in the ResearchPackage.
 * Asking the model in a prompt to "not invent citations" is a request; this is
 * a constraint. Any [n] the Writer emits that the Researcher never verified is
 * deleted from the prose and reported as a warning.
 */
class CitationService
{
    /**
     * Remove illegal citation markers and align the reference list with what the
     * prose actually cites.
     *
     * @return array{0: ChapterDraft, 1: array<int, int>} [draft, strippedCitationNumbers]
     */
    public function enforce(ChapterDraft $draft, ResearchPackage $package): array
    {
        $legalIds = $package->validCitationIds();
        $used = $draft->citationsUsed();

        $illegal = array_values(array_diff($used, $legalIds));

        $body = $draft->body;

        if ($illegal !== []) {
            foreach ($illegal as $citation) {
                $body = str_replace(
                    ['['.$citation.']', ' ['.$citation.']'],
                    '',
                    $body
                );
            }

            Log::warning('[Citations] stripped unverifiable markers', [
                'chapter' => $draft->chapterNumber,
                'stripped' => $illegal,
            ]);

            $body = $this->tidyPunctuation($body);
        }

        $finalUsed = TextUtils::uniqueCitations($body);

        // Reference list contains exactly the sources still cited, in order.
        $sources = array_values(array_filter(
            $package->sources,
            static fn (SourceItem $s): bool => in_array($s->citationId, $finalUsed, true)
        ));

        // Renumber densely by order of first appearance. The package numbers
        // every source it verified, so a chapter that cites only some of them
        // would otherwise ship a reference list like [1], [2], [6].
        [$body, $sources] = $this->renumber($body, $sources);

        $warnings = $draft->warnings;

        foreach ($illegal as $citation) {
            $warnings[] = sprintf(
                'Citation [%d] was removed: no verified source with that number existed in the research package.',
                $citation
            );
        }

        $unused = array_values(array_diff($package->validCitationIds(), $finalUsed));
        foreach ($unused as $citation) {
            $warnings[] = sprintf('Source [%d] was researched but not cited, so it is omitted from the references.', $citation);
        }

        // Report the old -> new mapping so the change is visible rather than silent.
        foreach ($this->lastRemap as $old => $new) {
            if ($old !== $new) {
                $warnings[] = sprintf(
                    'Reference numbering was made sequential: [%d] is now cited as [%d].',
                    $old,
                    $new
                );
            }
        }

        $this->lastRemap = [];

        return [
            new ChapterDraft(
                $draft->chapterNumber,
                $draft->title,
                $body,
                $sources,
                $warnings,
            ),
            $illegal,
        ];
    }

    /** @var array<int, int> */
    private array $lastRemap = [];

    /**
     * Renumber citation markers densely from 1 in order of first appearance.
     *
     * Substitution happens in a single pass. Rewriting one marker at a time
     * would corrupt the text whenever an old number is also a valid new
     * number, which is the common case: [1] -> [2] while [2] -> [3].
     *
     * @param  array<int, SourceItem>  $sources
     * @return array{0: string, 1: array<int, SourceItem>}
     */
    private function renumber(string $body, array $sources): array
    {
        preg_match_all('/\[(\d+)\]/', $body, $matches);

        $order = [];
        foreach ($matches[1] as $raw) {
            $number = (int) $raw;
            if (! in_array($number, $order, true)) {
                $order[] = $number;
            }
        }

        $map = [];
        foreach ($order as $index => $old) {
            $map[$old] = $index + 1;
        }

        $this->lastRemap = $map;

        // Nothing to do when the markers are already 1..n in order of appearance.
        $alreadySequential = $order === [] || $order === range(1, count($order));

        if ($alreadySequential) {
            return [$body, $sources];
        }

        $renumbered = preg_replace_callback(
            '/\[(\d+)\]/',
            static fn (array $m): string => '['.($map[(int) $m[1]] ?? 0).']',
            $body
        ) ?? $body;

        $byOld = [];
        foreach ($sources as $source) {
            $byOld[$source->citationId] = $source;
        }

        $newSources = [];

        foreach ($order as $old) {
            if (! isset($byOld[$old])) {
                continue;
            }

            $source = $byOld[$old];

            $newSources[] = new SourceItem(
                $map[$old],
                $source->organization,
                $source->title,
                $source->url,
                $source->claimsSupported,
                $source->retrievedContent,
                $source->verified,
                $source->verificationNote,
                $source->httpStatus,
            );
        }

        return [$renumbered, $newSources];
    }

    /**
     * Map each citation number to the sentences that carry it.
     *
     * This is what the Fact Checker treats as "the claim attached to [n]". Using
     * the sentence rather than the whole chapter keeps the judgement focused.
     *
     * @return array<int, array<int, string>>
     */
    public function claimMap(ChapterDraft $draft): array
    {
        $prose = $draft->proseOnly();
        $sentences = TextUtils::sentences($prose);

        $map = [];

        foreach ($sentences as $sentence) {
            foreach (TextUtils::extractCitations($sentence) as $citation) {
                $map[$citation][] = trim($sentence);
            }
        }

        // A citation can survive in text that sentence splitting did not isolate
        // (abbreviations, for example). Fall back to a line-level scan.
        foreach ($draft->citationsUsed() as $citation) {
            if (isset($map[$citation]) && $map[$citation] !== []) {
                continue;
            }

            foreach (preg_split('/\R/u', $prose) ?: [] as $line) {
                if (str_contains($line, '['.$citation.']')) {
                    $map[$citation][] = trim($line);
                }
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * Flatten a claim list into a single reviewable statement.
     *
     * @param  array<int, string>  $claims
     */
    public function mergeClaims(array $claims): string
    {
        $unique = array_values(array_unique(array_filter(array_map(
            'trim',
            $claims
        ))));

        return implode(' ', $unique);
    }

    /**
     * Deterministic reference/citation consistency checks.
     *
     * @return array<int, string> Error strings; empty means consistent.
     */
    public function validate(ChapterDraft $draft): array
    {
        $errors = [];

        $used = $draft->citationsUsed();
        $referenced = array_map(
            static fn (SourceItem $s): int => $s->citationId,
            $draft->sources
        );

        $missing = array_values(array_diff($used, $referenced));
        foreach ($missing as $citation) {
            $errors[] = sprintf('Citation [%d] is used in the text but missing from the reference list.', $citation);
        }

        $orphaned = array_values(array_diff($referenced, $used));
        foreach ($orphaned as $citation) {
            $errors[] = sprintf('Reference [%d] is listed but never cited in the text.', $citation);
        }

        foreach ($draft->sources as $source) {
            if (! TextUtils::isValidUrl($source->url)) {
                $errors[] = sprintf('Reference [%d] has a missing or invalid URL (%s).', $source->citationId, $source->url);
            }
        }

        // Markers must be sequential starting at 1. Skipped when there are no
        // references at all, because "no citations" is already reported and an
        // empty list adds noise rather than information.
        if ($referenced !== []) {
            $expected = range(1, count($referenced));
            sort($referenced);
            if ($referenced !== $expected) {
                $errors[] = sprintf(
                    'Reference numbering is not sequential from 1. Found: [%s].',
                    implode(', ', $referenced)
                );
            }
        }

        return $errors;
    }

    /**
     * Clean up the punctuation left behind by a removed marker.
     */
    private function tidyPunctuation(string $body): string
    {
        // "( )" or "[ ]" left empty by removal.
        $body = preg_replace('/\[\s*\]/', '', $body) ?? $body;
        $body = preg_replace('/\(\s*\)/', '', $body) ?? $body;

        // " ," and " ;" and " ." and doubled spaces.
        $body = preg_replace('/\s+([,;.])/u', '$1', $body) ?? $body;
        $body = preg_replace('/[ \t]{2,}/u', ' ', $body) ?? $body;
        $body = preg_replace('/ +\n/u', "\n", $body) ?? $body;

        return trim($body);
    }
}