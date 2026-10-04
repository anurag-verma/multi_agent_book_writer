<?php

namespace App\AI\Services;

use App\AI\Exceptions\AgentException;
use App\AI\Interfaces\SearchProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Real web retrieval. The researcher uses search() to discover candidate
 * sources and the fact checker uses probe()/fetch() to confirm a URL really
 * exists and really says what the citation claims.
 *
 * Providers are chosen with AI_SEARCH_PROVIDER:
 *   tavily     -> Tavily Search API. Returns page text fetched on their servers,
 *                 which matters because several Indian government sites refuse
 *                 direct automated requests. Also supports domain filtering.
 *   serper     -> Google results via serper.dev
 *   brave      -> Brave Search API
 *   duckduckgo -> keyless HTML endpoint (kept as a no-credentials fallback)
 *   null       -> no network calls, used by the test suite
 */
class WebSearchService implements SearchProviderInterface
{
    private string $provider;

    private string $apiKey;

    private int $timeout;

    private int $retries;

    private string $userAgent;

    public function __construct()
    {
        $this->provider = (string) config('ai.search.provider', 'duckduckgo');
        $this->apiKey = (string) config('ai.search.api_key', '');
        $this->timeout = (int) config('ai.search.timeout', 20);
        $this->retries = (int) config('ai.search.retries', 2);
        $this->userAgent = (string) config('ai.search.user_agent', 'MultiAgentBookWriter/1.0');
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, string>>
     */
    public function search(string $query, int $limit = 8, array $options = []): array
    {
        if ($this->provider === 'null') {
            return [];
        }

        try {
            $results = match ($this->provider) {
                'tavily' => $this->searchTavily($query, $limit, $options),
                'serper' => $this->searchSerper($query, $limit),
                'brave' => $this->searchBrave($query, $limit),
                default => $this->searchDuckDuckGo($query, $limit),
            };
        } catch (AgentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw AgentException::search($e->getMessage(), ['query' => $query], $e);
        }

        Log::info('[Search] query completed', [
            'provider' => $this->provider,
            'query' => $query,
            'include_domains' => $options['include_domains'] ?? null,
            'results' => count($results),
        ]);

        return $results;
    }

    /**
     * Tavily Search API.
     *
     * Two features matter for this project:
     *  - include_raw_content returns the page text fetched from Tavily's own
     *    infrastructure. NPCI and RBI refuse direct automated requests from
     *    datacenter IPs, so this is often the only way to read their pages.
     *  - include_domains lets us ask for official sources only, which raises
     *    citation quality before the LLM ever sees the results.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, string>>
     */
    private function searchTavily(string $query, int $limit, array $options = []): array
    {
        $this->assertApiKey('tavily');

        $payload = [
            'query' => $query,
            'search_depth' => (string) ($options['search_depth'] ?? config('ai.search.tavily_depth', 'advanced')),
            'max_results' => min($limit, 20),
            'include_raw_content' => (string) config('ai.search.tavily_raw_content', 'true') === 'true',
            'include_answer' => false,
        ];

        // Per-query domain filters win over the global config, which lets the
        // researcher run official-only passes and open-web passes in one run.
        $include = $options['include_domains'] ?? config('ai.search.tavily_include_domains', []);
        if (is_array($include) && $include !== []) {
            $payload['include_domains'] = array_values(array_filter($include));
        }

        $exclude = array_merge(
            (array) config('ai.search.tavily_exclude_domains', []),
            (array) ($options['exclude_domains'] ?? [])
        );
        $exclude = array_values(array_unique(array_filter($exclude)));
        if ($exclude !== []) {
            $payload['exclude_domains'] = $exclude;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->retry($this->retries, 500, throw: false)
            ->post('https://api.tavily.com/search', $payload);

        if ($response->failed()) {
            throw AgentException::search(
                'Tavily returned HTTP '.$response->status().': '.Str::limit($response->body(), 300),
                ['query' => $query, 'status' => $response->status()]
            );
        }

        $results = [];
        foreach ((array) $response->json('results', []) as $item) {
            $url = (string) ($item['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $results[] = [
                'title' => trim((string) ($item['title'] ?? '')),
                'url' => $url,
                'snippet' => Str::limit(trim((string) ($item['content'] ?? '')), 600),
                // Page text fetched by Tavily. The fact checker prefers this over
                // fetching the URL ourselves when it is present.
                'raw_content' => Str::limit((string) ($item['raw_content'] ?? ''), (int) config('ai.search.max_fetch_bytes', 400000), ''),
                'score' => (string) ($item['score'] ?? ''),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Keyless HTML endpoint. Retained as a no-credentials fallback so the
     * project still boots without any search credentials.
     *
     * @return array<int, array<string, string>>
     */
    private function searchDuckDuckGo(string $query, int $limit): array
    {
        $response = Http::withHeaders([
            'User-Agent' => $this->userAgent,
            'Accept' => 'text/html',
        ])
            ->timeout($this->timeout)
            ->retry($this->retries, 500, throw: false)
            ->post('https://html.duckduckgo.com/html/', ['q' => $query]);

        if ($response->failed()) {
            throw AgentException::search('DuckDuckGo returned HTTP '.$response->status(), ['query' => $query]);
        }

        $html = $response->body();

        $results = [];
        // Result anchors look like: <a rel="nofollow" class="result__a" href="...">Title</a>
        if (preg_match_all(
            '#<a[^>]+class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
                $url = $this->cleanDuckDuckGoUrl($url);

                if ($url === '' || ! Str::startsWith($url, 'http')) {
                    continue;
                }

                $results[] = [
                    'title' => trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5)),
                    'url' => $url,
                    'snippet' => '',
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * DuckDuckGo wraps outbound links in /l/?uddg=<urlencoded>. Unwrap them so
     * the citation stores the publisher's real URL.
     */
    private function cleanDuckDuckGoUrl(string $url): string
    {
        if (! Str::contains($url, 'duckduckgo.com/l/')) {
            return $url;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query)) {
            return $url;
        }

        parse_str($query, $params);
        $target = $params['uddg'] ?? null;

        return is_string($target) ? urldecode($target) : $url;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function searchSerper(string $query, int $limit): array
    {
        $this->assertApiKey('serper');

        $response = Http::withHeaders([
            'X-API-KEY' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->retry($this->retries, 500, throw: false)
            ->post('https://google.serper.dev/search', [
                'q' => $query,
                'num' => $limit,
            ]);

        if ($response->failed()) {
            throw AgentException::search('Serper returned HTTP '.$response->status(), ['query' => $query]);
        }

        $results = [];
        foreach ((array) $response->json('organic', []) as $item) {
            if (! isset($item['link'])) {
                continue;
            }
            $results[] = [
                'title' => (string) ($item['title'] ?? ''),
                'url' => (string) $item['link'],
                'snippet' => (string) ($item['snippet'] ?? ''),
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function searchBrave(string $query, int $limit): array
    {
        $this->assertApiKey('brave');

        $response = Http::withHeaders([
            'X-Subscription-Token' => $this->apiKey,
            'Accept' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->retry($this->retries, 500, throw: false)
            ->get('https://api.search.brave.com/res/v1/web/search', [
                'q' => $query,
                'count' => min($limit, 20),
            ]);

        if ($response->failed()) {
            throw AgentException::search('Brave returned HTTP '.$response->status(), ['query' => $query]);
        }

        $results = [];
        foreach ((array) $response->json('web.results', []) as $item) {
            if (! isset($item['url'])) {
                continue;
            }
            $results[] = [
                'title' => (string) ($item['title'] ?? ''),
                'url' => (string) $item['url'],
                'snippet' => Str::limit(strip_tags((string) ($item['description'] ?? '')), 400),
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function fetch(string $url): string
    {
        [$ok, $status] = $this->probe($url);

        if (! $ok) {
            Log::warning('[Search] fetch failed', ['url' => $url, 'status' => $status]);

            return '';
        }

        $response = Http::withHeaders(['User-Agent' => $this->userAgent])
            ->timeout($this->timeout)
            ->retry($this->retries, 500, throw: false)
            ->get($url);

        if ($response->failed()) {
            return '';
        }

        $text = $this->htmlToText(Str::limit($response->body(), (int) config('ai.search.max_fetch_bytes', 400000), ''));

        // A 200 that yields almost no text is a bot-challenge stub, not the
        // page. Returning '' here is what stops the fact checker from passing
        // a citation on the strength of a misleading status code.
        if (mb_strlen($text) < (int) config('ai.search.min_substantive_text', 400)) {
            Log::warning('[Search] fetch returned no substantive content', [
                'url' => $url,
                'status' => $response->status(),
                'chars' => mb_strlen($text),
            ]);

            return '';
        }

        return $text;
    }

    /**
     * Reachability check used by the fact checker. HEAD is cheap but many
     * publishers reject it, so a 405 falls back to a ranged GET.
     *
     * @return array{0: bool, 1: int, 2: string}
     */
    public function probe(string $url): array
    {
        if ($this->provider === 'null') {
            return [false, 0, ''];
        }

        $response = Http::withHeaders(['User-Agent' => $this->userAgent])
            ->timeout($this->timeout)
            ->withOptions(['allow_redirects' => ['max' => 5]])
            ->head($url);

        if ($response->status() === 405 || $response->status() === 403) {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent,
                'Range' => 'bytes=0-2048',
            ])
                ->timeout($this->timeout)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);
        }

        $status = $response->status();
        $finalUrl = $response->effectiveUri()->__toString();

        return [$response->successful(), $status, $finalUrl];
    }

    /**
     * Strip markup down to readable text. A full readability implementation is
     * overkill here; removing script/style/nav noise and collapsing whitespace is
     * enough for an LLM to judge whether a page supports a claim.
     */
    private function htmlToText(string $html): string
    {
        // Drop noisy elements entirely, keeping their text would add noise.
        $html = preg_replace('#<(script|style|noscript|svg|form|nav|footer|header|aside)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        // Keep paragraph and list separation as newlines.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|li|tr|h[1-6])>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function assertApiKey(string $provider): void
    {
        if ($this->apiKey === '') {
            throw AgentException::search("AI_SEARCH_API_KEY is required for the '{$provider}' provider.");
        }
    }
}