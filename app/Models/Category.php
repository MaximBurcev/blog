<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'categories';

    protected $fillable = ['title', 'code'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'category_id', 'id');
    }

    /**
     * Разделы, на которые вообще можно ссылаться.
     *
     * Условие «в разделе есть хоть один опубликованный пост» до этого стояло
     * дословно в шести местах — двух картах сайта, сайдбаре главной и двух
     * листингах, — и ровно из-за этого разъехалось: HTML-карта перечисляла
     * Category::all(), а XML-карта фильтровала, и поисковик ходил по ссылкам
     * на пустые страницы. Скоуп нужен, чтобы правило жило в одном месте.
     */
    public function scopeHasPublishedPosts(Builder $query): Builder
    {
        return $query->whereHas('posts', fn (Builder $q) => $q->published());
    }
}
