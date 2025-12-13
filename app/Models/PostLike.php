<?php

// app/Models/PostLike.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostLike extends Model
{
    protected $table = 'post_likes';

    public $timestamps = true; // если в миграции есть created_at/updated_at

    protected $fillable = ['post_id', 'user_id'];

    // Опционально: связи
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
