<?php

namespace Tests\Feature;

use App\Jobs\ImportToolsJob;
use App\Service\ReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReleaseToolsAutoImportTest extends TestCase
{
    use RefreshDatabase;

    private const DIGEST = 'https://example.test/digest';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('releases.section_headings', []);
        Config::set('releases.offset', 0);
        Config::set('releases.max_links', 20);
        Config::set('releases.enable_job_dispatch', true);
    }

    private function service(string $html): ReleaseService
    {
        return new class($html) extends ReleaseService
        {
            public function __construct(private readonly string $html)
            {
                parent::__construct();
            }

            public function fetchHtmlContent(string $url): string
            {
                return $this->html;
            }
        };
    }

    private function digestWithArticle(): string
    {
        return '<html><body><table><tr><td class="bodyContent">'
            .'<a href="https://dev.to/author/some-article">Статья выпуска</a>'
            .'</td></tr></table></body></html>';
    }

    public function test_parsing_a_release_also_queues_the_tools_import(): void
    {
        Queue::fake();

        $links = $this->service($this->digestWithArticle())->addPosts(self::DIGEST);

        $this->assertNotEmpty($links, 'фикстура обязана давать статью, иначе тест проходит вхолостую');
        Queue::assertPushed(ImportToolsJob::class);
    }

    public function test_tools_import_is_queued_even_when_no_articles_were_found(): void
    {
        Queue::fake();

        $links = $this->service('<html><body><p>Ни одной ссылки</p></body></html>')->addPosts(self::DIGEST);

        $this->assertSame([], $links);
        Queue::assertPushed(ImportToolsJob::class);
    }

    public function test_a_failing_tools_dispatch_does_not_stop_the_article_parsing(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('очередь недоступна'));

        $links = $this->service($this->digestWithArticle())->addPosts(self::DIGEST);

        $this->assertNotEmpty($links);
    }

    public function test_disabling_job_dispatch_stops_the_tools_import_too(): void
    {
        Config::set('releases.enable_job_dispatch', false);

        Queue::fake();

        $this->service($this->digestWithArticle())->addPosts(self::DIGEST);

        Queue::assertNotPushed(ImportToolsJob::class);
    }
}
