<?php

namespace App\Console\Commands;

use App\Models\PostView;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Чистка просмотров, сделанных роботами.
 *
 * post_views пополняется на каждый заход и до сих пор не чистилась ничем, хотя
 * в расписании давно убираются и telescope, и упавшие задачи, и токены сброса
 * пароля.
 *
 * Удаляются ТОЛЬКО записи роботов, и только старые. Живые просмотры не
 * трогаем: на нынешних полусотне заходов в день это 20 тысяч строк за год —
 * не тот объём, ради которого стоит терять историю. Роботы же не нужны никому
 * ни в одной выборке: аналитика их отсекает глобальным скоупом, счётчик под
 * статьёй тоже. Смысл держать их дольше — возможность пересмотреть вердикт
 * детектора, а через квартал такой разбор уже неинтересен.
 *
 * Когда человеческие просмотры станут проблемой (миллионы строк), появится
 * схлопывание старых в счётчик поста — но заводить эту машинерию заранее
 * значит усложнять модель ради цифр, которых нет. Тогда же уместен будет и
 * MassPrunable вместо одного DELETE: сейчас недельная дельта — десятки строк.
 */
class PrunePostViewsCommand extends Command
{
    protected $signature = 'post-views:prune
        {--days=90 : Сколько дней хранить записи роботов}
        {--dry-run : Показать, сколько будет удалено, без записи}';

    protected $description = 'Удаляет из post_views старые записи роботов';

    public function handle(): int
    {
        $days = $this->option('days');

        // Явная проверка вместо max(1, (int) $days): приведение типа молча
        // превращает «--days=quarter» в ноль, а тот — в один день, то есть
        // опечатка означала бы «удалить почти всё». Команда деструктивная,
        // такие вольности ей не по чину (ср. MarkBotViewsCommand).
        if (! ctype_digit((string) $days) || (int) $days < 1) {
            $this->error('--days должен быть целым числом больше нуля.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays((int) $days)->startOfDay();

        $query = PostView::withoutGlobalScope(PostView::HUMANS_ONLY)
            ->where('is_bot', true)
            ->where('viewed_at', '<', $cutoff)
            // Записи старше 17.08.2026 размечены не User-Agent'ом, а эвристикой
            // «сколько сессий пришло с одного IP» (post-views:mark-bots). Она
            // по построению может задеть офис за NAT, то есть живых читателей,
            // и потому объявлена обратимой через --reset. Удалив их, мы отняли
            // бы у той команды предмет отката — поэтому исторические записи
            // прунинг не трогает.
            ->where('viewed_at', '>=', Carbon::parse(PostView::ATTRIBUTION_SINCE)->startOfDay());

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("Записей роботов старше {$cutoff->toDateString()} нет.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Будет удалено {$count} записей роботов старше {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        // Рапортуем по возврату delete(), а не по прежнему счётчику: между
        // подсчётом и удалением таблица живёт своей жизнью, а эта цифра —
        // единственный след операции.
        $deleted = $query->delete();

        $this->info("Удалено {$deleted} записей роботов старше {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
