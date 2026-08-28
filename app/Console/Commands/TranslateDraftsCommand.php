<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Service\TranslateService;
use App\Service\Translation\GoogleScraperTranslator;
use App\Service\Translation\TranslationResult;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class TranslateDraftsCommand extends Command
{
    protected $signature = 'posts:translate-drafts
        {--limit=20 : Сколько черновиков перевести за прогон}
        {--allow-fallback : Не останавливаться, если Gemini лежит и перевод идёт скрейпером}
        {--dry-run : Показать партию, ничего не переводя}';

    protected $description = 'Переводит партию готовых черновиков (parse_status=ok, published=0)';

    /**
     * Разбор завала идёт партиями, а не разом: бесплатная квота Gemini —
     * десятки запросов в сутки, и делить её приходится с утренним парсингом.
     * Команда стоит в расписании (daily) и доезжает остаток следующими сутками.
     */
    public function handle(TranslateService $translateService): int
    {
        $total = $this->drafts()->count();

        if ($total === 0) {
            $this->info('Черновиков, готовых к переводу, нет.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $posts = $this->drafts()->limit($limit)->get();

        $this->info("Черновиков к переводу: {$total}. Партия: {$posts->count()} (лимит {$limit}).");

        if ($this->option('dry-run')) {
            foreach ($posts as $post) {
                $this->line("  #{$post->id} {$post->title}");
            }
            $this->comment('Пробный прогон: ничего не переведено.');

            return self::SUCCESS;
        }

        $translated = 0;
        $needsReview = 0;

        foreach ($posts as $post) {
            $data = $translateService->translate([
                'title' => $post->title,
                'content' => $post->content_orig,
                'selector' => '',
                'url' => '',
            ]);

            // code не трогаем — тот же инвариант, что в TranslatePostCommand:
            // перевод заголовка гуляет от прогона к прогону, а code — публичный
            // адрес черновика.
            $post->update([
                'title' => $data['title'],
                'content' => $data['content'],
                'translation_incomplete' => ! empty($data['translation_incomplete']),
                'translated_by' => $data['translated_by'] ?? null,
            ]);

            $engine = $data['translated_by'] ?? TranslationResult::NO_ENGINE;
            $incomplete = ! empty($data['translation_incomplete']);

            $translated++;
            $needsReview += (int) $incomplete;

            $this->line("  #{$post->id} [{$engine}]".($incomplete ? ' (требует ревью)' : '')." {$post->title}");

            /*
             * Запасной движок — страховка конвейера, а не инструмент разбора
             * завала: скрейпером мы эти статьи уже один раз перевели, второй
             * раз тем же скрейпером — бессмысленно. Почти всегда fallback
             * означает исчерпанную квоту, так что партию останавливаем:
             * остаток доедет следующим запуском по расписанию.
             */
            if (! $this->option('allow-fallback') && $engine !== TranslationResult::NO_ENGINE
                && $engine === GoogleScraperTranslator::NAME) {
                $this->warn('Перевод ушёл на запасной движок — основной, похоже, лежит (квота?). Партию останавливаю.');
                break;
            }
        }

        $left = $this->drafts()->count();
        $this->info("Готово: {$translated}, из них требуют ревью: {$needsReview}. Осталось черновиков: {$left}.");

        return self::SUCCESS;
    }

    /**
     * Готовые к переводу черновики: разобраны успешно, не опубликованы, есть
     * сохранённый оригинал. Сначала старые — они дольше всех ждут вычитки.
     */
    private function drafts(): Builder
    {
        return Post::query()
            ->where('published', false)
            ->where('parse_status', Post::PARSE_STATUS_OK)
            ->whereNotNull('content_orig')
            ->where('content_orig', '!=', '')
            ->oldest('created_at');
    }
}
