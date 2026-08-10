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
            return redirect()->route($expected, $post->code, 301);
        }

        $postViewService->record($post, $request);

        $date = Carbon::parse($post->created_at);
        $title = $post->title;
        // excerpt() выбрасывает из текста код и таблицы, поэтому для статьи,
        // состоящей из одних листингов, он возвращает пустую строку — без
        // фолбэка в разметку уходило бы content="".
        $description = $post->excerpt() ?: 'Статья «'.$post->title.'» в блоге о веб-разработке.';
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

        return view('post.show',
            compact('post', 'date', 'relatedPosts', 'title', 'description', 'ogImage', 'ogType', 'articleMeta', 'isLiked', 'comments', 'viewsCount'));
    }
}
