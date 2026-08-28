<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Models\Category;

class ShowController extends Controller
{
    public function __invoke(string $code)
    {
        $category = Category::where('code', $code)->firstOrFail();
        // select() под карточку листинга, как на главной: без него на каждый
        // пост тянутся оба LONGTEXT-поля (content/content_orig), хотя
        // карточке нужны только заголовок, адрес и превью. is_news
        // обязателен: на нём стоит Post::permalink(), а невыбранная колонка
        // молча читается как null — и новости получили бы адрес статьи (301
        // вместо ссылки). Сортировка обязательна: пагинация без orderBy не
        // гарантирует, что ?page=2 не повторит посты с первой страницы.
        $posts = $category->posts()->published()
            ->select(['posts.id', 'posts.title', 'posts.code', 'posts.preview_image', 'posts.category_id', 'posts.is_news', 'posts.created_at'])
            ->latest('created_at')
            ->paginate(6);
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
