<?php

namespace App\Http\Controllers\Post;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Service\PostViewService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ShowController extends Controller
{
    public function __invoke(string $code, Request $request, PostViewService $postViewService)
    {
        $post = Post::where('code', $code)->published()->with(['category', 'tags'])->firstOrFail();

        // Статьи живут на /posts/{code}, новости — на /news/{code}. Один и тот
        // же материал не должен открываться по двум адресам: для поисковика
        // это дубль. Поэтому обращение по «чужому» адресу — 301 на правильный,
        // а не вторая рабочая копия страницы (301, чтобы уже проиндексированные
        // ссылки передали вес новому адресу).
        $expected = $post->is_news ? 'news.show' : 'post.show';

        if ($request->route()?->getName() !== $expected) {
            // Query сохраняем: без него переключение на оригинал с «чужого»
            // адреса возвращало бы читателя в перевод.
            $queryString = $request->getQueryString();

            return redirect()->to(
                route($expected, $post->code).($queryString ? '?'.$queryString : ''),
                301
            );
        }

        // Оригинал показывается по тому же адресу с ?lang=en — материал один и
        // тот же, различается только язык тела статьи.
        $showOriginal = $request->query('lang') === Post::ORIGINAL_LANG;

        // Оригинал запросили там, где его нет: content_orig заполняет только
        // парсер. Уводим на перевод, а не показываем ту же страницу по второму
        // адресу — иначе к каждому посту добавился бы дубль.
        //
        // 302, а не 301: оригинал у поста может появиться позже — при
        // повторном разборе заглушки с parse_status = failed. Постоянный
        // редирект браузер кэширует бессрочно, и для такого читателя ?lang=en
        // навсегда остался бы переводом, минуя сервер.
        if ($showOriginal && ! $post->hasOriginal()) {
            return redirect()->to($post->permalink());
        }

        $postViewService->record($post, $request);

        $date = Carbon::parse($post->created_at);
        $title = $showOriginal ? $post->title.' — оригинал' : $post->title;
        // excerpt() выбрасывает из текста код и таблицы, поэтому для статьи,
        // состоящей из одних листингов, он возвращает пустую строку — без
        // фолбэка в разметку уходило бы content="".
        $description = ($showOriginal ? $post->originalExcerpt() : $post->excerpt())
            ?: 'Статья «'.$post->title.'» в блоге о веб-разработке.';
        $ogImage = $post->main_image ? asset('storage/'.$post->main_image) : null;
        $ogType = 'article';
        // og:type=article требует своих полей: без них Facebook/LinkedIn не
        // показывают дату и раздел материала.
        $articleMeta = [
            'published_time' => $post->created_at?->toIso8601String(),
            'modified_time' => $post->updated_at?->toIso8601String(),
            'section' => $post->category?->title,
            'tags' => $post->tags->pluck('title')->all(),
        ];
        $viewsCount = $post->viewsCount();

        $relatedPosts = Post::relatedTo($post)->get();

        $isLiked = auth()->check() && $post->likes()->where('user_id', auth()->id())->exists();

        $comments = $post->comments()->published()->with('user')->latest()->get();

        // Страница оригинала — англоязычная копия чужой статьи, в поиске ей
        // делать нечего: noindex, follow (по ссылкам краулер пусть идёт).
        //
        // canonical при этом самоссылочный, а не на перевод. Пара «noindex +
        // canonical на другой адрес» — противоречивые указания: Google
        // документированно может перенести noindex на цель канонизации, то
        // есть выбить из выдачи саму статью-перевод. Склейку с переводом
        // делает не canonical, а сам факт того, что версия оригинала
        // неиндексируема и на неё нет внешних ссылок.
        $robots = $showOriginal ? 'noindex, follow' : null;
        $canonical = $showOriginal ? $post->originalPermalink() : $post->permalink();

        return view('post.show',
            compact('post', 'date', 'relatedPosts', 'title', 'description', 'ogImage', 'ogType', 'articleMeta', 'isLiked', 'comments', 'viewsCount', 'showOriginal', 'robots', 'canonical'));
    }
}
