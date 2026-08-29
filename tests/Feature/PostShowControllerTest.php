<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostShowControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * published_at не входит в $fillable у Post (дату ставит хук модели),
     * поэтому для тестов с заданной датой публикации колонку обновляем
     * запросом мимо массового присвоения.
     */
    private function createPublishedPost(array $overrides = []): Post
    {
        $post = Post::withoutSyncingToSearch(fn () => Post::create(array_merge([
            'title' => 'Test post '.uniqid(),
            'code' => 'test-post-'.uniqid(),
            'content' => 'content',
            'published' => 1,
        ], $overrides)));

        if (isset($overrides['published_at'])) {
            Post::whereKey($post->id)->update(['published_at' => $overrides['published_at']]);
            $post->refresh();
        }

        return $post;
    }

    public function test_unknown_code_returns_404(): void
    {
        $response = $this->get(route('post.show', 'does-not-exist'));

        $response->assertNotFound();
    }

    public function test_unpublished_post_returns_404(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            Post::create([
                'title' => 'Draft post',
                'code' => 'draft-post',
                'content' => 'content',
                'published' => 0,
                'category_id' => $category->id,
            ]);
        });

        $response = $this->get(route('post.show', 'draft-post'));

        $response->assertNotFound();
    }

    public function test_related_posts_prioritise_shared_tags_then_category(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            $otherCategory = Category::create(['title' => 'Symfony', 'code' => 'symfony']);
            $sharedTag = Tag::create(['title' => 'PHP', 'code' => 'php']);

            $post = Post::create([
                'title' => 'Main post',
                'code' => 'main-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            $post->tags()->attach($sharedTag->id);

            // Общий тег + категория — должен идти первым
            $bestMatch = Post::create([
                'title' => 'Tag and category match',
                'code' => 'tag-and-category-match',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            $bestMatch->tags()->attach($sharedTag->id);

            // Только категория, без общего тега
            Post::create([
                'title' => 'Category only match',
                'code' => 'category-only-match',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);

            // Ни тега, ни категории — не должен попасть в топ
            Post::create([
                'title' => 'No match',
                'code' => 'no-match',
                'content' => 'content',
                'published' => 1,
                'category_id' => $otherCategory->id,
            ]);

            // Неопубликован — не должен попасть вообще, даже с общим тегом
            $unpublished = Post::create([
                'title' => 'Unpublished match',
                'code' => 'unpublished-match',
                'content' => 'content',
                'published' => 0,
                'category_id' => $category->id,
            ]);
            $unpublished->tags()->attach($sharedTag->id);
        });

        $post = Post::where('code', 'main-post')->firstOrFail();
        $related = Post::relatedTo($post)->get();

        $this->assertSame('tag-and-category-match', $related->first()->code);
        $this->assertTrue($related->pluck('code')->contains('category-only-match'));
        $this->assertFalse($related->pluck('code')->contains('unpublished-match'));
    }

    /**
     * Обложка в теле статьи не выводится, но остаётся превью для соцсетей.
     *
     * У переводных статей обложка — это og:image площадки-источника, то есть
     * картинка, состоящая из заголовка статьи, имени автора и логотипа
     * площадки. Под <h1> она давала тот же заголовок второй раз, ещё и
     * по-английски. В превью ссылки такая картинка, наоборот, уместна.
     */
    public function test_cover_is_not_shown_in_the_article_body_but_stays_in_link_preview(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            Post::create([
                'title' => 'Пост с обложкой',
                'code' => 'post-with-cover',
                'content' => '<p>Тело статьи</p>',
                'published' => 1,
                'category_id' => $category->id,
                'main_image' => 'images/content/cover.webp',
                'preview_image' => 'images/content/cover.webp',
            ]);
        });

        $response = $this->get(route('post.show', 'post-with-cover'));

        $response->assertOk();

        [$head, $body] = explode('</head>', $response->getContent(), 2);

        // Именно тег og:image, а не путь где-то в HTML: путь есть ещё и в
        // JSON-LD, поэтому поиск по всей странице пережил бы удаление
        // og:image и ничего не заметил.
        $this->assertStringContainsString(
            '<meta property="og:image" content="'.asset('storage/images/content/cover.webp').'">',
            $head,
        );

        // В теле пути нет вообще — проверка ловит возврат картинки любым
        // способом: с другим классом, без обёртки и через srcset, где варианты
        // строятся от того же имени файла.
        $this->assertStringNotContainsString('images/content/cover.webp', $body);
    }

    /**
     * «Предыдущая/следующая» — соседи по published_at внутри своей ленты:
     * новость, стоящая между статьями по дате, в соседи статьи попадать
     * не должна. Проверяем содержимое самого блока навигации: те же посты
     * могут встречаться и в «Схожих постах», поэтому глобальный поиск по
     * странице ничего бы не доказал.
     */
    public function test_prev_next_links_are_ordered_by_published_at_within_the_same_feed(): void
    {
        $older = $this->createPublishedPost(['published_at' => '2026-08-01 10:00:00']);
        $current = $this->createPublishedPost(['published_at' => '2026-08-10 10:00:00']);
        $newer = $this->createPublishedPost(['published_at' => '2026-08-20 10:00:00']);
        $news = $this->createPublishedPost(['is_news' => true, 'published_at' => '2026-08-15 10:00:00']);

        $response = $this->get(route('post.show', $current->code));

        $response->assertOk();

        $content = $response->getContent();
        $navStart = strpos($content, 'post-neighbors');
        $navEnd = strpos($content, 'comment-section');
        $this->assertNotFalse($navStart);
        $nav = substr($content, $navStart, $navEnd - $navStart);

        $this->assertStringContainsString($older->permalink(), $nav);
        $this->assertStringContainsString($newer->permalink(), $nav);
        $this->assertStringNotContainsString($news->permalink(), $nav);

        // Предыдущая раньше следующей в разметке блока.
        $this->assertLessThan(
            strpos($nav, $newer->permalink()),
            strpos($nav, $older->permalink()),
        );
    }

    public function test_article_without_neighbors_has_no_prev_next_block(): void
    {
        $post = $this->createPublishedPost();

        $response = $this->get(route('post.show', $post->code));

        $response->assertOk();
        $response->assertDontSee('post-neighbors');
    }

    public function test_toc_is_rendered_when_article_has_three_or_more_headings(): void
    {
        $post = $this->createPublishedPost([
            'content' => '<h2>Введение в очереди</h2><p>Текст</p>'
                .'<h2>Настройка воркера</h2><pre>&lt;?php echo 1;</pre>'
                .'<h3>Тонкости конфигурации</h3>',
        ]);

        $response = $this->get(route('post.show', $post->code));

        $response->assertOk();
        $response->assertSee('post-toc');
        $response->assertSee('href="#section-1"', false);
        // Заголовки в тексте получили якоря, кириллица в пунктах сохранилась.
        $response->assertSee('<h2 id="section-1">Введение в очереди</h2>', false);
        $response->assertSee('<h3 id="section-3">Тонкости конфигурации</h3>', false);
    }

    public function test_toc_is_not_rendered_when_fewer_than_three_headings(): void
    {
        $post = $this->createPublishedPost([
            'content' => '<h2>Раз</h2><p>Текст</p><h2>Два</h2>',
        ]);

        $response = $this->get(route('post.show', $post->code));

        $response->assertOk();
        $response->assertDontSee('post-toc');
        // Контент при этом уезжает на страницу без изменений — якоря не нужны.
        $response->assertSee('<h2>Раз</h2>', false);
    }

    public function test_share_links_contain_post_permalink(): void
    {
        $post = $this->createPublishedPost();

        $response = $this->get(route('post.show', $post->code));

        $response->assertOk();
        $response->assertSee('https://t.me/share/url?url='.urlencode($post->permalink()), false);
        $response->assertSee('https://vk.com/share.php?url='.urlencode($post->permalink()), false);
        $response->assertSee('data-url="'.$post->permalink().'"', false);
    }

    /**
     * Пагинация по 20 корневых комментариев: на первой странице — самые
     * свежие, старые уезжают на следующие. created_at разносим явно:
     * созданные в цикле комментарии получают одну и ту же секунду, и
     * сортировка latest() между ними была бы недетерминированной.
     */
    public function test_comments_are_paginated_twenty_per_page(): void
    {
        $post = $this->createPublishedPost();

        // Номера с ведущим нулём: «Комментарий номер 1» — подстрока
        // «Комментарий номер 10», и проверки ниже съедали бы ложное совпадение.
        for ($i = 1; $i <= 21; $i++) {
            $comment = $post->comments()->create([
                'guest_name' => 'Гость',
                'message' => 'Комментарий номер '.sprintf('%02d', $i),
                'published' => true,
            ]);
            Comment::whereKey($comment->id)->update(['created_at' => now()->addMinutes($i)]);
        }

        $firstPage = $this->get(route('post.show', $post->code));

        $firstPage->assertOk();
        $firstPage->assertSee('?page=2');
        $firstPage->assertSee('Комментарий номер 21');
        $firstPage->assertDontSee('Комментарий номер 01');

        $secondPage = $this->get(route('post.show', $post->code).'?page=2');

        $secondPage->assertOk();
        $secondPage->assertSee('Комментарий номер 01');
        $secondPage->assertDontSee('Комментарий номер 21');
        // Страница пагинации — тот же пост: canonical остаётся голым, без query.
        $secondPage->assertSee('<link rel="canonical" href="'.$post->permalink().'">', false);
    }

    public function test_published_replies_are_rendered_under_their_parent(): void
    {
        $post = $this->createPublishedPost();
        $parent = $post->comments()->create([
            'guest_name' => 'Первый',
            'message' => 'Корневой комментарий',
            'published' => true,
        ]);
        $post->comments()->create([
            'guest_name' => 'Второй',
            'parent_id' => $parent->id,
            'message' => 'Опубликованный ответ',
            'published' => true,
        ]);
        $post->comments()->create([
            'guest_name' => 'Третий',
            'parent_id' => $parent->id,
            'message' => 'Ответ на модерации',
            'published' => false,
        ]);

        $response = $this->get(route('post.show', $post->code));

        $response->assertOk();
        $response->assertSeeInOrder(['Корневой комментарий', 'Опубликованный ответ']);
        $response->assertSee('comment-replies');
        $response->assertDontSee('Ответ на модерации');
        // Счётчик в заголовке — все опубликованные сообщения ветки, не только корни.
        $response->assertSee('Комментарии (2)');
    }
}
