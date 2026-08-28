<?php

namespace App\Http\Controllers\Tag;

use App\Http\Controllers\Controller;
use App\Models\Tag;

class ShowController extends Controller
{
    public function __invoke(string $code)
    {
        $tag = Tag::where('code', $code)->firstOrFail();
        // См. Category\ShowController: узкий select() под карточку (иначе
        // тянутся оба LONGTEXT) и явная сортировка под пагинацию.
        // Имена колонок квалифицированы: это выборка через pivot post_tags,
        // и голый 'id' в select неоднозначен.
        $posts = $tag->posts()->published()
            ->select(['posts.id', 'posts.title', 'posts.code', 'posts.preview_image', 'posts.category_id', 'posts.is_news', 'posts.created_at'])
            ->latest('created_at')
            ->paginate(6);
        $title = 'Посты с тегом '.$tag->title;
        // Развёрнутое описание по той же причине, что и у категорий: короткая
        // строка вида «Посты с тегом X» не проходит порог Вебмастера.
        $description = 'Статьи и переводы блога по теме «'.$tag->title.'»: все материалы, отмеченные этим тегом.';
        $canonical = $posts->currentPage() > 1
            ? $posts->url($posts->currentPage())
            : url()->current();
        // См. Category\ShowController: пустой список закрываем от индексации,
        // а не отдаём 404 — теги наполняются по мере публикации постов.
        // isEmpty() ловит и ?page=N за пределами пагинации.
        $robots = $posts->isEmpty() ? 'noindex, follow' : null;

        return view('tags.show', compact('posts', 'tag', 'title', 'description', 'canonical', 'robots'));
    }
}
