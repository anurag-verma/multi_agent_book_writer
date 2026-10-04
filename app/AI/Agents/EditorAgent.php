<?php

namespace App\AI\Agents;

use App\AI\DTO\BookBrief;
use App\AI\DTO\ChapterDraft;
use App\AI\DTO\FactCheckResult;
use App\AI\DTO\ResearchPackage;
use App\AI\Helpers\TextUtils;
use App\AI\Interfaces\LLMInterface;
use App\AI\Services\CitationService;
use Illuminate\Support\Facades\Log;

/**
 * Agent 5 of 5.
 *
 * Improves the prose of a chapter that has already passed fact checking. The
 * editor is deliberately constrained: it may reword, reorder and tighten, but it
 * may not touch a figure, drop a citation, or add a source. Everything the Editor
 * produces is re-validated against the same research package afterwards, so a
 * citation it loses is caught rather than shipped.
 */
class EditorAgent
{
    public function __construct(
        private readonly LLMInterface $llm,
        private readonly CitationService $citations,
    ) {}

    public function edit(BookBrief $brief, ChapterDraft $draft, ResearchPackage $package, FactCheckResult $factCheck): ChapterDraft
    {
        Log::info('[Editor] Chapter '.$draft->chapterNumber.' started', [
            'words' => $draft->wordCount(),
        ]);

        $system = <<<'TXT'
        You are the Editor of a practical book for first-time small-business owners in India.

        You improve an already fact-checked chapter. You are polishing, not rewriting.

        You MAY:
        - fix grammar, spelling and punctuation
        - improve readability, flow and sentence rhythm
        - remove repetition and unnecessary jargon
        - simplify a phrase while keeping its meaning identical

        You MUST NOT:
        - change, add or remove any figure, date, percentage or named policy
        - remove, renumber or add any citation marker such as [1]
        - add any new fact or new source
        - introduce bullet points or headings inside the body
        - change the meaning of any factual statement

        Keep the same warm, encouraging mentor voice. Keep the length within roughly
        5 percent of the original.

        Return the complete chapter body as plain prose, with the same citation
        markers in the same sentences.

        Copy the final "Takeaway:" line across verbatim from the chapter you were
        given. Do not reword it, do not move it, and never omit it -- an edit that
        loses the Takeaway will be discarded.
        TXT;

        $user = <<<TXT
        Book: {$brief->title}
        Audience: {$brief->audience}
        Tone: {$brief->tone}

        Chapter {$draft->chapterNumber}: {$draft->title}

        --- BEGIN CHAPTER ---
        {$draft->body}
        --- END CHAPTER ---

        Return only the edited chapter body.
        TXT;

        $edited = '';

        try {
            $data = $this->llm->sendJson(
                $system,
                $user,
                [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'body'],
                    'additionalProperties' => false,
                ],
                ['temperature' => 0.4, 'max_tokens' => 4000]
            );

            $edited = TextUtils::normalizeLineEndings((string) ($data['body'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('[Editor] structured edit failed, falling back to prose', ['error' => $e->getMessage()]);
        }

        if (trim($edited) === '') {
            $edited = $this->editAsProse($system, $user, $draft->body);
        }

        if (trim($edited) === '') {
            Log::warning('[Editor] no edit produced, keeping fact-checked draft', [
                'chapter' => $draft->chapterNumber,
            ]);

            return $draft;
        }

        $edited = $this->tidy($edited);

        // Compare citation sets *before* enforcement renumbers them. Comparing
        // against the enforced draft would mix old and new ids together and name
        // the wrong citation.
        $editedMarkers = TextUtils::uniqueCitations($edited);

        // Re-run enforcement. If editing lost or mangled a citation, the markers
        // that no longer resolve are removed and reported rather than shipped.
        [$enforced, $stripped] = $this->citations->enforce(
            new ChapterDraft($draft->chapterNumber, $draft->title, $edited, $draft->sources),
            $package
        );

        $warnings = $enforced->warnings;

        $lost = array_values(array_diff($draft->citationsUsed(), $editedMarkers));
        foreach ($lost as $citation) {
            $source = $draft->source($citation);

            $warnings[] = sprintf(
                'Editor dropped citation [%d]%s. The claim it supported is now uncited, and the source is no longer listed.',
                $citation,
                $source !== null ? ' ('.$source->organization.')' : ''
            );
        }

        $result = new ChapterDraft(
            $draft->chapterNumber,
            $draft->title,
            $enforced->body,
            $enforced->sources,
            $warnings
        );

        Log::info('[Editor] Chapter '.$draft->chapterNumber.' completed', [
            'words_before' => $draft->wordCount(),
            'words_after' => $result->wordCount(),
            'citations' => count($result->citationsUsed()),
        ]);

        return $result;
    }

    private function editAsProse(string $system, string $user, string $body): string
    {
        try {
            return TextUtils::normalizeLineEndings(
                $this->llm->sendPrompt($system, $user, ['temperature' => 0.4, 'max_tokens' => 4000])
            );
        } catch (\Throwable $e) {
            Log::error('[Editor] prose edit failed', ['error' => $e->getMessage()]);

            return $body;
        }
    }

    private function tidy(string $body): string
    {
        $body = preg_replace('/^```.*$/m', '', $body) ?? $body;
        $body = preg_replace('/\R{3,}/', "\n\n", $body) ?? $body;

        // Keep only the final Takeaway line if the editor duplicated it.
        if (preg_match_all('/^\s*Takeaway:.*$/mi', $body, $m) > 1) {
            $last = end($m[0]);
            $body = preg_replace('/^\s*Takeaway:.*$/mi', '', $body) ?? $body;
            $body = rtrim($body)."\n\n".trim($last);
        }

        // The model sometimes narrates its own behaviour at the end.
        $body = preg_replace('/\n\s*(?:Here is|Here\'s|I have|Note that)\b.*$/is', '', $body) ?? $body;

        return trim($body);
    }
}