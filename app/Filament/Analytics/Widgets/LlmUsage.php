<?php

namespace App\Filament\Analytics\Widgets;

use App\Models\LlmCall;
use App\Models\Post;
use App\Support\AnalyticsPeriod;
use App\Support\LlmPricing;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Во что обходится перевод статей моделью: расход, скорость, доля брака.
 *
 * Единственный потребитель таблицы llm_calls. До неё про LLM было известно
 * ровно одно — имя движка в posts.translated_by; ни счёта за токены, ни
 * времени ответа, ни причин отказов не существовало нигде, кроме laravel.log,
 * который ротируется. Вопрос «сколько стоит пост» не имел ответа в принципе.
 *
 * Живёт вне app/Filament/Widgets намеренно — см. PostViewsOverview.
 */
class LlmUsage extends BaseWidget
{
    use InteractsWithPageFilters;

    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    /** @see PostViewsOverview::$pollingInterval */
    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Расход на перевод';

    /**
     * День, с которого ведётся журнал вызовов.
     *
     * Раньше него в таблице пусто не потому, что модель молчала, а потому, что
     * писать было некому. Показывать за такой период честный ноль — то же
     * самое, что заявить «мы ничего не потратили», поэтому периоды, заходящие
     * за эту дату, помечаются как неполные (ср. PostView::ATTRIBUTION_SINCE и
     * PublishingPace::PUBLISHED_AT_SINCE).
     */
    private const CALLS_SINCE = '2026-08-21';

    protected function getStats(): array
    {
        // Один проход по таблице на весь виджет: строки разобраны по моделям,
        // остальное складывается в PHP. Второй запрос ради стоимости читал бы
        // тот же диапазон повторно — ровно то, от чего предостерегает докблок
        // ниже.
        $rows = $this->rows();
        $counters = $this->fold($rows);
        $periodLabel = AnalyticsPeriod::label($this->filters);

        if ($counters->calls === 0) {
            return [$this->emptyStat($periodLabel)];
        }

        return [
            $this->callsStat($counters, $periodLabel),
            $this->latencyStat($counters),
            $this->tokensStat($counters, $periodLabel),
            $this->costStat($rows, $periodLabel),
            $this->qualityStat($counters),
        ];
    }

    private function callsStat(object $counters, string $periodLabel): Stat
    {
        $calls = $counters->calls;
        $previousCalls = $counters->previous_calls;

        return Stat::make('Запросов за '.$periodLabel, (string) $calls)
            ->description($this->comparison($calls, $previousCalls))
            ->descriptionIcon($this->trendIcon($calls, $previousCalls))
            ->icon('heroicon-m-chat-bubble-left-right')
            // Цвет намеренно нейтральный: рост числа запросов — это и рост
            // расхода, и рост числа разобранных статей. Красить его в success
            // или danger значило бы выбрать за администратора, что из двух.
            ->color('gray');
    }

    /**
     * Скорость ответа и цена повторов.
     *
     * Среднее считается только по телам статей и только по вызовам с первой
     * попытки. Заголовок отвечает за полсекунды против десятков у статьи —
     * смешав их, получаем число, которое не описывает ни то ни другое. А
     * длительность с повторами включает сон между попытками (до 45 секунд), то
     * есть приписывает модели время, которое мы сами прождали.
     *
     * Повторы поэтому вынесены в подпись, а не в ошибки: 429 у бесплатного
     * тира — рабочий режим, а не сбой, но именно он объясняет, почему разбор
     * статей вдруг пополз. В счётчике ошибок такие вызовы не видны — они в
     * итоге завершились успешно.
     */
    private function latencyStat(object $counters): Stat
    {
        $measured = $counters->latency_calls;
        $retries = $counters->retries;

        return Stat::make('Среднее время ответа', $measured > 0
            ? number_format($counters->latency_sum / $measured / 1000, 1, ',', ' ').' с'
            : '—')
            ->description($retries > 0
                ? 'Повторных попыток: '.$retries
                : 'Все ответы с первой попытки')
            ->icon('heroicon-m-clock')
            ->color($retries > 0 ? 'warning' : 'gray');
    }

    private function tokensStat(object $counters, string $periodLabel): Stat
    {
        $input = $counters->input_tokens;
        $output = $counters->output_tokens;

        return Stat::make('Токенов за '.$periodLabel, $this->number($input + $output))
            // Разделение не косметическое: выходные токены дороже входных в
            // разы, и одна общая цифра не даёт понять, чем именно вырос счёт.
            ->description('Вход: '.$this->number($input).' · выход: '.$this->number($output))
            ->icon('heroicon-m-cpu-chip')
            ->color('gray');
    }

