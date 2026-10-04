<?php

namespace App\AI\Agents;

use App\AI\DTO\BookBrief;
use App\AI\DTO\ChapterDraft;
use App\AI\DTO\ChapterOutline;
use App\AI\DTO\ResearchPackage;
use App\AI\Helpers\TextUtils;
use App\AI\Interfaces\LLMInterface;
use App\AI\Services\CitationService;
use Illuminate\Support\Facades\Log;

/**
 * Agent 3 of 5.
 *
 * Writes prose using only the sources in the ResearchPackage. The Writer is
 * trusted for tone and structure but not for citations: whatever it emits is run
 * through CitationService::enforce(), which deletes any [n] that does not resolve
 * to a verified source. That is why this agent cannot fabricate a reference.
 */
class WriterAgent
{
    public function __construct(
        private readonly LLMInterface $llm,
        private readonly CitationService $citations,
    ) {}

    /**
     * @return array{0: ChapterDraft, 1: array<int, int>} [draft, strippedCitationNumbers]
     */
    public function write(
        BookBrief $brief,
        ChapterOutline $chapter,
        ResearchPackage $package,
        string $feedback = '',
        string $previousBody = '',
    ): array {
        $revision = $feedback !== '' || $previousBody !== '';

        Log::info('[Writer] Chapter '.$chapter->chapterNumber.($revision ? ' revision' : '').' started', [
            'sources' => count($package->sources),
            'has_feedback' => $feedback !== '',
        ]);

        $system = $this->systemPrompt();

        $user = $this->userPrompt($brief, $chapter, $package);

        if ($revision) {
            $user .= "\n\n=== REVISION REQUIRED ===\n"
                ."Your previous draft did not pass verification. Fix it.\n\n"
                ."Do not restate any claim listed below. You have two options for each "
                ."one, and only these two:\n"
                ."  1. Attribute it to a different source from the list above that actually "
                ."states it, using that source's number.\n"
                ."  2. Delete the claim entirely.\n"
                ."Rewording the same unsupported figure does not fix it, and repeating it "
                ."verbatim will fail again.\n\n"
                .'Problems found by fact checking:'.PHP_EOL.$feedback.PHP_EOL;

            if ($previousBody !== '') {
                $user .= "\n\nYour previous draft:".PHP_EOL.$previousBody.PHP_EOL;
                $user .= "\nRewrite it, correcting every problem above. Keep what was accurate.";
                $user .= "\nThe word count and the single closing Takeaway line are hard requirements;";
                $user .= " check both before returning.";
            }
        }

        $data = $this->llm->sendJson(
            $system,
            $user,
            $this->schema($brief),
            ['temperature' => 0.7, 'max_tokens' => 4000]
        );

        $body = TextUtils::normalizeLineEndings((string) ($data['body'] ?? ''));
        $title = trim((string) ($data['title'] ?? '')) ?: $chapter->title;

        if (trim($body) === '') {
            $body = $this->retryAsPlainText($system, $user);
        }

        // Strip anything the model added that does not belong in the body.
        $body = $this->cleanBody($body);

        $draft = new ChapterDraft($chapter->chapterNumber, $title, $body, []);

        // The guarantee: illegal citation markers cannot survive this call.
        [$enforced, $stripped] = $this->citations->enforce($draft, $package);

        Log::info('[Writer] Chapter '.$chapter->chapterNumber.' completed', [
            'words' => $enforced->wordCount(),
            'citations_used' => count($enforced->citationsUsed()),
            'stripped' => $stripped,
        ]);

        return [$enforced, $stripped];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You are the Writer in a multi-agent book pipeline. You write one chapter of a
        practical book for first-time small-business owners in India.

        You are given a list of VERIFIED sources. Every source is numbered, for
        example [1], [2]. Those numbers are the only citations that exist.

        Hard rules:
        - Cite with square brackets immediately after the statement they support, e.g.
          "UPI processed 16.58 billion transactions in October 2024 [3]."
        - Use ONLY the numbered sources provided. Never invent a citation number.
        - Never state a figure, date, percentage or named policy unless a provided
          source supports it. If the sources do not cover something, write around it.
        - Attach a citation only to claims the source actually states. A sentence is
          checked claim by claim, so if it mixes a sourced fact with your own
          inference, judgement or business advice, the inference will fail.
          Write the sourced part as its own sentence with the marker, and put your
          commentary in a separate sentence with no citation.
        - Prefer plain, sourced description over confident generalisation. "UPI lets a
          customer pay by scanning a QR code [1]." passes; "This caters to a diverse
          group of customers [1]." does not, because the source never says that.
        - Do not write a references section. It is generated separately.
        - Write flowing prose only. No bullet points, no numbered lists, no headings
          inside the body.
        - Explain every technical term the first time it appears, in plain words.
        - Keep the same warm, encouraging mentor voice throughout.
        - Do not use markdown. Return plain paragraphs.

        Structure of what you return:
        - Write about 700-800 words of prose, so the finished chapter lands between
          600 and 900 words.
        - End with exactly one line starting with "Takeaway:" followed by a single
          encouraging sentence for the reader. Do not add anything after that line.
        TXT;
    }

    private function userPrompt(BookBrief $brief, ChapterOutline $chapter, ResearchPackage $package): string
    {
        return <<<TXT
        {$brief->toPrompt()}

        === THIS CHAPTER ===
        Number: {$chapter->chapterNumber}
        Working title: {$chapter->title}
        Purpose: {$chapter->purpose}

        Cover these topics:
        - {$this->bullets($chapter->keyTopics)}

        The reader's questions this chapter must answer:
        - {$this->bullets($chapter->importantQuestions)}

        === VERIFIED SOURCES (the only citations that exist) ===
        {$package->toPrompt()}

        Write the chapter now. Target 700-800 words. Cite with the bracketed numbers above.
        TXT;
    }

    /** @return array<string, mixed> */
    private function schema(BookBrief $brief): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
            ],
            'required' => ['title', 'body'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Fallback when structured output produced nothing usable.
     */
    private function retryAsPlainText(string $system, string $user): string
    {
        Log::warning('[Writer] empty body, retrying as plain text');

        try {
            return $this->llm->sendPrompt($system, $user, [
                'temperature' => 0.7,
                'max_tokens' => 4000,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Writer] plain text retry failed', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Remove structural debris the model sometimes adds: markdown headings, code
     * fences, an unwanted references block, and any "Takeaway" duplicates.
     */
    private function cleanBody(string $body): string
    {
        $body = preg_replace('/^```.*$/m', '', $body) ?? $body;
        $body = preg_replace('/\R{3,}/', "\n\n", $body) ?? $body;

        // The reference list is built from verified sources, so any version the
        // model produced is discarded outright.
        $body = preg_replace('/\n\s*(?:#{1,6}\s*)?(?:References|Sources|Bibliography)\s*:?\s*\n.*$/is', '', $body) ?? $body;

        // Keep only the final Takeaway line.
        if (preg_match_all('/^\s*Takeaway:.*$/mi', $body, $m) > 1) {
            $last = end($m[0]);
            $body = preg_replace('/^\s*Takeaway:.*$/mi', '', $body) ?? $body;
            $body = rtrim($body)."\n\n".trim($last);
        }

        return trim($body);
    }

    /** @param array<int, string> $items */
    private function bullets(array $items): string
    {
        return implode("\n- ", $items);
    }
}