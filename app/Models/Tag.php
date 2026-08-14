<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tag extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'tags';

    protected $fillable = ['title', 'code'];

    public function posts()
    {
        return $this->belongsToMany(Post::class, 'post_tags', 'tag_id', 'post_id');
    }

    /**
     * См. Category::scopeHasPublishedPosts(). У тегов пустых заметно больше:
     * их проставляет TagDetectorService прямо при парсинге, то есть задолго до
     * того, как пост опубликуют — а публикуют далеко не каждый.
     */
    public function scopeHasPublishedPosts(Builder $query): Builder
    {
        return $query->whereHas('posts', fn (Builder $q) => $q->published());
    }
}
