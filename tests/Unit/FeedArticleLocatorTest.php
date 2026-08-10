<?php

namespace Tests\Unit;

use App\Support\FeedArticleLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Medium с августа 2026 отдаёт на страницы статей Cloudflare managed
 * challenge (403, «Just a moment...»), недостижимый для curl, но RSS
 * остаётся открытым и содержит полный content:encoded. Класс достаёт
 * статью оттуда; тесты фиксируют формы URL и разбор фида.
 */
class FeedArticleLocatorTest extends TestCase
{
    private function feed(string ...$items): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'.
            '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">'.
            '<channel><title>Feed</title>'.implode('', $items).'</channel></rss>';
    }

    private function item(string $link, string $title, string $body, string $pubDate = 'Sat, 09 Aug 2026 10:00:00 GMT'): string
    {
        return '<item>'.
            '<title><![CDATA['.$title.']]></title>'.
            '<link>'.$link.'</link>'.
            '<pubDate>'.$pubDate.'</pubDate>'.
            '<content:encoded><![CDATA['.$body.']]></content:encoded>'.
            '</item>';
    }

    public static function feedUrls(): array
    {
        return [
            'автор' => [
                'https://medium.com/@bikkisingh/the-array-bug-1ca68f019664',
                'https://medium.com/feed/@bikkisingh',
            ],
            'публикация' => [
                'https://medium.com/javascript-in-plain-english/some-post-abc123def456',
                'https://medium.com/feed/javascript-in-plain-english',
            ],
            'кастомный домен' => [
                'https://blog.stackademic.com/some-post-abc123def456',
                'https://blog.stackademic.com/feed',
            ],
            // Поддомен medium: автор закодирован в хосте, путь из одного
            // сегмента. Раньше тут возвращался null, и RSS не пробовался.
            'поддомен medium' => [
                'https://ecnmee.medium.com/the-4-memory-layers-7b5264ac78bd',
                'https://ecnmee.medium.com/feed',
            ],
        ];
    }

    #[DataProvider('feedUrls')]
    public function test_derives_feed_url(string $article, string $expected): void
    {
        $this->assertSame($expected, FeedArticleLocator::feedUrlFor($article));
    }

    public function test_refuses_non_http_scheme(): void
    {
        $this->assertNull(FeedArticleLocator::feedUrlFor('javascript:alert(1)'));
        $this->assertNull(FeedArticleLocator::feedUrlFor('mailto:a@b.c'));
    }

    public function test_medium_root_has_no_article_to_look_for(): void
    {
        $this->assertNull(FeedArticleLocator::feedUrlFor('https://medium.com/@bikkisingh'));
    }

    public function test_finds_article_by_exact_link(): void
    {
        $xml = $this->feed(
            $this->item('https://medium.com/@a/other-999999999999', 'Другая', '<p>нет</p>'),
            $this->item('https://medium.com/@a/target-1ca68f019664', 'Нужная', '<p>Текст статьи</p>'),
        );

        $found = FeedArticleLocator::findArticle($xml, 'https://medium.com/@a/target-1ca68f019664');

        $this->assertNotNull($found);
        $this->assertSame('Нужная', $found['title']);
        $this->assertSame('<p>Текст статьи</p>', $found['html']);
        $this->assertNotNull($found['published_at']);
    }

    /**
     * У ссылки из дайджеста бывают utm-метки и ?source=rss, а слаг в фиде
     * может отличаться — сопоставление идёт и по hex-id статьи.
     */
    public function test_finds_article_ignoring_query_and_slug_difference(): void
    {
        $xml = $this->feed($this->item('https://medium.com/@a/other-slug-1ca68f019664', 'Нужная', '<p>Текст</p>'));

        $found = FeedArticleLocator::findArticle(
            $xml,
            'https://medium.com/@a/the-array-bug-1ca68f019664?source=rss&utm_medium=email'
        );

        $this->assertNotNull($found);
        $this->assertSame('Нужная', $found['title']);
    }

    public function test_returns_null_when_article_is_not_in_feed(): void
    {
        $xml = $this->feed($this->item('https://medium.com/@a/other-999999999999', 'Другая', '<p>нет</p>'));

        $this->assertNull(FeedArticleLocator::findArticle($xml, 'https://medium.com/@a/target-1ca68f019664'));
    }

    public function test_returns_null_on_broken_or_empty_xml(): void
    {
        $this->assertNull(FeedArticleLocator::findArticle('', 'https://medium.com/@a/x-1ca68f019664'));
        $this->assertNull(FeedArticleLocator::findArticle('<rss><channel>', 'https://medium.com/@a/x-1ca68f019664'));
    }

    public function test_detects_truncated_member_only_content(): void
    {
        $this->assertTrue(FeedArticleLocator::isTruncated(
            '<p>Вступление…</p><a href="https://medium.com/@a/x?source=rss">Continue reading on Medium »</a>'
        ));
        $this->assertFalse(FeedArticleLocator::isTruncated('<p>Полный текст статьи без всяких приглашений.</p>'));
    }

    /**
     * Чужой фид — недоверенный ввод: разбор не должен ходить в сеть за
     * внешними сущностями.
     */
    public function test_external_entities_are_not_resolved(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'.
            '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>'.
            '<item><title>t</title><link>https://medium.com/@a/x-1ca68f019664</link>'.
            '<content:encoded>&xxe;</content:encoded></item></channel></rss>';

        $found = FeedArticleLocator::findArticle($xxe, 'https://medium.com/@a/x-1ca68f019664');

        // Либо разбор провалился, либо тело пустое — но содержимого файла там быть не должно.
        $this->assertTrue($found === null || ! str_contains($found['html'], 'root:'));
    }
}
