<?php

return [

    /*
    |---------------------------------------------------------------------------
    | LLM
    |---------------------------------------------------------------------------
    | "openai"    -> OpenAI / any OpenAI-compatible endpoint (Groq, OpenRouter,
    |                Together, Ollama, LM Studio...). Set AI_LLM_BASE_URL if needed.
    | "anthropic" -> Anthropic Messages API.
    | "null"      -> no network calls, returns empty responses (used by tests).
    */
    'llm' => [
        'provider' => env('AI_LLM_PROVIDER', 'openai'),
        'model' => env('AI_LLM_MODEL', 'openai/gpt-4o-mini'),
        'api_key' => env('AI_LLM_API_KEY', ''),
        // OpenRouter: https://openrouter.ai/api/v1
        // Groq:       https://api.groq.com/openai/v1
        // Ollama:     http://localhost:11434/v1
        'base_url' => env('AI_LLM_BASE_URL', 'https://openrouter.ai/api/v1'),
        'temperature' => (float) env('AI_LLM_TEMPERATURE', 0.3),
        'max_tokens' => (int) env('AI_LLM_MAX_TOKENS', 4000),
        'timeout' => (int) env('AI_LLM_TIMEOUT', 120),
        'retries' => (int) env('AI_LLM_RETRIES', 2),

        // OpenRouter asks for attribution headers on API traffic.
        // Keys must be preserved: LLMService treats a numerically-indexed
        // entry as a header with no name and silently drops it, so this is
        // array_filter() only, never array_values().
        'extra_headers' => array_filter([
            'HTTP-Referer' => env('AI_LLM_HTTP_REFERER'),
            'X-Title' => env('AI_LLM_APP_TITLE'),
        ], static fn ($value): bool => is_string($value) && $value !== ''),
    ],

    /*
    |---------------------------------------------------------------------------
    | Web search / retrieval
    |---------------------------------------------------------------------------
    | "duckduckgo" -> keyless HTML search (default, always available)
    | "tavily"     -> Tavily Search API. Preferred: fetches page text server-side
    |                 and can filter to official domains only. (needs key)
    | "serper"     -> https://google.serper.dev  (needs key)
    | "brave"      -> Brave Search API           (needs key)
    | "duckduckgo" -> keyless HTML endpoint (no-credentials fallback)
    | "null"       -> no network calls (used by tests)
    */
'search' => [
        'provider' => env('AI_SEARCH_PROVIDER', 'tavily'),
        'api_key' => env('AI_SEARCH_API_KEY', ''),
        'timeout' => (int) env('AI_SEARCH_TIMEOUT', 30),
        'retries' => (int) env('AI_SEARCH_RETRIES', 2),
        'results_per_query' => (int) env('AI_SEARCH_RESULTS_PER_QUERY', 8),
        'max_fetch_bytes' => (int) env('AI_SEARCH_MAX_FETCH_BYTES', 400000),

        // Below this many characters a "200 OK" page is treated as a bot
        // challenge rather than real content.
        'min_substantive_text' => (int) env('AI_SEARCH_MIN_TEXT', 400),

        'user_agent' => env('AI_SEARCH_USER_AGENT', 'MultiAgentBookWriter/1.0 (+https://example.org)'),

        // Tavily specific. Domain filtering is done server-side, so low-quality
        // aggregators never reach the researcher in the first place.
        'tavily_depth' => env('AI_TAVILY_DEPTH', 'advanced'),
        'tavily_raw_content' => env('AI_TAVILY_RAW_CONTENT', 'true'),
        'tavily_include_domains' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AI_TAVILY_INCLUDE_DOMAINS', ''))
        ))),
        'tavily_exclude_domains' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AI_TAVILY_EXCLUDE_DOMAINS', ''))
        ))),
    ],

    /*
    |---------------------------------------------------------------------------
    | Workflow
    |---------------------------------------------------------------------------
    */
    'workflow' => [
        'max_revisions' => (int) env('MAX_REVISIONS', 2),
        'chapter_count' => (int) env('CHAPTER_COUNT', 3),
        'min_chapter_words' => (int) env('MIN_CHAPTER_WORDS', 600),
        'max_chapter_words' => (int) env('MAX_CHAPTER_WORDS', 900),
        'min_sources_per_chapter' => (int) env('MIN_SOURCES_PER_CHAPTER', 4),
        'max_sources_per_chapter' => (int) env('MAX_SOURCES_PER_CHAPTER', 8),

        /*
         * Source diversity. Without a cap, the candidate pool is ordered by
         * content length and one publisher (usually PIB, which publishes long
         * pages) takes every slot, so a chapter ends up citing a single
         * organisation. These caps are applied both to the catalogue offered to
         * the model and to what it is allowed to select.
         */
        'max_candidates_per_domain' => (int) env('MAX_CANDIDATES_PER_DOMAIN', 3),
        'max_sources_per_domain' => (int) env('MAX_SOURCES_PER_DOMAIN', 2),
        'output_dir' => base_path('output'),
        'artifacts_dir' => base_path('output/artifacts'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Local storage
    |---------------------------------------------------------------------------
    | Run documents are plain JSON files. Tests point this at a temporary
    | directory so a test run never deletes real generation results.
    */
    'storage' => [
        'runs_path' => env('AI_RUNS_PATH', storage_path('app'.DIRECTORY_SEPARATOR.'runs')),
    ],

    /*
    |---------------------------------------------------------------------------
    | Research
    |---------------------------------------------------------------------------
    */
    'research' => [
        // Domains the researcher is told to prefer first.
        'preferred_domains' => [
            'npci.org.in',
            'rbi.org.in',
            'pib.gov.in',
            'financialservices.gov.in',
            'indiabudget.gov.in',
            'udyogamitra.gov.in',
            'data.gov.in',
            'ecb.europa.eu',
            'worldbank.org',
        ],
// Domains that are never acceptable as a citation.
//
// A book about payment regulation should cite the regulator, not a page that
// paraphrases it. These are blocked so a live run cannot quietly ground a
// chapter in a content farm, a UGC site, or a scraped document dump.
'blocked_domains' => [
            'wikipedia.org',
            'quora.com',
            'medium.com',
            'pinterest.com',
            'facebook.com',
            'instagram.com',
            'tiktok.com',
            'reddit.com',
            'geeksforgeeks.org',
            'demandsage.com',
            'suerf.org',
            'tradingview.com',
            'researchgate.net',
            'ssrn.com',
            'arxiv.org',
            'semanticscholar.org',
            'scribd.com',
            'issuu.com',
            'slideshare.net',
            'coursehero.com',
            'brainly.com',
            'askfilo.com',
            'blogspot.com',
            'wordpress.com',
            'wixsite.com',
            'yolasite.com',
            'slideshare.io',
            'pdfcoffee.com',
            'vdocuments.net',
            'idoc.pub',
            'courseity.com',
        ],
    ],

];