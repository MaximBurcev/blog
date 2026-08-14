<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Исходный текст статьи лежит в posts.content_orig с 22.02.2026 и до сих пор
 * не показывался нигде: читатель не знал даже, что перед ним машинный
 * перевод. Здесь — плашка о переводе и страница оригинала (?lang=en).
 */
class PostOriginalTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_translation_notice_names_the_source_and_offers_the_original(): void
    {
        $post = $this->createPost();

        $response = $this->get($post->permalink());

        $response->assertSee('Машинный перевод статьи');
        $response->assertSee('dev.to');
        $response->assertSee($post->originalPermalink(), escape: false);
    }

    public function test_incomplete_translation_is_called_out(): void
    {
        $post = $this->createPost(['translation_incomplete' => true]);

        $this->get($post->permalink())
            ->assertSee('Часть блоков осталась без перевода')
            // Именно значение атрибута class: само имя класса есть и в
            // стилях страницы, так что поиск по подстроке проходил бы всегда.
            ->assertSee('class="post-translation-notice post-translation-notice-warning"', escape: false);
    }

    public function test_post_written_by_hand_has_no_notice(): void
    {
        // Ни источника, ни оригинала — плашке неоткуда взяться, иначе она
        // висела бы на собственных статьях блога.
        $post = $this->createPost(['url' => null, 'content_orig' => null]);

        $this->get($post->permalink())
            ->assertDontSee('Машинный перевод')
            ->assertDontSee('Показать оригинал');
    }

    public function test_original_page_shows_source_text_marked_as_english(): void
    {
        $post = $this->createPost();

        $response = $this->get($post->originalPermalink());

        $response->assertOk();
        $response->assertSee('Original English body');
        $response->assertDontSee('Русский перевод статьи');
        // lang на секции, а не на <html>: обвязка страницы остаётся русской.
        $response->assertSee('class="post-content" lang="en"', escape: false);
        $response->assertSee('Вернуться к переводу');
    }

    public function test_original_page_is_kept_out_of_the_index(): void
    {
        $post = $this->createPost();

        $response = $this->get($post->originalPermalink());

        // Англоязычная копия чужой статьи в выдаче не нужна.
        $response->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
        // canonical самоссылочный: пара «noindex + canonical на другой адрес»
        // противоречива, и Google может перенести noindex на цель — то есть
        // выбить из выдачи сам перевод.
        $response->assertSee('<link rel="canonical" href="'.$post->originalPermalink().'">', escape: false);
        // Разметка BlogPosting описывает материал по адресу перевода — на
        // копии её быть не должно, иначе два адреса с одним @id.
        $response->assertDontSee('BlogPosting');
    }

    public function test_translation_page_stays_indexable(): void
    {
        $post = $this->createPost();

        $this->get($post->permalink())
            ->assertDontSee('noindex', escape: false)
            ->assertSee('BlogPosting');
    }

    public function test_original_is_redirected_to_translation_when_there_is_none(): void
    {
        // Пост без content_orig не должен отвечать по двум адресам: это дубль
        // ради дубля. Но редирект временный — оригинал появится, если
        // заглушку разберут повторно, а 301 браузер закэширует навсегда.
        $post = $this->createPost(['content_orig' => null]);

        $this->get($post->permalink().'?lang=en')
            ->assertRedirect($post->permalink())
            ->assertStatus(302);
    }

    /**
     * Мутатор content_orig появился на пять месяцев позже самой колонки, так
     * что в БД лежат записи с сырым HTML страницы-источника — на проде у двух
     * уцелел <iframe>. Санитайзинг на выводе не даёт им исполниться, даже если
     * posts:resanitize где-то не прогоняли.
     */
    public function test_unsanitized_original_is_cleaned_on_render(): void
    {
        $post = $this->createPost();
        // Пишем мимо мутатора — ровно так эти записи и попали в базу.
        Post::withoutSyncingToSearch(fn () => \DB::table('posts')->where('id', $post->id)->update([
            'content_orig' => '<p>Safe text.</p><script>alert(1)</script><iframe src="https://evil.test"></iframe>',
        ]));

        $response = $this->get($post->fresh()->originalPermalink());

        $response->assertOk();
        $response->assertSee('Safe text.');
        $response->assertDontSee('<script>alert(1)</script>', escape: false);
        $response->assertDontSee('<iframe', escape: false);
    }

    /**
     * Картинки в оригинале ведут на CDN первоисточника: ContentImageService
     * локализует их только для перевода. Показывать их значит отправлять IP
     * читателя на medium/dev.to при каждом открытии — и ловить 403 хотлинка.
     */
    public function test_remote_images_are_dropped_from_the_original(): void
    {
        $post = $this->createPost([
            'content_orig' => '<p>Text</p><img src="https://cdn-images-1.medium.com/pic.png" alt="remote">',
        ]);

        $this->get($post->originalPermalink())
            ->assertOk()
            ->assertSee('Text')
            ->assertDontSee('cdn-images-1.medium.com');
    }

    public function test_incomplete_translation_is_not_flagged_on_the_original(): void
    {
        // Предупреждение относится к переводу; на английском тексте ему нечего
        // сказать.
        $post = $this->createPost(['translation_incomplete' => true]);

        $this->get($post->originalPermalink())
            ->assertDontSee('class="post-translation-notice post-translation-notice-warning"', escape: false)
            ->assertDontSee('Часть блоков осталась без перевода');
    }

    public function test_news_original_keeps_its_own_address(): void
    {
        $news = $this->createPost(['is_news' => true, 'code' => 'news-with-original']);

        $response = $this->get($news->originalPermalink());

        $response->assertOk();
        // Адрес новости, а не статьи: permalink() учитывает is_news, и
        // canonical с «Вернуться к переводу» обязаны вести туда же.
        $response->assertSee(route('news.show', 'news-with-original'), escape: false);
        $response->assertDontSee(route('post.show', 'news-with-original'), escape: false);
    }

    public function test_original_page_describes_the_english_text(): void
    {
        $post = $this->createPost([
            'content' => '<p>Русский текст статьи про очереди.</p>',
            'content_orig' => '<p>English text about queues.</p>',
        ]);

        $response = $this->get($post->originalPermalink());

        // og:description уезжает в мессенджеры вместе со ссылкой: описывать
        // английскую страницу русским пересказом незачем.
        $response->assertSee('English text about queues', escape: false);
        $response->assertSee('— оригинал', escape: false);
    }

    public function test_stub_post_edited_by_hand_is_not_called_a_translation(): void
    {
        // Парсер не осилил статью, админ вписал текст руками — url от заглушки
        // остался. Называть такой текст машинным переводом значит соврать.
        $post = $this->createPost([
            'content_orig' => null,
            'parse_status' => Post::PARSE_STATUS_FAILED,
        ]);

        $this->get($post->permalink())
            ->assertDontSee('Машинный перевод');
    }

    public function test_javascript_scheme_in_source_url_is_not_rendered_as_a_link(): void
    {
        // Гард переехал из шаблона и Filament в Post::sourceUrl() — это
        // единственная его проверка.
        $post = $this->createPost(['url' => 'javascript:alert(1)']);

        $this->assertNull($post->sourceUrl());
        $this->assertNull($post->sourceHost());

        $this->get($post->permalink())
            ->assertDontSee('javascript:alert(1)', escape: false);
    }

    public function test_unknown_lang_value_shows_the_translation(): void
    {
        $post = $this->createPost();

        $this->get($post->permalink().'?lang=de')
            ->assertOk()
            ->assertSee('Русский перевод статьи')
            ->assertDontSee('Original English body');
    }

    public function test_lang_survives_the_redirect_between_news_and_posts(): void
    {
        // Новость открыли по адресу статьи: 301 на правильный адрес обязан
        // сохранить ?lang=en, иначе переключатель роняет читателя в перевод.
        $news = $this->createPost(['is_news' => true, 'code' => 'news-post']);

        $this->get(route('post.show', 'news-post').'?lang=en')
            ->assertRedirect(route('news.show', 'news-post').'?lang=en')
            ->assertStatus(301);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPost(array $attributes = []): Post
    {
        return Post::withoutSyncingToSearch(function () use ($attributes): Post {
            $category = Category::firstOrCreate(['code' => 'php'], ['title' => 'PHP']);

            return Post::create(array_merge([
                'title' => 'Заголовок статьи',
                'code' => 'translated-post',
                'content' => '<p>Русский перевод статьи.</p>',
                'content_orig' => '<p>Original English body.</p>',
                'url' => 'https://dev.to/some/article',
                'published' => 1,
                'category_id' => $category->id,
            ], $attributes));
        });
    }
}
