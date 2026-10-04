<?php

namespace App\AI\Agents;

use App\AI\DTO\BookBrief;
use App\AI\DTO\ChapterOutline;
use App\AI\DTO\ResearchPackage;
use App\AI\DTO\SourceItem;
use App\AI\Exceptions\AgentException;
use App\AI\Helpers\TextUtils;
use App\AI\Interfaces\LLMInterface;
use App\AI\Interfaces\SearchProviderInterface;
use App\AI\Services\SourceVerifier;
use Illuminate\Support\Facades\Log;

/**
 * Agent 2 of 5, and the owner of truth for citations.
 *
 * Two passes:
 *  1. Discovery - run real web searches and build a pool of candidate URLs.
 *     Nothing is cited yet.
 *  2. Verification - every candidate is confirmed by SourceVerifier. Anything
 *     unreachable or unreadable is dropped here and never reaches the Writer.
 *
 * The LLM's only job inside this agent is to say which verified candidates to
 * keep and which specific claims each one supports. It returns candidate indices,
 * never URLs, so it is structurally incapable of inventing a source.
 */
class ResearcherAgent
{
    /**
     * Public suffixes that occupy two labels, so the label in front of them is
     * the publisher rather than the suffix itself.
     *
     * @var list<string>
     */
    private const MULTI_LABEL_SUFFIXES = [
        'gov.in',
        'org.in',
        'net.in',
        'ac.in',
        'edu.in',
        'firm.in',
        'gen.in',
        'ind.in',
        'co.in',
        'europa.eu',
        'gov.uk',
        'org.uk',
        'ac.uk',
        'co.uk',
        'com.au',
        'com.br',
        'co.nz',
        'co.za',
    ];
    public function __construct(
        private readonly LLMInterface $llm,
        private readonly SearchProviderInterface $search,
        private readonly SourceVerifier $verifier,
    ) {}

    /**
     * @param  array<int, string>  $extraQueries  Additional queries, e.g. on a revision pass.
     */
    public function research(BookBrief $brief, ChapterOutline $chapter, array $extraQueries = []): ResearchPackage
    {
        Log::info('[Researcher] Chapter '.$chapter->chapterNumber.' research started', [
            'title' => $chapter->title,
        ]);

        $candidates = $this->discoverCandidates($brief, $chapter, $extraQueries);

        Log::info('[Researcher] candidates discovered', [
            'chapter' => $chapter->chapterNumber,
            'count' => count($candidates),
        ]);

        if ($candidates === []) {
            throw new AgentException(
                'No candidate sources were found for chapter '.$chapter->chapterNumber.'.',
                ['chapter' => $chapter->chapterNumber]
            );
        }

        $verified = $this->verifyCandidates($candidates);

        Log::info('[Researcher] candidates verified', [
            'chapter' => $chapter->chapterNumber,
            'verified' => count($verified),
            'rejected' => count($candidates) - count($verified),
        ]);

        if ($verified === []) {
            throw new AgentException(
                'Every candidate source for chapter '.$chapter->chapterNumber.' failed verification.',
                ['chapter' => $chapter->chapterNumber]
            );
        }

        $selected = $this->selectSources($brief, $chapter, $verified);

        Log::info('[Researcher] Found '.count($selected).' sources', [
            'chapter' => $chapter->chapterNumber,
        ]);

        return new ResearchPackage(
            $chapter->chapterNumber,
            $chapter->title,
            $selected,
            $this->notes($candidates, $verified),
        );
    }

