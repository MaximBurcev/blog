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
        // Страница с пустым списком. Ссылок на неё больше нет (листинги и обе
        // карты сайта фильтруют по published), но уже проиндексированные
        // адреса сами из выдачи не уйдут. Не 404: посты в разделе могут
        // появиться в любой момент, а отданная единожды 404 выбьет адрес
        // насовсем. follow — чтобы пагинация и меню остались проходимыми.
        //
        // isEmpty(), а не total() === 0: у total() пустыми считаются только
        // разделы без постов вообще, а ?page=99 наполненной категории — это
        // тоже нулевой список, причём канонический сам на себя (canonical выше
        // подставляет текущую страницу). Именно такие адреса Вебмастер и
        // собирает: пагинация в разметке есть, значит он по ним пройдёт.
        $robots = $posts->isEmpty() ? 'noindex, follow' : null;

        return view('categories.show', compact('posts', 'category', 'title', 'description', 'canonical', 'robots'));
    }
}
