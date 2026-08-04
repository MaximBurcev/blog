<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Models\Category;

class ShowController extends Controller
{
    public function __invoke(string $code)
    {
        $category = Category::where('code', $code)->firstOrFail();
        $posts = $category->posts()->published()->paginate(6);
        $title = 'Посты категории '.$category->title;
        // Не «Посты в категории X» в три слова: описание короче полусотни
        // символов Вебмастер считает незаполненным и всё равно собирает
        // сниппет из текста страницы.
        $description = 'Статьи и переводы блога в категории «'.$category->title.'»: подборка материалов раздела о веб-разработке.';
        $canonical = $posts->currentPage() > 1
            ? $posts->url($posts->currentPage())
            : url()->current();

        return view('categories.show', compact('posts', 'category', 'title', 'description', 'canonical'));
    }
}
