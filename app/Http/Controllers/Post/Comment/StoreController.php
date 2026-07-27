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
