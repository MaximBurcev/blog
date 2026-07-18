<?php

namespace App\Service;

use App\Models\Tag;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TagDetectorService
{
    /**
     * Определяет массив tag_id по URL, заголовку и содержимому статьи.
     * Проверяет вхождение названия тега (lower-case) в URL, заголовок и
     * текст статьи. Дополнительно пробует словарь известных тем
     * (config('topics.known')) и создаёт тег при совпадении, которого
     * ещё нет среди существующих.
     *
     * @return int[]
     */
    public function detect(string $title, string $url = '', string $content = ''): array
    {
        $haystack = strtolower($url.' '.$title.' '.strip_tags($content));

        $matched = [];
        foreach (Tag::all(['id', 'title']) as $tag) {
            if ($this->matchesWord($haystack, $tag->title)) {
                $matched[] = $tag->id;
            }
        }

        foreach (config('topics.known', []) as $keyword => $canonicalName) {
            if ($this->matchesWord($haystack, $keyword)) {
                $matched[] = $this->findOrCreateTag($canonicalName)->id;
            }
        }

        $matched = array_values(array_unique($matched));

        Log::info('TagDetector: matched', ['tag_ids' => $matched]);

        return $matched;
    }

    /**
     * Проверяет вхождение $needle как отдельного слова (границы \b), а не
     * произвольной подстроки. Баг: с plain str_contains() короткие названия
     * (например "AI") ложно матчились внутри "contains", "domain", "email" —
     * как только такой тег реально создавался, следующие посты массово
     * получали его по ошибке. \b с модификатором u корректно работает и с
     * кириллицей (проверено на "Тестовая").
     */
    private function matchesWord(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/\b'.preg_quote(strtolower($needle), '/').'\b/iu', $haystack);
    }

    /**
     * Ищет тег без учёта регистра (чтобы не плодить дубли вроде
     * "Laravel" и "laravel"), создаёт с уникальным slug при отсутствии.
     */
    private function findOrCreateTag(string $title): Tag
    {
        $existing = Tag::whereRaw('LOWER(title) = ?', [mb_strtolower($title)])->first();
        if ($existing) {
            return $existing;
        }

        return Tag::create([
            'title' => $title,
            'code' => Str::slug($title),
        ]);
    }
}
