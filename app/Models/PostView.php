<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostView extends Model
{
    // Фиксируем момент просмотра явным полем viewed_at, отдельные
    // created_at/updated_at здесь не нужны.
    public $timestamps = false;

    protected $fillable = [
        'post_id',
        'ip_hash',
        'session_hash',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
