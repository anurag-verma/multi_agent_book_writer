<?php

namespace App\AI\Services;

use App\AI\Helpers\TextUtils;
use App\AI\Interfaces\SearchProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a candidate URL may become a citation.
 *
 * Two independent conditions must hold:
 *
 *  1. Reachability - the URL resolves to a successful HTTP response.
 *  2. Substance - the response actually contains readable page content.
 *
 * The second condition is not optional. In testing, npci.org.in answered HTTP 200
 * with an 82-byte body containing nothing but the page title, because a bot
 * protection layer intercepted the request. A status-code-only check would have
 * accepted that as a valid source and the book would have cited a page that does
 * not exist for the reader.
 */
class SourceVerifier
{
    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    public function __construct(private readonly SearchProviderInterface $search) {}

    /**
     * @param  string  $providedContent  Page text the search provider already fetched, if any.
     * @return array{
     *     verified: bool,
     *     content: string,
     *     http_status: int,
     *     note: string,
     *     retrieval: string
     * }
     */
    public function verify(string $url, string $providedContent = ''): array
    {
        $canonical = TextUtils::canonicalUrl($url);

        if ($canonical === '' || ! TextUtils::isValidUrl($canonical)) {
            return $this->result(false, '', 0, 'Not a valid absolute http(s) URL.', 'none');
        }

        if (TextUtils::domain($canonical) === '') {
            return $this->result(false, '', 0, 'Could not determine a domain for this URL.', 'none');
        }

        $key = 'source:'.sha1($canonical.'|'.mb_strlen($providedContent));

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if (Cache::has($key)) {
            return $this->memo[$key] = Cache::get($key);
        }

        $minText = (int) config('ai.search.min_substantive_text', 400);

        // Fast path: the search provider already retrieved usable page text.
        $provided = TextUtils::htmlToText($providedContent);
        if (mb_strlen($provided) >= $minText) {
            $result = $this->result(
                true,
                $provided,
                200,
                'Page text retrieved via the search provider.',
                'search_provider'
            );

            return $this->remember($key, $result);
        }

        [$reachable, $status, $finalUrl] = $this->search->probe($canonical);

        if (! $reachable) {
            return $this->remember($key, $this->result(
                false,
                '',
                $status,
                "URL is not reachable (HTTP {$status}).",
                'probe'
            ));
        }

        $fetched = $this->search->fetch($canonical);

        if (trim($fetched) === '') {
            return $this->remember($key, $this->result(
                false,
                '',
                $status,
                'The URL responded but returned no readable page content (most likely bot protection). '
                .'Treating as unverifiable.',
                'fetch'
            ));
        }

        return $this->remember($key, $this->result(
            true,
            $fetched,
            $status,
            'URL is reachable and returned readable page content.',
            'direct_fetch'
        ));
    }

    /**
     * Cheap policy filter applied before verification to save HTTP calls.
     */
    public function isDomainAllowed(string $url): bool
    {
        $domain = TextUtils::domain($url);

        if ($domain === '') {
            return false;
        }

        $blocked = (array) config('ai.research.blocked_domains', []);
        foreach ($blocked as $candidate) {
            $candidate = ltrim((string) $candidate, '.');
            if ($candidate !== '' && ($domain === $candidate || str_ends_with($domain, '.'.$candidate))) {
                return false;
            }
        }

        return true;
    }

    public function isPreferredDomain(string $url): bool
    {
        $domain = TextUtils::domain($url);

        foreach ((array) config('ai.research.preferred_domains', []) as $candidate) {
            $candidate = ltrim((string) $candidate, '.');
            if ($candidate !== '' && ($domain === $candidate || str_ends_with($domain, '.'.$candidate))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function remember(string $key, array $result): array
    {
        Cache::put($key, $result, now()->addHours(12));

        return $this->memo[$key] = $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(bool $verified, string $content, int $status, string $note, string $retrieval): array
    {
        if (! $verified) {
            Log::info('[Verify] rejected', ['status' => $status, 'note' => $note]);
        }

        return [
            'verified' => $verified,
            'content' => $content,
            'http_status' => $status,
            'note' => $note,
            'retrieval' => $retrieval,
        ];
    }
}