    /**
     * Run real searches. An official-only pass runs first so regulators and
     * government bodies are preferred, followed by an open web pass for
     * reputable reporting.
     *
     * @param  array<int, string>  $extraQueries
     * @return array<string, array<string, string>> Keyed by canonical URL.
     */
    private function discoverCandidates(BookBrief $brief, ChapterOutline $chapter, array $extraQueries): array
    {
        $preferred = array_slice($brief->sourcePreferences, 0, 6);
        $limit = (int) config('ai.search.results_per_query', 8);

        $queries = [];

        foreach ($chapter->suggestedResearchAreas as $area) {
            $queries[] = ['q' => $area, 'domains' => $preferred];
        }

        foreach ($extraQueries as $query) {
            $queries[] = ['q' => $query, 'domains' => $preferred];
        }

        // Always run a couple of broad passes so a chapter is not starved if the
        // planner's research areas return nothing usable.
        $queries[] = ['q' => $chapter->title.' statistics data India', 'domains' => []];
        $queries[] = ['q' => implode(' ', array_slice($chapter->keyTopics, 0, 3)).' India figures', 'domains' => []];

        $pool = [];
        $errors = [];

        foreach ($queries as $entry) {
            try {
                $results = $this->search->search($entry['q'], $limit, array_filter([
                    'include_domains' => $entry['domains'],
                ]));

                foreach ($results as $result) {
                    $url = TextUtils::canonicalUrl($result['url'] ?? '');

                    if ($url === '' || ! $this->verifier->isDomainAllowed($url)) {
                        continue;
                    }

                    // Keep the richest copy of a page we have seen more than once.
                    if (! isset($pool[$url]) || strlen($result['raw_content'] ?? '') > strlen($pool[$url]['raw_content'])) {
                        $pool[$url] = [
                            'url' => $url,
                            'title' => trim((string) ($result['title'] ?? '')),
                            'snippet' => (string) ($result['snippet'] ?? ''),
                            'raw_content' => (string) ($result['raw_content'] ?? ''),
                            'preferred' => $this->verifier->isPreferredDomain($url),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $entry['q'].': '.$e->getMessage();
                Log::warning('[Researcher] search query failed', ['query' => $entry['q'], 'error' => $e->getMessage()]);
            }
        }

        if ($errors !== []) {
            Log::info('[Researcher] some queries failed', ['errors' => $errors]);
        }

        // Official sources first, then by available evidence.
        uasort($pool, static function (array $a, array $b): int {
            return [$b['preferred'], strlen($b['raw_content'])] <=> [$a['preferred'], strlen($a['raw_content'])];
        });

        return array_slice($this->diversifyByDomain($pool), 0, 14, true);
    }

    /**
     * Cap how many candidates any one domain may contribute.
     *
     * Ranking purely by content length means a single publisher with long pages
     * takes every slot in the catalogue, and the model can only choose from what
     * it is shown. Interleaving by domain here is therefore what actually
     * produces a multi-source chapter; asking the model to diversify is not
     * enough when every alternative has been filtered out before it sees them.
     *
     * @param  array<string, array<string, mixed>>  $pool
     * @return array<int, array<string, mixed>>
     */
    private function diversifyByDomain(array $pool): array
    {
        $cap = max(1, (int) config('ai.workflow.max_candidates_per_domain', 3));

        $byDomain = [];

        foreach ($pool as $candidate) {
            $byDomain[$this->domainKey((string) $candidate['url'])][] = $candidate;
        }

        // Take the cap from the strongest domain first, then round-robin so a
        // weaker domain still gets its best pages into the catalogue.
        uksort($byDomain, static function (string $a, string $b) use ($byDomain): int {
            return count($byDomain[$b]) <=> count($byDomain[$a]);
        });

        $diverse = [];
        $taken = [];

        foreach ($byDomain as $domain => $candidates) {
            $taken[$domain] = 0;
        }

        // Two passes: official sources rotate first, everything else only fills
        // the gaps they leave. Diversity must not promote a content farm ahead
        // of the regulator.
        $preferred = [];
        $rest = [];

        foreach ($byDomain as $domain => $candidates) {
            if ((bool) ($candidates[0]['preferred'] ?? false)) {
                $preferred[$domain] = $candidates;
            } else {
                $rest[$domain] = $candidates;
            }
        }

        foreach ([$preferred, $rest] as $rotation) {
            $exhausted = false;

            while (! $exhausted) {
                $exhausted = true;

                foreach ($rotation as $domain => $candidates) {
                    if ($taken[$domain] >= $cap || $taken[$domain] >= count($candidates)) {
                        continue;
                    }

                    $diverse[] = $candidates[$taken[$domain]];
                    $taken[$domain]++;
                    $exhausted = false;
                }
            }
        }

        return $diverse;
    }

    /**
     * A stable key for "who published this", used for diversity accounting.
     */
    /**
     * Registrable domain for a URL, used as the publisher identity.
     *
     * Naively taking the last two labels is wrong for the sources this project
     * actually cites: NPCI, the RBI and every other *.org.in host collapse to
     * "org.in", and PIB, the Finance Ministry and data.gov.in all collapse to
     * "gov.in". Those are different publishers, so the diversity cap would
     * ration slots between distinct regulators as if they were one.
     *
     * Multi-label public suffixes are therefore peeled off first, so
     * rbi.org.in and pib.gov.in stay separate while pay.npci.org.in still folds
     * into npci.org.in.
     */
    private function domainKey(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host === '') {
            return '';
        }

        $parts = explode('.', $host);

        foreach (self::MULTI_LABEL_SUFFIXES as $suffix) {
            if (! str_ends_with($host, '.'.$suffix)) {
                continue;
            }

            // Everything in front of the suffix, reduced to its last label, so
            // pay.npci.org.in folds into npci.org.in while rbi.org.in keeps its
            // own identity.
            $head = substr($host, 0, -strlen($suffix) - 1);

            if ($head === '') {
                continue;
            }

            $labels = explode('.', $head);
            $publisher = end($labels);

            return $publisher.'.'.$suffix;
        }

        $tail = implode('.', array_slice($parts, -2));

        return $tail !== '' ? $tail : $host;
    }

    /**
     * @param  array<string, array<string, string>>  $candidates
     * @return array<int, array<string, mixed>>      Indexed list for the LLM.
     */
    private function verifyCandidates(array $candidates): array
    {
        $verified = [];
        $index = 0;

        foreach ($candidates as $candidate) {
            $result = $this->verifier->verify($candidate['url'], $candidate['raw_content']);

            if (! $result['verified']) {
                continue;
            }

            $verified[] = [
                'index' => $index,
                'url' => $candidate['url'],
                'title' => $candidate['title'] !== '' ? $candidate['title'] : TextUtils::domain($candidate['url']),
                'organization' => $this->guessOrganization($candidate['url']),
                'snippet' => $candidate['snippet'],
                'content' => mb_substr($result['content'], 0, 3500),
                'preferred' => $candidate['preferred'],
                'verification_note' => $result['note'],
                'http_status' => $result['http_status'],
            ];

            $index++;
        }

        return $verified;
    }

    /**
     * Ask the LLM to pick sources and record what each one proves. It answers
     * with candidate indices, which we map back to real URLs.
     *
     * @param  array<int, array<string, mixed>>  $verified
     * @return array<int, SourceItem>
     */
    private function selectSources(BookBrief $brief, ChapterOutline $chapter, array $verified): array
    {
        $min = (int) config('ai.workflow.min_sources_per_chapter', 4);
        $max = (int) config('ai.workflow.max_sources_per_chapter', 8);

        $schema = [
            'type' => 'object',
            'properties' => [
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'index' => ['type' => 'integer'],
                            'organization' => ['type' => 'string'],
                            'claims_supported' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 1,
                            ],
                        ],
                        'required' => ['index', 'organization', 'claims_supported'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['sources'],
            'additionalProperties' => false,
        ];

        $catalogue = [];
        foreach ($verified as $candidate) {
            $catalogue[] = sprintf(
                "[%d] %s\n    URL: %s\n    Title: %s\n    Content: %s",
                $candidate['index'],
                $candidate['organization'],
                $candidate['url'],
                $candidate['title'],
                mb_substr(preg_replace('/\s+/u', ' ', $candidate['content']) ?? '', 0, 1500)
            );
        }

        $system = <<<'TXT'
        You are the Researcher in a multi-agent book pipeline.

        You are given a numbered catalogue of web pages that have ALREADY been
        downloaded and verified as real and readable.

        Choose the sources that best support this chapter, and for each one record
        the specific factual claims it can prove.

        Absolute rules:
        - Only reference items by their catalogue index. Never write a URL.
        - Every claim you list must actually appear in, or be directly supported
          by, the content shown for that item. Do not infer, do not guess.
        - Prefer official sources (NPCI, RBI, Press Information Bureau, Ministry of
          Finance, Department of Financial Services) over blogs and aggregators.
        - Spread your choices across as many different publishers as you can. A
          chapter backed by four pages from one organisation is weaker than one
          drawing on NPCI, the RBI and the Press Information Bureau separately.
        - Write each claim as a short standalone statement containing a figure or
          date where the source gives one, for example: "UPI processed 16.4 billion
          transactions in 2024."
        - Drop any source whose content does not actually contain usable facts.
        TXT;

        $user = <<<TXT
        Book: {$brief->title}
        Chapter {$chapter->chapterNumber}: {$chapter->title}
        Purpose: {$chapter->purpose}

        Key topics:
        - {$this->bullets($chapter->keyTopics)}

        The chapter must answer:
        - {$this->bullets($chapter->importantQuestions)}

        Verified source catalogue:
        {$this->bullets($catalogue)}

        Select between {$min} and {$max} sources and record their supported claims.
        TXT;

        $data = $this->llm->sendJson($system, $user, $schema, ['temperature' => 0.2]);

        $byIndex = [];
        foreach ($verified as $candidate) {
            $byIndex[$candidate['index']] = $candidate;
        }

        $sources = [];
        $seenIndexes = [];
        $perDomain = [];
        $domainCap = max(1, (int) config('ai.workflow.max_sources_per_domain', 2));

        foreach ((array) ($data['sources'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $index = (int) ($entry['index'] ?? -1);

            // The LLM cannot introduce a source that was not verified.
            if (! isset($byIndex[$index]) || isset($seenIndexes[$index])) {
                Log::warning('[Researcher] discarded unverified or duplicate source reference', [
                    'chapter' => $chapter->chapterNumber,
                    'index' => $index,
                ]);
                continue;
            }

            $seenIndexes[$index] = true;
            $candidate = $byIndex[$index];

            // Diversity is enforced here as well as in the catalogue, so a model
            // that lists six PIB pages cannot produce a single-source chapter.
            $domain = $this->domainKey((string) $candidate['url']);

            if (($perDomain[$domain] ?? 0) >= $domainCap) {
                Log::info('[Researcher] skipped source to keep the chapter diverse', [
                    'chapter' => $chapter->chapterNumber,
                    'domain' => $domain,
                    'index' => $index,
                ]);
                continue;
            }

            $claims = array_values(array_filter(array_map(
                static fn (mixed $c): string => trim((string) $c),
                (array) ($entry['claims_supported'] ?? [])
            )));

            if ($claims === []) {
                continue;
            }

            $perDomain[$domain] = ($perDomain[$domain] ?? 0) + 1;

            $sources[] = new SourceItem(
                citationId: count($sources) + 1,
                organization: trim((string) ($entry['organization'] ?? '')) !== ''
                    ? trim((string) $entry['organization'])
                    : $candidate['organization'],
                title: $candidate['title'],
                url: $candidate['url'],
                claimsSupported: $claims,
                retrievedContent: $candidate['content'],
                verified: true,
                verificationNote: $candidate['verification_note'],
                httpStatus: (int) $candidate['http_status'],
            );

            if (count($sources) >= $max) {
                break;
            }
        }

        if ($sources === []) {
            throw new AgentException(
                'The Researcher could not map any selection back to a verified source for chapter '
                .$chapter->chapterNumber.'.',
                ['chapter' => $chapter->chapterNumber]
            );
        }

        return $sources;
    }

    /**
     * @param  array<string, array<string, string>>  $candidates
     * @param  array<int, array<string, mixed>>      $verified
     * @return array<int, string>
     */
    private function notes(array $candidates, array $verified): array
    {
        $notes = [
            sprintf('Discovered %d candidate sources, %d passed verification.', count($candidates), count($verified)),
        ];

        $rejected = count($candidates) - count($verified);
        if ($rejected > 0) {
            $notes[] = sprintf('%d candidate(s) were discarded as unreachable or unreadable.', $rejected);
        }

        return $notes;
    }

    /**
     * Best-effort organisation name from the hostname. The LLM may override it,
     * but a sensible default stops "Unknown" reaching the reference list.
     */
    private function guessOrganization(string $url): string
    {
        $domain = TextUtils::domain($url);

        return match (true) {
            str_contains($domain, 'npci.org.in') => 'National Payments Corporation of India (NPCI)',
            str_contains($domain, 'rbi.org.in') => 'Reserve Bank of India (RBI)',
            str_contains($domain, 'pib.gov.in') => 'Press Information Bureau, Government of India',
            str_contains($domain, 'financialservices.gov.in') => 'Department of Financial Services, Ministry of Finance',
            str_contains($domain, 'indiabudget.gov.in') => 'Ministry of Finance, Government of India',
            str_contains($domain, 'data.gov.in') => 'Government of India Open Data Portal',
            str_contains($domain, 'egazette.gov.in') => 'Government of India Gazette',
            str_contains($domain, 'india.gov.in') => 'National Portal of India',
            default => ucfirst(explode('.', $domain)[0] ?? $domain),
        };
    }

    /** @param array<int, string> $items */
    private function bullets(array $items): string
    {
        return implode("\n- ", $items);
    }
}