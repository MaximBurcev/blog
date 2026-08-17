<?php

namespace App\Console\Commands;

use App\Models\PostView;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Разметка просмотров, записанных до появления колонки is_bot.
 *
 * User-Agent тогда не сохранялся, поэтому робота задним числом видно только
 * косвенно. Признак — число разных сессий с одного IP: браузер получает cookie
 * и возвращается с той же сессией, а краулер приходит каждый раз новым. На
 * 17.08.2026 из 311 адресов у восьми было от 9 до 39 сессий — это не читатели.
 *
 * Порог по умолчанию 4: три сессии с адреса набирает и человек (смена
 * устройства, режим инкогнито, истёкшая сессия), а вот четвёртая уже говорит,
 * что cookie не держатся вовсе. Порог сознательно щадящий — задача не поймать
 * всех роботов, а убрать тех, кто искажает статистику заметнее прочих.
 *
 * Команда работает ТОЛЬКО с записями старше PostView::ATTRIBUTION_SINCE, и это
 * не косметика:
 *
 * - без верхней границы эвристика съедала бы живых читателей. Четыре сессии с
 *   одного адреса — это обычный офис или мобильный оператор за CG-NAT, и все их
 *   просмотры, уже правильно классифицированные по User-Agent, задним числом
 *   стали бы роботами. Чем позже запустить команду, тем больше людей потеряно;
 * - без неё же --reset был бы невосстановим: он снимал бы флаг и с записей,
 *   размеченных BotDetector'ом на лету, а пересчитать их нечем — сам User-Agent
 *   намеренно не хранится.
 *
 * В своих границах команда идемпотентна и обратима: --reset возвращает
 * исторические записи в исходное состояние. Строки не удаляются ни в одном
 * режиме — ошибку в пороге можно пересмотреть, удалённые данные нет.
 */
class MarkBotViewsCommand extends Command
{
    protected $signature = 'post-views:mark-bots
        {--sessions=4 : Сколько разных сессий с одного IP считать признаком робота}
        {--dry-run : Показать, что будет сделано, без записи}
        {--reset : Снять разметку с исторических записей и выйти}';

    protected $description = 'Пометить роботами просмотры, записанные до появления User-Agent-детекта';

    public function handle(): int
    {
        $this->line('Записи с '.PostView::ATTRIBUTION_SINCE.' не затрагиваются: у них есть разметка по User-Agent.');

        return $this->option('reset') ? $this->reset() : $this->mark();
    }

    private function reset(): int
    {
        $affected = $this->historical()->where('is_bot', true)->count();

        if ($this->option('dry-run')) {
            $this->info("Будет снята разметка с {$affected} исторических записей.");

            return self::SUCCESS;
        }

        $this->historical()->where('is_bot', true)->update(['is_bot' => false]);
        $this->forgetTotalsCache();
        $this->info("Разметка снята с {$affected} исторических записей.");

        return self::SUCCESS;
    }

    private function mark(): int
    {
        $threshold = (int) $this->option('sessions');

        if ($threshold < 2) {
            $this->error('Порог --sessions должен быть не меньше 2: с одной сессией на адрес приходит любой читатель.');

            return self::FAILURE;
        }

        $suspects = $this->suspectIpHashes($threshold);

        if ($suspects === []) {
            $this->info('Адресов с числом сессий выше порога не нашлось — размечать нечего.');

            return self::SUCCESS;
        }

        $target = $this->historical()->whereIn('ip_hash', $suspects)->where('is_bot', false);
        $affected = (clone $target)->count();
        $total = $this->historical()->count();
        $share = $total > 0 ? round($affected / $total * 100, 1) : 0.0;

        if ($this->option('dry-run')) {
            $this->info("Будет помечено {$affected} из {$total} исторических просмотров ({$share}%) с ".count($suspects).' адресов.');

            return self::SUCCESS;
        }

        $target->update(['is_bot' => true]);
        $this->forgetTotalsCache();

        $this->info("Помечено {$affected} из {$total} исторических просмотров ({$share}%) с ".count($suspects).' адресов.');
        $this->line('Снять разметку: php artisan post-views:mark-bots --reset');

        return self::SUCCESS;
    }

    /**
     * Записи, сделанные до появления детекта по User-Agent, — единственное, что
     * команде позволено трогать.
     */
    private function historical(): Builder
    {
        return DB::table('post_views')
            ->where('viewed_at', '<', Carbon::parse(PostView::ATTRIBUTION_SINCE)->startOfDay());
    }

    /**
     * Хэши адресов, с которых пришло больше разрешённого числа сессий.
     *
     * Записи без ip_hash пропускаем: сгруппировать их не по чему. COUNT DISTINCT
     * не считает NULL, поэтому адрес, у которого вообще нет сессий, порога не
     * достигнет — на web-маршрутах сессия стартует всегда, так что случай
     * теоретический, но эвристика по построению ловит только тех, у кого сессии
     * есть и они каждый раз новые.
     *
     * @return array<int, string>
     */
    private function suspectIpHashes(int $threshold): array
    {
        return $this->historical()
            ->whereNotNull('ip_hash')
            ->groupBy('ip_hash')
            ->havingRaw('COUNT(DISTINCT session_hash) >= ?', [$threshold])
            ->pluck('ip_hash')
            ->all();
    }

    /**
     * Плитка «Всего просмотров» живёт из кэша на 10 минут. Без сброса админ
     * первым делом увидел бы, что цифра не изменилась.
     */
    private function forgetTotalsCache(): void
    {
        Cache::forget('analytics:post-views-total:humans');
    }
}
