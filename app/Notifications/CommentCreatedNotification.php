<?php

namespace App\Notifications;

use App\Filament\Resources\CommentResource;
use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommentCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(public Comment $comment) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // См. PostCreatedNotification: адрес строит Filament, путь панели
        // настраивается через config/admin.php.
        $url = CommentResource::getUrl('edit', ['record' => $this->comment]);

        return (new MailMessage)
            ->subject('Новый комментарий к посту: '.$this->comment->post->title)
            ->line('Оставлен новый комментарий, ожидает модерации (не опубликован).')
            ->line('Пост: **'.$this->comment->post->title.'**')
            ->line('Автор: '.$this->comment->authorName())
            ->line('Текст: '.$this->comment->message)
            ->action('Открыть в модерации', $url);
    }
}