    /**
     * Деньги за период и цена одного переведённого поста.
     *
     * Цены в конфиге может не быть, и тогда плитка прямо об этом говорит: ноль
     * долларов и «мы не знаем, сколько это стоило» — разные утверждения, а
     * первое выглядит убедительнее ровно настолько, насколько оно неверно.
     */
    private function costStat(Collection $rows, string $periodLabel): Stat
    {
        [$cost, $unpricedCalls] = $this->cost($rows);

        if ($cost === null) {
            return Stat::make('Стоимость за '.$periodLabel, '—')
                ->description('Цена модели не задана: config/translation.php → gemini.prices')
                ->icon('heroicon-m-banknotes')
                ->color('warning');
        }

        return Stat::make('Стоимость за '.$periodLabel, $this->money($cost))
            ->description($this->costNote($cost, $unpricedCalls))
            ->icon('heroicon-m-banknotes')
            ->color($unpricedCalls > 0 ? 'warning' : 'gray');
    }

    private function costNote(float $cost, int $unpricedCalls): string
    {
        if ($unpricedCalls > 0) {
            return 'Без цены осталось запросов: '.$unpricedCalls.' — сумма неполная';
        }

        $posts = $this->postsTranslatedByModel();

        if ($posts === 0) {
            return 'Ни один пост за период не переведён моделью';
        }

        // Знаменатель — посты, а числитель — ВСЕ вызовы периода, включая те,
        // что кончились браком и увели статью на скрейпер. Это намеренно: они
        // тоже оплачены, и «цена поста» обязана включать промахи, иначе она
        // занижена ровно на долю, которую хотелось бы не замечать.
        return 'Примерно '.$this->money($cost / $posts).' на пост';
    }

    private function qualityStat(object $counters): Stat
    {
        $ok = $counters->ok;
        $wasted = $counters->wasted;
        $errors = $counters->errors;
        $share = (int) round($ok / $counters->calls * 100);

        return Stat::make('Удачных ответов', $share.'%')
            ->description($wasted === 0 && $errors === 0
                ? 'Ни одного отказа за период'
                : 'Брак модели: '.$wasted.' · ошибок и пропусков: '.$errors)
            ->icon('heroicon-m-check-badge')
            ->color(match (true) {
                $share >= 95 => 'success',
                $share >= 80 => 'warning',
                default => 'danger',
            });
    }

    private function emptyStat(string $periodLabel): Stat
    {
        return Stat::make('Запросов за '.$periodLabel, '0')
            ->description($this->periodPredatesJournal()
                ? 'Журнал вызовов ведётся с '.self::CALLS_SINCE.' — за более ранние дни данных нет'
                : 'Модель не вызывалась: либо нечего разбирать, либо перевод идёт скрейпером')
            ->icon('heroicon-m-chat-bubble-left-right')
            ->color('gray');
    }

