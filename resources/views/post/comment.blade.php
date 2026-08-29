{{-- Один комментарий в списке на странице поста. $isReply=true — ответ:
     кнопки «Ответить» у него нет, вложенность ограничена одним уровнем
     (так же, как валидация parent_id в Post\Comment\StoreRequest). --}}
<div class="comment-item" data-aos="fade-up">
    <div class="comment-avatar">{{ mb_strtoupper(mb_substr($comment->authorName(), 0, 1)) }}</div>
    <div class="comment-body">
        <div class="comment-item-header">
            <span class="comment-author">{{ $comment->authorName() }}</span>
            <span class="comment-date">{{ $comment->created_at->translatedFormat('d F Y, H:i') }}</span>
        </div>
        <p class="comment-message">{{ $comment->message }}</p>
        @unless($isReply ?? false)
            <button type="button" class="comment-reply-btn"
                    data-reply-id="{{ $comment->id }}" data-reply-author="{{ $comment->authorName() }}">Ответить</button>
        @endunless
    </div>
</div>
