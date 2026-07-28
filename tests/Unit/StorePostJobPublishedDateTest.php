<?php

namespace Tests\Unit;

use App\Jobs\StorePostJob;
use DOMDocument;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * created_at поста должен отражать дату публикации оригинальной статьи у
 * источника, а не момент, когда её затащил скрейпер (иначе дата на
 * публичной странице/RSS не соответствует реальной дате публикации).
 */
class StorePostJobPublishedDateTest extends TestCase
{
    private function loadHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<html><head>'.$html.'</head><body></body></html>');

        return $dom;
    }

    private function extractRaw(string $html): ?string
    {
        $method = new ReflectionMethod(StorePostJob::class, 'extractPublishedDateRaw');
        $method->setAccessible(true);

        return $method->invoke(new StorePostJob([]), $this->loadHtml($html));
    }

    public function test_extracts_date_from_json_ld_single_object(): void
    {
        $html = '<script type="application/ld+json">{"@type":"BlogPosting","datePublished":"2026-07-09T10:00:00.000Z"}</script>';

        $this->assertSame('2026-07-09T10:00:00.000Z', $this->extractRaw($html));
    }

    public function test_extracts_date_from_json_ld_graph(): void
    {
        $html = '<script type="application/ld+json">'.
            '{"@graph":[{"@type":"WebPage"},{"@type":"Article","datePublished":"2026-05-01T08:00:00Z"}]}'.
            '</script>';

        $this->assertSame('2026-05-01T08:00:00Z', $this->extractRaw($html));
    }

    public function test_extracts_date_from_json_ld_list_of_objects(): void
    {
        $html = '<script type="application/ld+json">'.
            '[{"@type":"BreadcrumbList"},{"@type":"Article","datePublished":"2026-03-03T00:00:00Z"}]'.
            '</script>';

        $this->assertSame('2026-03-03T00:00:00Z', $this->extractRaw($html));
    }

    public function test_falls_back_to_article_published_time_meta(): void
    {
        $html = '<meta property="article:published_time" content="2026-06-15T12:00:00+00:00">';

        $this->assertSame('2026-06-15T12:00:00+00:00', $this->extractRaw($html));
    }

    public function test_falls_back_to_itemprop_date_published_meta(): void
    {
        $html = '<meta itemprop="datePublished" content="2026-02-20">';

        $this->assertSame('2026-02-20', $this->extractRaw($html));
    }

    public function test_falls_back_to_name_date_meta(): void
    {
        $html = '<meta name="date" content="2026-01-10">';

        $this->assertSame('2026-01-10', $this->extractRaw($html));
    }

    public function test_falls_back_to_time_datetime_element(): void
    {
        $html = '<time datetime="2025-12-25T00:00:00Z">25 декабря</time>';

        $this->assertSame('2025-12-25T00:00:00Z', $this->extractRaw($html));
    }

    public function test_json_ld_takes_priority_over_meta_tags(): void
    {
        $html = '<meta property="article:published_time" content="2020-01-01T00:00:00Z">'.
            '<script type="application/ld+json">{"datePublished":"2026-07-09T10:00:00Z"}</script>';

        $this->assertSame('2026-07-09T10:00:00Z', $this->extractRaw($html));
    }

    public function test_returns_null_when_no_date_source_present(): void
    {
        $this->assertNull($this->extractRaw('<meta property="og:title" content="whatever">'));
    }

    public function test_apply_published_date_sets_created_at_on_job_data(): void
    {
        $job = new StorePostJob(['url' => 'https://example.test/article']);
        $dom = $this->loadHtml('<script type="application/ld+json">{"datePublished":"2026-07-09T10:00:00Z"}</script>');

        $method = new ReflectionMethod(StorePostJob::class, 'applyPublishedDate');
        $method->setAccessible(true);
        $method->invoke($job, $dom);

        $data = new ReflectionProperty(StorePostJob::class, 'data');
        $data->setAccessible(true);

        $this->assertSame('2026-07-09 10:00:00', $data->getValue($job)['created_at']);
    }

    public function test_apply_published_date_ignores_future_date(): void
    {
        $job = new StorePostJob(['url' => 'https://example.test/article']);
        $future = now()->addYear()->toIso8601String();
        $dom = $this->loadHtml('<script type="application/ld+json">{"datePublished":"'.$future.'"}</script>');

        $method = new ReflectionMethod(StorePostJob::class, 'applyPublishedDate');
        $method->setAccessible(true);
        $method->invoke($job, $dom);

        $data = new ReflectionProperty(StorePostJob::class, 'data');
        $data->setAccessible(true);

        $this->assertArrayNotHasKey('created_at', $data->getValue($job));
    }

    public function test_apply_published_date_ignores_garbage_value(): void
    {
        $job = new StorePostJob(['url' => 'https://example.test/article']);
        $dom = $this->loadHtml('<meta property="article:published_time" content="not-a-date">');

        $method = new ReflectionMethod(StorePostJob::class, 'applyPublishedDate');
        $method->setAccessible(true);
        $method->invoke($job, $dom);

        $data = new ReflectionProperty(StorePostJob::class, 'data');
        $data->setAccessible(true);

        $this->assertArrayNotHasKey('created_at', $data->getValue($job));
    }
}
