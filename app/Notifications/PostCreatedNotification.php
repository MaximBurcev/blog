<?php

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PostCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(public Post $post) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/filament/posts/' . $this->post->id . '/edit');

        return (new MailMessage)
            ->subject('Новый пост: ' . $this->post->title)
            ->line('Создан новый пост.')
            ->line('**' . $this->post->title . '**')
            ->action('Открыть в админке', $url)
            ->line('Категория: ' . ($this->post->category->title ?? '—'));
    }
}