    /**
     * Все счётчики периода — одним проходом по индексу created_at.
     *
     * Тот же приём, что в PostViewsOverview::counters: раздельными запросами
     * это десяток сканов одного и того же диапазона подряд.
     *
     * Разбивка по моделям нужна стоимости: за период версия модели могла
     * смениться (GEMINI_MODEL — переменная окружения), и складывать токены
     * разной цены в одну кучу нельзя. Остальным плиткам она не мешает —
     * моделей в окне единицы, и складываются они в PHP (см. fold).
     *
     * @return Collection<int, object>
     */
    private function rows(): Collection
    {
        $periodStart = AnalyticsPeriod::startsAt($this->filters);
        $previousStart = AnalyticsPeriod::previousStartsAt($this->filters);
        $previousEnd = AnalyticsPeriod::previousEndsAt($this->filters);

        return DB::table('llm_calls')
            ->selectRaw('
                model,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as calls,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as previous_calls,
                SUM(CASE WHEN created_at >= ? AND outcome = ? THEN 1 ELSE 0 END) as ok,
                SUM(CASE WHEN created_at >= ? AND outcome IN (?, ?, ?) THEN 1 ELSE 0 END) as wasted,
                SUM(CASE WHEN created_at >= ? AND outcome IN (?, ?, ?) THEN 1 ELSE 0 END) as errors,
                SUM(CASE WHEN created_at >= ? THEN prompt_tokens ELSE 0 END) as input_tokens,
                SUM(CASE WHEN created_at >= ? THEN output_tokens + thinking_tokens ELSE 0 END) as output_tokens,
                SUM(CASE WHEN created_at >= ? AND attempts > 1 THEN attempts - 1 ELSE 0 END) as retries,
                SUM(CASE WHEN created_at >= ? AND kind = ? AND attempts = 1 AND duration_ms IS NOT NULL THEN duration_ms ELSE 0 END) as latency_sum,
                SUM(CASE WHEN created_at >= ? AND kind = ? AND attempts = 1 AND duration_ms IS NOT NULL THEN 1 ELSE 0 END) as latency_calls
            ', [
                $periodStart,
                $previousStart, $previousEnd,
                $periodStart, LlmCall::OUTCOME_OK,
                $periodStart, ...LlmCall::WASTED_OUTCOMES,
                $periodStart, ...LlmCall::FAILED_OUTCOMES,
                $periodStart,
                $periodStart,
                $periodStart,
                $periodStart, LlmCall::KIND_HTML,
                $periodStart, LlmCall::KIND_HTML,
            ])
            // Нижняя граница выборки — начало предыдущего периода: дальше в
            // прошлое ни одна плитка не смотрит.
            ->where('created_at', '>=', $previousStart)
            ->groupBy('model')
            ->get();
    }

    /**
     * Складывает построчную разбивку в общие числа периода.
     *
     * Сумма и число замеров вместо готового среднего: AVG по каждой модели
     * отдельно, усреднённый потом ещё раз, дал бы среднее из средних — оно
     * равно правильному только при одинаковом числе вызовов у всех моделей.
     *
     * @param  Collection<int, object>  $rows
     */
    private function fold(Collection $rows): object
    {
        $sum = fn (string $column): int => (int) $rows->sum(fn (object $row): int => (int) $row->{$column});

        return (object) [
            'calls' => $sum('calls'),
            'previous_calls' => $sum('previous_calls'),
            'ok' => $sum('ok'),
            'wasted' => $sum('wasted'),
            'errors' => $sum('errors'),
            'input_tokens' => $sum('input_tokens'),
            'output_tokens' => $sum('output_tokens'),
            'retries' => $sum('retries'),
            'latency_sum' => $sum('latency_sum'),
            'latency_calls' => $sum('latency_calls'),
        ];
    }

    /**
     * Стоимость периода и число запросов, которые в неё не вошли.
     *
     * Модель без цены не обнуляет отчёт, а честно выносится в остаток: молча
     * прибавить чужие токены по чужой цене было бы выдумкой.
     *
     * @param  Collection<int, object>  $rows
     * @return array{0: float|null, 1: int}
     */
    private function cost(Collection $rows): array
    {
        $cost = 0.0;
        $unpricedCalls = 0;
        $priced = false;

        foreach ($rows as $row) {
            // Модели, вызывавшейся только в предыдущем периоде, в текущем окне
            // не досталось ни токенов, ни вызовов — в остаток она не идёт.
            if ((int) $row->calls === 0) {
                continue;
            }

            $rowCost = LlmPricing::costUsd(
                (string) $row->model,
                (int) $row->input_tokens,
                (int) $row->output_tokens,
            );

            if ($rowCost === null) {
                $unpricedCalls += (int) $row->calls;

                continue;
            }

            $priced = true;
            $cost += $rowCost;
        }

        return [$priced ? $cost : null, $unpricedCalls];
    }

    /**
     * Сколько постов за период перевела именно модель.
     *
     * По parsed_at, а не created_at, по той же причине, что и в
     * PublishingPace: created_at хранит дату публикации оригинала у источника.
     */
    private function postsTranslatedByModel(): int
    {
        return Post::query()
            ->where('translated_by', 'gemini')
            ->where('parsed_at', '>=', AnalyticsPeriod::startsAt($this->filters))
            ->count();
    }

    private function periodPredatesJournal(): bool
    {
        return AnalyticsPeriod::startsAt($this->filters)
            ->lt(CarbonImmutable::parse(self::CALLS_SINCE));
    }

    private function comparison(int $current, int $previous): string
    {
        $subject = AnalyticsPeriod::previousLabel($this->filters);

        if ($this->periodPredatesJournal()) {
            return 'Журнал вызовов ведётся с '.self::CALLS_SINCE.', период неполный';
        }

        if ($previous === 0) {
            return 'Сравнивать не с чем: в '.$subject.' вызовов не было';
        }

        $delta = (int) round(($current - $previous) / $previous * 100);

        if ($delta === 0) {
            return 'Столько же, сколько в '.$subject;
        }

        return sprintf('%s%d%% к тому, что было в %s', $delta > 0 ? '+' : '−', abs($delta), $subject);
    }

    private function trendIcon(int $current, int $previous): ?string
    {
        if ($current === $previous || $this->periodPredatesJournal()) {
            return null;
        }

        return $current > $previous ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    /**
     * Доллары с точностью, при которой цифра не превращается в ноль.
     *
     * Расход за неделю измеряется центами, а цена одного поста — их долями:
     * два знака после запятой показали бы «$0.00» там, где потрачено что-то
     * реальное.
     */
    private function money(float $usd): string
    {
        $decimals = $usd > 0 && $usd < 0.1 ? 4 : 2;

        return '$'.number_format($usd, $decimals, '.', ' ');
    }
}
