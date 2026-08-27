<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tool extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tools';

    protected $fillable = [
        'name',
        'url',
        'description',
        'description_orig',
        'digest_url',
        'translated_by',
        'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function displayDescription(): string
    {
        $translated = trim((string) $this->description);

        return $translated !== '' ? $translated : trim((string) $this->description_orig);
    }
}
