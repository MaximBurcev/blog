<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Один вызов языковой модели: сколько токенов, сколько времени, чем кончился.
 *
 * Пишется из App\Service\Translation\GeminiTranslator, читается виджетом
 * «Расход на перевод» и командой llm-calls:prune. Больше в приложении её никто
 * не трогает: на сам перевод эта таблица не влияет никак — она про деньги и
 * наблюдаемость, не про результат.
 *
 * @property int $id
 * @property string $engine
 * @property string $model
 * @property string $kind
 * @property string $outcome
 * @property int $prompt_tokens
 * @property int $output_tokens
 * @property int $thinking_tokens
 * @property int|null $duration_ms
 * @property int $attempts
 * @property int|null $http_status
 * @property string|null $finish_reason
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class LlmCall extends Model
{
    /**
     * Обновлять здесь нечего: запись о состоявшемся вызове неизменна. Есть
     * ровно одно исключение — outcome меняется на rejected, когда валидатор
     * забраковал уже полученный ответ (см. markRejected).
     */
    public const UPDATED_AT = null;

    /** Тело статьи или её кусок. */
    public const KIND_HTML = 'html';

    /** Заголовок. */
    public const KIND_TEXT = 'text';

    /** Ответ получен и принят. */
    public const OUTCOME_OK = 'ok';

    /** Ответ получен, но забракован TranslatedHtmlValidator. */
    public const OUTCOME_REJECTED = 'rejected';

    /** Модель не довела ответ до конца: MAX_TOKENS, SAFETY, RECITATION. */
    public const OUTCOME_TRUNCATED = 'truncated';

    /** Ответ 200, но текста в нём нет — чаще всего фильтры безопасности. */
    public const OUTCOME_MALFORMED = 'malformed';

    /** Сеть или ошибка API. */
    public const OUTCOME_ERROR = 'error';

    /** Запроса не было: не задан GEMINI_API_KEY. */
    public const OUTCOME_NO_KEY = 'no_key';

    /** Запроса не было: исчерпан бюджет времени на статью. */
    public const OUTCOME_BUDGET = 'budget';

    /**
     * Ответ пришёл, но пользы не принёс.
     *
     * Отдельно от ошибок связи: здесь мы за токены заплатили (в том числе за
     * обрезанный ответ), а статью не получили. Рост именно этой доли — сигнал,
     * что модель стала халтурить, и он не виден в счётчике ошибок API.
     */
    public const WASTED_OUTCOMES = [
        self::OUTCOME_REJECTED,
        self::OUTCOME_TRUNCATED,
        self::OUTCOME_MALFORMED,
    ];

    /**
     * Ответа не было вовсе.
     *
     * Здесь мы, наоборот, ничего не заплатили, зато остались без перевода:
     * статья ушла на скрейпер. Считать это вместе с браком модели нельзя —
     * лечится оно совсем другим (ключ, сеть, лимиты), а не сменой версии.
     */
    public const FAILED_OUTCOMES = [
        self::OUTCOME_ERROR,
        self::OUTCOME_NO_KEY,
        self::OUTCOME_BUDGET,
    ];

    protected $fillable = [
        'engine',
        'model',
        'kind',
        'outcome',
        'prompt_tokens',
        'output_tokens',
        'thinking_tokens',
        'duration_ms',
        'attempts',
        'http_status',
        'finish_reason',
        'error',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'output_tokens' => 'integer',
        'thinking_tokens' => 'integer',
        'duration_ms' => 'integer',
        'attempts' => 'integer',
        'http_status' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Ответ получен, но валидатор его завернул.
     *
     * Отдельным шагом, а не сразу нужным outcome, потому что решение принимают
     * в разных местах: сетевую часть знает ask(), а годность разметки —
     * translateSingle() уже после возврата. Токены при этом уже потрачены,
     * поэтому строка не удаляется, а перекрашивается.
     */
    public function markRejected(string $reason): void
    {
        self::recording(fn () => $this->update([
            'outcome' => self::OUTCOME_REJECTED,
            'error' => self::category($reason),
        ]));
    }

    /**
     * Оставляет от вердикта валидатора только его вид.
     *
     * TranslatedHtmlValidator дописывает к причине улику — до 60 символов кода
     * из самой статьи («код изменён или потерян: …»). В журнале расходов ей не
     * место дважды: во-первых, таблица по построению не хранит ни промпта, ни
     * ответа, во-вторых, с уликой каждый брак становится уникальной строкой, и
     * отчёт «по каким причинам браковали» вырождается в список из N разных
     * причин вместо трёх с числами. Полный текст с фрагментом остаётся в
     * laravel.log — там он и нужен, поштучно.
     */
    private static function category(string $reason): string
    {
        return mb_substr(trim(explode(':', $reason, 2)[0]), 0, 191);
    }

    /**
     * Выполняет запись в журнал так, чтобы её отказ не стоил нам перевода.
     *
     * Журнал расходов — побочная функция: недоступная таблица (окно деплоя,
     * переполненный диск) не повод терять статью, ради которой всё затевалось.
     * Молчать при этом нельзя — ровно так полгода прятался неработающий OCR,
     * поэтому неудача уходит предупреждением в лог.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T|null
     */
    public static function recording(callable $write): mixed
    {
        try {
            return $write();
        } catch (\Throwable $exception) {
            Log::warning('LlmCall: не удалось записать вызов модели в журнал', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
