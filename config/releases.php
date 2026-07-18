<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Release Parser Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the release URL parser and link extraction.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | CSS Selector for Link Extraction
    |--------------------------------------------------------------------------
    |
    | The CSS selector used to find links in the HTML content.
    | Default: 'td.bodyContent a[href'
    |
    */
    'parser_selector' => env('RELEASE_PARSER_SELECTOR', 'td.bodyContent a[href]'),

    /*
    |--------------------------------------------------------------------------
    | SOCKS5 Proxy for Parser and Translator
    |--------------------------------------------------------------------------
    |
    | Proxy in host:port format used by StorePostJob (curl fetch) and
    | Google Translate requests. Read via config() so it keeps working
    | after `artisan config:cache` (bare env() would return null).
    |
    */
    'curl_proxy' => env('CURL_PROXY'),

    /*
    |--------------------------------------------------------------------------
    | Curl Binary Override (curl-impersonate)
    |--------------------------------------------------------------------------
    |
    | Path to a curl-impersonate wrapper (e.g. curl_chrome116) used by
    | StorePostJob to bypass TLS-fingerprint bot detection (Cloudflare on
    | medium.com etc.). The wrapper sets Chrome TLS fingerprint and headers
    | itself. Leave empty to use system /usr/bin/curl with manual headers.
    |
    */
    'curl_binary' => env('CURL_BINARY'),

    /*
    |--------------------------------------------------------------------------
    | CSS Class for Post Content Extraction
    |--------------------------------------------------------------------------
    |
    | The CSS class name used by StorePostJob to find the content block
    | inside each parsed article page.
    |
    */
    'post_selector' => env('RELEASE_POST_SELECTOR', 'article-body'),

    /*
    |--------------------------------------------------------------------------
    | Per-Domain CSS Selectors for Post Content
    |--------------------------------------------------------------------------
    |
    | Maps domain substrings to CSS class names used by StorePostJob
    | to find the content block. If a URL matches a domain key,
    | that selector is used; otherwise 'post_selector' is used as fallback.
    |
    */
    'domain_selectors' => [
        'dev.to' => '#article-body',
        'medium.com' => 'article',
        'gitconnected.com' => 'article',
        'laravel-news.com' => 'article',
        'symfony.com' => 'article',
        'langchain.com' => '.w-richtext',
        'stackademic.com' => 'article',
        'stitcher.io' => 'article',
        'christoph-rumpel.com' => 'article',
        'cakedc.com' => 'article',
        'thephp.foundation' => '.leading-8',
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Links to Process
    |--------------------------------------------------------------------------
    |
    | Maximum number of links to extract and process from a single page.
    | Default: 5
    |
    */
    'max_links' => env('RELEASE_MAX_LINKS', 20),

    /*
    |--------------------------------------------------------------------------
    | Link Offset
    |--------------------------------------------------------------------------
    |
    | Number of links to skip before starting extraction.
    | Default: 2
    |
    */
    'offset' => env('RELEASE_OFFSET', 0),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for HTTP requests when fetching content.
    | Default: 30
    |
    */
    'timeout' => env('RELEASE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | User Agent
    |--------------------------------------------------------------------------
    |
    | User agent string to use when making HTTP requests.
    |
    */
    'user_agent' => env('RELEASE_USER_AGENT', 'Mozilla/5.0 (compatible; ReleaseParser/1.0)'),

    /*
    |--------------------------------------------------------------------------
    | Enable Job Dispatch
    |--------------------------------------------------------------------------
    |
    | Whether to automatically dispatch jobs for extracted links.
    | Set to false to disable automatic job creation.
    |
    */
    'enable_job_dispatch' => env('RELEASE_ENABLE_JOB_DISPATCH', true),

    /*
    |--------------------------------------------------------------------------
    | Allowed Domains
    |--------------------------------------------------------------------------
    |
    | Array of allowed domains for URL extraction.
    | Leave empty to allow all domains.
    |
    */
    'allowed_domains' => env('RELEASE_ALLOWED_DOMAINS')
        ? explode(',', env('RELEASE_ALLOWED_DOMAINS'))
        : [],

    /*
    |--------------------------------------------------------------------------
    | Blocked Domains
    |--------------------------------------------------------------------------
    |
    | Array of blocked domains to exclude from URL extraction.
    |
    */
    'blocked_domains' => env('RELEASE_BLOCKED_DOMAINS')
        ? explode(',', env('RELEASE_BLOCKED_DOMAINS'))
        : [
            'facebook.com',
            'twitter.com',
            'instagram.com',
            'linkedin.com',
        ],

    /*
    |--------------------------------------------------------------------------
    | Section Headings Filter
    |--------------------------------------------------------------------------
    |
    | When set, only links from td.bodyContent blocks containing an h2
    | with matching text will be extracted.
    | Example: RELEASE_SECTION_HEADINGS="Articles,Tutorials and Talks"
    | Leave empty to extract from all td.bodyContent blocks.
    |
    */
    'section_headings' => env('RELEASE_SECTION_HEADINGS')
        ? explode(',', env('RELEASE_SECTION_HEADINGS'))
        : [],
];
