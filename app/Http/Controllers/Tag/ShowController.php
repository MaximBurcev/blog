<?php

namespace App\Http\Controllers\Tag;

use App\Http\Controllers\Controller;
use App\Models\Tag;

class ShowController extends Controller
{
    public function __invoke(string $code)
    {
        $tag = Tag::where('code', $code)->firstOrFail();
        $posts = $tag->posts()->published()->paginate(6);
        $title = 'Посты с тегом '.$tag->title;
        // Развёрнутое описание по той же причине, что и у категорий: короткая
        // строка вида «Посты с тегом X» не проходит порог Вебмастера.
        $description = 'Статьи и переводы блога по теме «'.$tag->title.'»: все материалы, отмеченные этим тегом.';
        $canonical = $posts->currentPage() > 1
            ? $posts->url($posts->currentPage())
            : url()->current();

        return view('tags.show', compact('posts', 'tag', 'title', 'description', 'canonical'));
    }
}
