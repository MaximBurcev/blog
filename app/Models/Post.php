<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use SoftDeletes, Searchable;

    protected $table = 'posts';

    protected $guarded = false;

    protected $with = ['category'];

    public function tags(){
        return $this->belongsToMany(Tag::class, 'post_tags', 'post_id', 'tag_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id', 'id');
    }

    protected $casts = [
        'translation_incomplete' => 'boolean',
    ];

    public function likes()
    {
        return $this->hasMany(PostLike::class);
    }

    public function likesCount()
    {
        return $this->likes()->count();
    }

    public function excerpt(int $length = 160): string
    {
        $text = html_entity_decode(strip_tags($this->content), ENT_QUOTES, 'UTF-8');

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text)), $length);
    }
}
