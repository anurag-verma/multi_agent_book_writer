<?php

namespace App\AI\Agents;

use App\AI\DTO\ChapterDraft;
use App\AI\DTO\FactCheckItem;
use App\AI\DTO\FactCheckResult;
use App\AI\DTO\ResearchPackage;
use App\AI\Helpers\TextUtils;
use App\AI\Interfaces\LLMInterface;
use App\AI\Services\CitationService;
use App\AI\Services\SourceVerifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Agent 4 of 5, and the most important one.
 *
 * Each citation is checked in two layers.
 *
 * Layer 1, deterministic and free: does the marker resolve to a verified source,
 * does the URL still resolve, does the page still contain real content, is the
 * citation actually attached to a sentence. These checks are code, not opinion,
 * so they never produce a false PASS.
 *
 * Layer 2, the LLM: does the retrieved page text actually support the specific
 * sentence the marker is attached to. This is the one question code cannot
 * answer, so it is the only place a model is consulted.
 *
 * Splitting the two matters. Asking a model "does this URL exist?" wastes tokens
 * and gets confident wrong answers. Asking code "does this sentence support this
 * claim?" is not possible at all.
 */
class FactCheckerAgent
{
    public function __construct(
        private readonly LLMInterface $llm,
        private readonly CitationService $citations,
        private readonly SourceVerifier $verifier,
    ) {}

    public function check(ChapterDraft $draft, ResearchPackage $package): FactCheckResult
    {
        $claimMap = $this->citations->claimMap($draft);

        Log::info('[FactChecker] Chapter '.$draft->chapterNumber.' started', [
            'citations' => count($claimMap),
        ]);

        if ($claimMap === []) {
            Log::warning('[FactChecker] chapter has no citations at all', [
                'chapter' => $draft->chapterNumber,
            ]);

            // An empty result would report zero failures and let the chapter
            // pass, which is the opposite of what an uncited chapter deserves.
            // Citation 0 is a synthetic item that blocks the chapter.
            return new FactCheckResult($draft->chapterNumber, [
                new FactCheckItem(
                    citation: 0,
                    claim: Str::limit(TextUtils::sentences($draft->proseOnly())[0] ?? $draft->title, 300),
                    sourceUrl: '',
                    sourceOrganization: '',
                    status: FactCheckItem::FAIL,
                    reason: 'The chapter contains no citations. Every factual claim must carry a numbered citation.',
                    checkType: 'existence',
                ),
            ]);
        }

        // Layer 1: deterministic checks.
        $deterministic = [];
        foreach ($claimMap as $citation => $claims) {
            $source = $draft->source($citation) ?? $package->source($citation);

            if ($source === null) {
                $deterministic[] = new FactCheckItem(
                    citation: $citation,
                    claim: $this->citations->mergeClaims($claims),
                    sourceUrl: '',
                    sourceOrganization: '',
                    status: FactCheckItem::FAIL,
                    reason: 'Citation has no matching verified source in the research package.',
                    checkType: 'existence',
                );

                continue;
            }

            $deterministic[] = $this->checkSourceHealth($citation, $claims, $source->url, $source->organization, $source->retrievedContent);
        }

        // Layer 2: semantic support, only for citations that survived layer 1.
        $semantic = $this->checkSupport($draft, $package, $claimMap);

        $items = array_merge($deterministic, $semantic);

        // One verdict per citation: a deterministic FAIL wins over an LLM PASS.
        $merged = $this->mergeVerdicts($items);

        // A citation only earns PASS when the semantic layer actually ruled on
        // it. Anything still resting on its existence check was never judged for
        // support, and reporting that as PASS would be a silent pass -- exactly
        // the failure this pipeline exists to prevent. Found in a live run, where
        // citations came back PASS with the reason "Pending semantic support
        // check" because the model omitted them from its verdict list.
        $judged = array_map(static fn (FactCheckItem $i): int => $i->citation, $semantic);

        foreach ($merged as $citation => $item) {
            if ($item->status !== FactCheckItem::PASS || in_array($citation, $judged, true)) {
                continue;
            }

            $merged[$citation] = new FactCheckItem(
                $item->citation,
                $item->claim,
                $item->sourceUrl,
                $item->sourceOrganization,
                FactCheckItem::UNCERTAIN,
                'The source URL is reachable, but support for this specific claim was never verified.',
                'support'
            );
        }

        $result = new FactCheckResult($draft->chapterNumber, array_values($merged));

        Log::info('[FactChecker] citations checked', [
            'chapter' => $draft->chapterNumber,
            'checked' => $result->total(),
            'passed' => $result->passedCount(),
            'failed' => $result->failedCount(),
            'uncertain' => $result->uncertainCount(),
        ]);

        return $result;
    }

    /**
     * Deterministic: is the source real and readable right now?
     *
     * @param  array<int, string>  $claims
     */
    private function checkSourceHealth(
        int $citation,
        array $claims,
        string $url,
        string $organization,
        string $providedContent,
    ): FactCheckItem {
        $claim = $this->citations->mergeClaims($claims);

        if (! TextUtils::isValidUrl($url)) {
            return new FactCheckItem(
                $citation, $claim, $url, $organization, FactCheckItem::FAIL,
                'The citation has no valid source URL.', 'existence'
            );
        }

        // Content captured at research time is trusted for support checks but we
        // still confirm the URL resolves, so a source that died after research is
        // caught.
        $verification = $this->verifier->verify($url, $providedContent);

        if (! $verification['verified']) {
            return new FactCheckItem(
                $citation, $claim, $url, $organization, FactCheckItem::FAIL,
                'Source could not be verified: '.$verification['note'], 'existence'
            );
        }

        if (trim($providedContent) === '') {
            return new FactCheckItem(
                $citation, $claim, $url, $organization, FactCheckItem::UNCERTAIN,
                'The URL is reachable but no page text could be read, so the claim cannot be '
                .'confirmed against the source directly.', 'existence'
            );
        }

        return new FactCheckItem(
            $citation, $claim, $url, $organization, FactCheckItem::PASS,
            'Source URL is reachable and returned readable content. Pending semantic support check.',
            'existence'
        );
    }

