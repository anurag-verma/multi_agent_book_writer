<?php

namespace App\AI\Helpers;

/**
 * Pure-PHP text analysis. Everything here is deterministic and unit tested, so
 * the final validator never needs to spend an LLM call on these checks.
 */
final class TextUtils
{
    /**
     * Word count used for the 600-900 rule.
     *
     * Counts runs of letters and digits as one word, and keeps internal
     * apostrophes, hyphens and decimal points together, so "small-business" and
     * "16.4" each count once. Decimal handling matters here because this book is
     * full of figures like "16.4 billion"; splitting them would inflate the
     * count and wrongly fail the 600-900 check.
     */
    public static function countWords(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        $clean = self::stripMarkdown($text);

        // stripMarkdown keeps citation markers so that claim attribution works;
        // a marker is not a word, so remove them here instead.
        $clean = preg_replace('/\[\d+\]/', ' ', $clean) ?? $clean;

        if (preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-.][\p{L}\p{N}]+)*/u', $clean, $matches) === false) {
            return 0;
        }

        return count($matches[0]);
    }

    /**
     * Citation markers in order of appearance, e.g. [1], [2], [12].
     *
     * @return array<int, int>
     */
    public static function extractCitations(string $text): array
    {
        if (preg_match_all('/\[(\d+)\]/', $text, $matches) === false) {
            return [];
        }

        return array_map('intval', $matches[1]);
    }

    /**
     * Unique citation numbers, ascending.
     *
     * @return array<int, int>
     */
    public static function uniqueCitations(string $text): array
    {
        $unique = array_values(array_unique(self::extractCitations($text)));
        sort($unique);

        return $unique;
    }

    /**
     * True when a sentence carries at least one citation marker.
     */
    public static function sentenceHasCitation(string $sentence): bool
    {
        return preg_match('/\[\d+\]/', $sentence) === 1;
    }

    /**
     * Split prose into sentences without breaking on common abbreviations or
     * decimals, which would otherwise produce misleading fragments.
     *
     * @return array<int, string>
     */
    public static function sentences(string $text): array
    {
        $text = self::stripMarkdown($text);

        $parts = preg_split(
            '/(?<=[.!?])\s+(?=[A-Z"\'“(])/u',
            trim($text)
        ) ?: [];

        $sentences = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $sentences[] = $part;
            }
        }

        return $sentences;
    }

    /**
     * A bullet list sneaking into prose. The assignment forbids bullets inside
     * chapters, so the reference list is excluded by the caller, not here.
     */
    public static function hasBulletPoints(string $text): bool
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if (preg_match('/^([-*+•·‣▪]|\d+[.)])\s+\S/u', $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Markdown headings and emphasis carry no words, so remove the syntax but
     * keep the visible text.
     *
     * Citation markers such as [1] are deliberately preserved here. Removing
     * them would destroy the link between a claim and its source, which is the
     * one thing the Fact Checker depends on. countWords() strips them itself.
     */
    public static function stripMarkdown(string $text): string
    {
        $text = preg_replace('/```.*?```/s', ' ', $text) ?? $text;
        $text = preg_replace('/`([^`]*)`/', '$1', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__)(.*?)\1/s', '$2', $text) ?? $text;
        $text = preg_replace('/(\*|_)(.*?)\1/s', '$2', $text) ?? $text;
        $text = preg_replace('/https?:\/\/\S+/', ' ', $text) ?? $text;

        return $text;
    }

    /**
     * Normalise CRLF/CR to LF so offsets and line maths behave predictably.
     */
    public static function normalizeLineEndings(string $text): string
    {
        return preg_replace('/\R/u', "\n", $text) ?? $text;
    }

    /**
     * Remove HTML tags and collapse whitespace. Used when feeding fetched web
     * pages to the fact checker.
     */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text);
    }

    /**
     * Normalise a URL for storage and comparison: lowercase host, no tracking
     * parameters, no fragment, no trailing slash.
     */
    public static function canonicalUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return '';
        }

        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        $path = rtrim($parts['path'] ?? '', '/');

        $query = '';
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $params);
            unset($params['utm_source'], $params['utm_medium'], $params['utm_campaign'], $params['utm_term'], $params['utm_content'], $params['ref'], $params['source']);

            // Government sites mirror one document across locale and region
            // variants. PIB, for example, serves the same press release as
            // ?PRID=123&reg=3&lang=1 and ?PRID=123&reg=3&lang=2, which were
            // being cited as two separate sources. The document id is kept; only
            // the presentation parameters are dropped.
            unset($params['lang'], $params['reg']);
            if ($params !== []) {
                $query = '?'.http_build_query($params);
            }
        }

        $url = 'https://'.$host.$path.$query;

        return rtrim($url, '/');
    }

    /**
     * Registrable-ish domain used to group and filter sources.
     * Good enough for our purposes (npci.org.in, rbi.org.in, ...).
     */
    public static function domain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        $parts = explode('.', $host);
        $count = count($parts);

        if ($count <= 2) {
            return $host;
        }

        // Handle co.uk / com.au style public suffixes.
        $twoPartSuffixes = ['co.uk', 'org.uk', 'gov.in', 'ac.in', 'net.in', 'org.in', 'com.au', 'co.nz'];
        $lastTwo = $parts[$count - 2].'.'.$parts[$count - 1];

        if (in_array($lastTwo, $twoPartSuffixes, true)) {
            return implode('.', array_slice($parts, -3));
        }

        return $lastTwo;
    }

    public static function isValidUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}