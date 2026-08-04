<?php

namespace Tests\Feature;

use App\DataTransferObjects\PostData;
use App\Enums\UserRole;
use App\Events\PostCreated;
use App\Filament\Resources\PostResource\Pages\EditPost;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Service\PostService;
use App\Support\PostCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01: code — часть публичного адреса статьи, но
 * правило его генерации было размазано по коду и расходилось. PostService
 * безусловно пересчитывал code из заголовка (введённый в форме slug не значил
 * ничего), форма Filament транслитерировала кириллицу другой таблицей, чем
 * сервис, а правка заголовка у существующего поста молча уводила статью на
 * новый адрес — старый начинал отдавать 404.
 */
class PostCodeStabilityTest extends TestCase
{
    use RefreshDatabase;

    private function category(): Category
    {
        return Category::create(['title' => 'Laravel', 'code' => 'laravel']);
    }

    public function test_transliteration_uses_russian_table(): void
    {
        // Без локали 'ru' Str::slug даёт cikl-zizni — другой адрес.
        $this->assertSame('tsikl-zhizni-zaprosa', PostCode::fromTitle('Цикл жизни запроса'));
    }

    public function test_generated_code_is_never_empty(): void
    {
        // Пост от неудавшегося парсинга может остаться без заголовка,
        // а пустой code сломал бы маршрут.
        $this->assertStringStartsWith('post-', PostCode::fromTitle(''));
        $this->assertStringStartsWith('post-', PostCode::fromTitle(null));
    }

    public function test_manually_entered_slug_is_respected_on_create(): void
    {
        Event::fake([PostCreated::class]);

        $post = Post::withoutSyncingToSearch(fn () => app(PostService::class)->store(PostData::fromArray([
            'title' => 'Заголовок статьи',
            'code' => 'moy-svoy-adres',
            'content' => 'текст',
            'category_id' => $this->category()->id,
        ])));

        $this->assertSame('moy-svoy-adres', $post->code);
    }

    public function test_manually_entered_slug_is_normalized(): void
    {
        Event::fake([PostCreated::class]);

        $post = Post::withoutSyncingToSearch(fn () => app(PostService::class)->store(PostData::fromArray([
            'title' => 'Заголовок',
            'code' => 'Мой Адрес Статьи',
            'content' => 'текст',
            'category_id' => $this->category()->id,
        ])));

        $this->assertSame('moy-adres-stati', $post->code);
    }

    public function test_code_is_generated_from_title_when_not_provided(): void
    {
        Event::fake([PostCreated::class]);

        $post = Post::withoutSyncingToSearch(fn () => app(PostService::class)->store(PostData::fromArray([
            'title' => 'Чистая архитектура',
            'content' => 'текст',
            'category_id' => $this->category()->id,
        ])));

        $this->assertSame('chistaya-arhitektura', $post->code);
    }

    public function test_editing_title_in_filament_does_not_change_public_url(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = $this->category();

        $post = Post::withoutSyncingToSearch(fn () => Post::create([
            'title' => 'Старый заголовок',
            'code' => 'staryy-zagolovok',
            'content' => 'текст',
            'category_id' => $category->id,
            'published' => true,
        ]));

        Post::withoutSyncingToSearch(function () use ($admin, $post) {
            Livewire::actingAs($admin)
                ->test(EditPost::class, ['record' => $post->getKey()])
                ->fillForm(['title' => 'Совершенно другой заголовок'])
                ->call('save')
                ->assertHasNoFormErrors();
        });

        $post->refresh();

        $this->assertSame('Совершенно другой заголовок', $post->title);
        $this->assertSame('staryy-zagolovok', $post->code, 'Адрес опубликованной статьи менять нельзя');
    }
}
