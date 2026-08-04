<?php

namespace App\Http\Controllers\Post\Comment;

use App\Events\CommentCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\Comment\StoreRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;

class StoreController extends Controller
{
    public function __invoke(Post $post, StoreRequest $request): RedirectResponse
    {
        // Неявный биндинг резолвит пост по id без scopePublished, тогда как
        // Post\ShowController отдаёт 404 на неопубликованный. Без этой проверки
        // перебор id подтверждал существование ещё не вышедшей статьи (200 vs
        // 404) и позволял оставить к ней комментарий до публикации.
        abort_unless($post->published, 404);

        $data = $request->validated();

        $redirect = redirect(route('post.show', $post->code).'#comments')
            ->with('success', 'Комментарий отправлен на модерацию и появится на странице после проверки');

        // Honeypot: поле невидимо для человека, боты его заполняют.
        // Молча делаем вид, что всё прошло успешно, ничего не создавая —
        // так скрипт не понимает, что был отсеян, и не пытается обойти проверку.
        if (filled($data['website'] ?? null)) {
            return $redirect;
        }

        $comment = $post->comments()->create([
            'user_id' => auth()->id(),
            'guest_name' => auth()->check() ? null : $data['name'],
            'message' => $data['message'],
            'published' => false,
        ]);

        CommentCreated::dispatch($comment);

        return $redirect;
    }
}