    /**
     * LLM: does the source text support the cited sentence?
     *
     * @param  array<int, array<int, string>>  $claimMap
     * @return array<int, FactCheckItem>
     */
    private function checkSupport(ChapterDraft $draft, ResearchPackage $package, array $claimMap): array
    {
        $blocks = [];
        $targets = [];

        foreach ($claimMap as $citation => $claims) {
            $source = $draft->source($citation) ?? $package->source($citation);

            if ($source === null || ! $source->hasEvidence()) {
                continue;
            }

            $claim = $this->citations->mergeClaims($claims);

            if (mb_strlen($claim) < 10) {
                continue;
            }

            $targets[$citation] = ['claim' => $claim, 'source' => $source];

            $blocks[] = sprintf(
                "[%d]\nCLAIM IN THE BOOK: %s\nSOURCE: %s\nSOURCE TEXT: %s",
                $citation,
                $claim,
                $source->url,
                mb_substr(preg_replace('/\s+/u', ' ', $source->retrievedContent) ?? '', 0, 3500)
            );
        }

        if ($targets === []) {
            return [];
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'verdicts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'citation' => ['type' => 'integer'],
                            'status' => ['type' => 'string', 'enum' => ['PASS', 'FAIL', 'UNCERTAIN']],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['citation', 'status', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['verdicts'],
            'additionalProperties' => false,
        ];

        $system = <<<'TXT'
        You are a strict fact checker. For each numbered item you are shown a claim
        made in a book and the text of the source cited for it.

        Decide whether the SOURCE TEXT supports the CLAIM.

        PASS      - the source text states the claim, or clearly implies it directly.
        FAIL      - the source text contradicts the claim, or clearly does not contain it.
        UNCERTAIN - the source text is on the topic but does not settle the claim.

        Be strict and literal. Do not reward a claim for being plausible, common
        knowledge, or likely to be true. Only the supplied source text counts.
        If a specific figure in the claim does not appear in the source text, the
        verdict is FAIL or UNCERTAIN, never PASS.

        Keep each reason under 30 words and say what specifically matched or did not.
        TXT;

        try {
            $data = $this->llm->sendJson($system, implode("\n\n---\n\n", $blocks), $schema, [
                'temperature' => 0.0,
            ]);
        } catch (\Throwable $e) {
            Log::error('[FactChecker] support check failed', ['error' => $e->getMessage()]);

            // Never silently pass a claim we could not judge.
            return array_map(
                fn (int $citation): FactCheckItem => new FactCheckItem(
                    $citation,
                    $targets[$citation]['claim'],
                    $targets[$citation]['source']->url,
                    $targets[$citation]['source']->organization,
                    FactCheckItem::UNCERTAIN,
                    'The automated support check could not be completed: '.$e->getMessage(),
                    'support'
                ),
                array_keys($targets)
            );
        }

        $items = [];

        foreach ((array) ($data['verdicts'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $citation = (int) ($entry['citation'] ?? 0);

            if (! isset($targets[$citation])) {
                continue;
            }

            $items[] = FactCheckItem::fromArray([
                'citation' => $citation,
                'claim' => $targets[$citation]['claim'],
                'source_url' => $targets[$citation]['source']->url,
                'source_organization' => $targets[$citation]['source']->organization,
                'status' => $entry['status'] ?? FactCheckItem::UNCERTAIN,
                'reason' => $entry['reason'] ?? '',
                'check_type' => 'support',
            ]);
        }

        // Any citation the model skipped stays UNCERTAIN rather than defaulting
        // to a pass.
        foreach ($targets as $citation => $target) {
            $answered = array_filter($items, static fn (FactCheckItem $i): bool => $i->citation === $citation);
            if ($answered === []) {
                $items[] = new FactCheckItem(
                    $citation,
                    $target['claim'],
                    $target['source']->url,
                    $target['source']->organization,
                    FactCheckItem::UNCERTAIN,
                    'The support check returned no verdict for this citation.',
                    'support'
                );
            }
        }

        return $items;
    }

    /**
     * Collapse the two layers into one verdict per citation.
     *
     * Precedence: FAIL beats everything, then UNCERTAIN, then PASS. A reachable
     * URL that the model could not confirm must never read as PASS.
     *
     * @param  array<int, FactCheckItem>  $items
     * @return array<int, FactCheckItem>
     */
    private function mergeVerdicts(array $items): array
    {
        $byCitation = [];

        foreach ($items as $item) {
            $existing = $byCitation[$item->citation] ?? null;

            if ($existing === null) {
                $byCitation[$item->citation] = $item;

                continue;
            }

            $byCitation[$item->citation] = $this->worse($existing, $item);
        }

        return $byCitation;
    }

    private function worse(FactCheckItem $a, FactCheckItem $b): FactCheckItem
    {
        $rank = [
            FactCheckItem::PASS => 0,
            FactCheckItem::UNCERTAIN => 1,
            FactCheckItem::FAIL => 2,
        ];

        return $rank[$a->status] >= $rank[$b->status] ? $a : $b;
    }
}