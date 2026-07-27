<?php

namespace Tests\Feature;

use App\Jobs\StorePostJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Регрессия на architecture-audit-2026-07-24 H5: PostService::store()
 * делает updateOrCreate() по url, но без distributed lock две параллельные
 * джобы по одному url (два воркера, либо повторный dispatch до коммита
 * первой) всё ещё могли одновременно пройти проверку и создать дубликат —
 * updateOrCreate не атомарен без UNIQUE-индекса в БД, а его пока нет.
 * ShouldBeUnique закрывает это через Cache::lock под капотом Laravel.
 */
class StorePostJobUniquenessTest extends TestCase
{
    public function test_duplicate_dispatch_for_same_url_is_suppressed(): void
    {
        Queue::fake();

        StorePostJob::dispatch(['url' => 'https://example.test/same-article']);
        StorePostJob::dispatch(['url' => 'https://example.test/same-article']);

        Queue::assertPushed(StorePostJob::class, 1);
    }

    public function test_dispatch_for_different_urls_is_not_suppressed(): void
    {
        Queue::fake();

        StorePostJob::dispatch(['url' => 'https://example.test/article-one']);
        StorePostJob::dispatch(['url' => 'https://example.test/article-two']);

        Queue::assertPushed(StorePostJob::class, 2);
    }

    public function test_jobs_without_url_are_deduplicated_by_html_file(): void
    {
        Queue::fake();

        StorePostJob::dispatch(['url' => '', 'html_file' => '/tmp/one.html']);
        StorePostJob::dispatch(['url' => '', 'html_file' => '/tmp/one.html']);
        StorePostJob::dispatch(['url' => '', 'html_file' => '/tmp/two.html']);

        Queue::assertPushed(StorePostJob::class, 2);
    }
}
