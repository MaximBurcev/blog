<?php

namespace App\Service\Translation;

use App\Models\LlmCall;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * HTTP-клиент Gemini: один запрос к модели с повторами, разбором ответа и
 * записью в журнал llm_calls.
 *
 * Извлечён из GeminiTranslator, когда у модели появился второй потребитель
 * (App\Service\LlmTaggerService): своя копия retry-политики и учёта токенов
 * в каждом вызывающем разъехалась бы сразу, а расход считается именно по
 * этому журналу. Вся переводческая специфика (промпты, валидатор, разбиение
 * на куски) осталась в GeminiTranslator — здесь только сеть и журнал.
 *
 * Официального PHP SDK у Google нет, поэтому обычный HTTP-клиент Laravel.
 */
class GeminiClient
{
    /**
     * Провайдер. В llm_calls.engine едет именно он, а не имя модели: рядом
     * есть колонка model, и дублировать её значением engine значит потерять
     * возможность сложить расход по провайдеру.
     */
    public const PROVIDER = 'gemini';

    /**
     * Модель приходит аргументом, а не читается из конфига внутри.
     *
     * Иначе всех экземпляров этого класса в приложении может быть только один
     * — с одной моделью, — а квота у Google считается ПО МОДЕЛИ
     * (GenerateRequestsPerDayPerProjectPerModel-FreeTier, замерено 24.08.2026:
     * gemini-3.6-flash отдавал 429, а gemini-3.5-flash тем же ключом в ту же
     * секунду отвечал 200). Ходя всегда в одну модель, мы упирались в треть
     * доступного: 20 запросов в сутки вместо 60 на трёх моделях.
     *
     * null — взять основную из конфига, чтобы контейнер мог собрать клиент
     * без аргументов.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $model = null,
        private readonly ?TranslationDeadline $deadlineHolder = null,
    ) {}

    private function deadline(): TranslationDeadline
    {
        return $this->deadlineHolder ?? app(TranslationDeadline::class);
    }

    public function model(): string
    {
        return $this->model ?? (string) config('translation.gemini.model');
    }

    /**
     * Клиент для другой модели с теми же настройками.
     *
     * Прокси, таймауты и retry-политика читаются из конфига внутри ask(), так
     * что разнести их негде: отличие клиентов — только имя модели и общий на
     * всех срок статьи. Тем же способом сборщик цепочки переводчика
     * (AppServiceProvider) собирает движки на каждую модель.
     */
    public function forModel(string $model): self
    {
        return new self($this->http, $model, $this->deadlineHolder);
    }

    /**
     * Запрос по цепочке моделей: основная, затем запасные по порядку.
     *
     * Квота у Google считается ПО МОДЕЛИ (см. chainModels у GeminiTranslator),
     * поэтому исчерпавшая квоту основная модель — не повод останавливать
     * разметку, пока у запасных квота свободна. Ровно это и случилось с
     * разметкой архива 01.09.2026: переводы съели суточный лимит основной
     * модели, предохранитель разомкнулся, и тегирование встало, хотя две
     * запасные модели отвечали.
     *
     * Модель с разомкнутым предохранителем пропускаем без запроса. Размыкание
     * отказавшей модели делает сам ask() через markDown — здесь это
     * специально не дублируется. Пустой ответ (text === null) — только когда
     * недоступны ВСЕ модели цепочки.
     */
    public function askAlongChain(string $prompt, string $kind): LlmAnswer
    {
        $skipped = [];

        foreach (GeminiTranslator::chainModels() as $model) {
            if (FallbackTranslator::isDown($model)) {
                $skipped[] = $model;

                continue;
            }

            $answer = $this->forModel($model)->ask($prompt, $kind);

            if ($answer->text !== null) {
                return $answer;
            }

            // Нет ключа или исчерпан бюджет статьи — остальные модели ответят
            // тем же: ключ и срок общие на всю цепочку, перебирать их
            // бессмысленно и дорого (по строке в журнале на каждую).
            if (in_array($answer->call?->outcome, [LlmCall::OUTCOME_NO_KEY, LlmCall::OUTCOME_BUDGET], true)) {
                break;
            }
        }

        if ($skipped !== []) {
            Log::info('GeminiClient: модели на паузе, пропущены', ['models' => $skipped]);
        }

        return new LlmAnswer(null);
    }

    /**
     * Сколько ждать один запрос: своя настройка, но не дольше остатка бюджета
     * статьи и не меньше пяти секунд — на совсем исходе смысла ждать уже нет,
     * но и рвать соединение мгновенно незачем.
     *
     * Публично, потому что это характеристика запроса, а не внутренняя
     * кухня: GeminiTranslator показывает её наружу как таймаут своего движка.
     */
    public function requestTimeout(): int
    {
        $configured = (int) config('translation.gemini.timeout');
        $remaining = $this->deadline()->remaining();

        if (is_infinite($remaining)) {
            return $configured;
        }

        return max(5, min($configured, (int) floor($remaining)));
    }

    /**
     * Один запрос к модели с повторами.
     *
     * Возвращает ответ с пустым text, если добиться его не удалось — решение о
     * запасном варианте принимает вызывающий, не этот класс. Каждый исход,
     * включая несостоявшиеся вызовы, попадает в журнал llm_calls: расход и
     * причины отказов иначе видны только в laravel.log, который ротируется.
     */
    public function ask(string $prompt, string $kind): LlmAnswer
    {
        $model = $this->model();
        $key = (string) config('translation.gemini.key');

        if ($key === '') {
            Log::warning('GeminiClient: GEMINI_API_KEY не задан');
            FallbackTranslator::markDown($model);

            return $this->record($kind, $model, LlmCall::OUTCOME_NO_KEY);
        }

        if ($this->deadline()->expired()) {
            Log::warning('GeminiClient: бюджет времени на статью исчерпан');

            return $this->record($kind, $model, LlmCall::OUTCOME_BUDGET);
        }

        $url = rtrim((string) config('translation.gemini.endpoint'), '/')
            ."/models/{$model}:generateContent";

        $delays = (array) config('translation.gemini.retry_delays_ms');
        $attempts = 1;
        // Carbon, а не microtime: тем же часам подчиняется Sleep, поэтому
        // прождённое время измеримо и в тестах, где сон подделан.
        $startedAt = CarbonImmutable::now();

        try {
            // Таймаут запроса ограничен ОСТАТКОМ бюджета статьи, а не только
            // своей настройкой. Без этого одна цепочка повторов живёт до
            // 4 × 90 с ожидания плюс 62 с пауз — 422 секунды, больше всего
            // таймаута StorePostJob (420). Проверка срока между кусками этого
            // не ловит: она стоит ПЕРЕД вызовом, а переполняется вызов внутри.
            // Так статья 236 и уходила в таймаут трижды подряд, не оставив в
            // журнале ни одной записи — ask() просто не успевал вернуться.
            $request = $this->http
                ->timeout($this->requestTimeout());

            if ($proxy = config('translation.gemini.proxy')) {
                $request = $request->withOptions(['proxy' => 'socks5h://'.$proxy]);
            }

            $response = $request
                // Повторяем только то, что имеет смысл повторять: 429 у
                // бесплатного тира — обычное дело (лимит запросов в минуту),
                // 5xx — временная беда на стороне Google. На 400 и 403
                // (невалидный ключ, неподдерживаемый регион) повтор бессмыслен.
                ->retry(
                    $delays,
                    when: function (\Throwable $exception) use (&$attempts, $delays): bool {
                        if (! $this->isRetryable($exception)) {
                            return false;
                        }

                        // Единственное место, где видна каждая неудачная
                        // попытка: сам клиент их не считает. Потолок — по числу
                        // задержек, потому что на последней попытке этот
                        // колбэк тоже вызывается, а повтора за ним уже нет.
                        $attempts = min($attempts + 1, count($delays) + 1);

                        return true;
                    },
                    throw: false
                )
                ->withHeaders([
                    // Ключ заголовком, а не ?key= в адресе: query-string
                    // оседает в логах прокси и access-логах.
                    'x-goog-api-key' => $key,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => array_filter([
                        'temperature' => (float) config('translation.gemini.temperature'),
                        'maxOutputTokens' => (int) config('translation.gemini.max_output_tokens'),
                        'thinkingConfig' => ($level = config('translation.gemini.thinking_level'))
                            ? ['thinkingLevel' => $level]
                            : null,
                    ], fn ($value) => $value !== null),
                ]);
        } catch (\Throwable $exception) {
            Log::warning('GeminiClient: запрос не удался', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->record($kind, $model, LlmCall::OUTCOME_ERROR,
                startedAt: $startedAt,
                attempts: $attempts,
                error: $exception::class.': '.$exception->getMessage(),
            );
        }

        // Счётчики Google приходят с каждым ответом, в том числе с обрезанным:
        // за такой ответ мы платим ровно так же, и не записать его значило бы
        // занизить расход именно там, где он потрачен впустую.
        $usage = $response->json('usageMetadata');
        $usage = is_array($usage) ? $usage : [];

        if ($response->failed()) {
            Log::warning('GeminiClient: ошибка API', [
                'status' => $response->status(),
                // Тело нужно целиком: именно из него видно «User location is
                // not supported» или «model no longer available» — по одному
                // коду 400 эти случаи неотличимы.
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            // 400/401/403 — беда конфигурации, а не мгновения: ключ, регион,
            // снятая модель. Повторять их на каждой следующей статье незачем.
            if (in_array($response->status(), [400, 401, 403, 404], true)) {
                FallbackTranslator::markDown($model);
            }

            $this->pauseIfQuotaIsSpent($response);

            return $this->record($kind, $model, LlmCall::OUTCOME_ERROR,
                startedAt: $startedAt,
                attempts: $attempts,
                usage: $usage,
                status: $response->status(),
                error: mb_substr($response->body(), 0, 191),
            );
        }

        $finishReason = $response->json('candidates.0.finishReason');
        $finishReason = is_string($finishReason) ? $finishReason : null;

        // Обрезанный ответ выглядит как обычный: приходит валидное содержимое,
        // просто без хвоста. Без этой проверки половина текста молча пропадала
        // бы, а вызывающий сочёл бы результат годным.
        if (in_array($finishReason, ['MAX_TOKENS', 'SAFETY', 'RECITATION'], true)) {
            Log::warning('GeminiClient: ответ не доведён до конца', [
                'finish_reason' => $finishReason,
            ]);

            return $this->record($kind, $model, LlmCall::OUTCOME_TRUNCATED,
                startedAt: $startedAt,
                attempts: $attempts,
                usage: $usage,
                status: $response->status(),
                finishReason: $finishReason,
            );
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text)) {
            Log::warning('GeminiClient: неожиданная структура ответа', [
                // Ответ без текста — это чаще всего блокировка фильтрами
                // безопасности: finishReason скажет, какими именно.
                'finish_reason' => $finishReason,
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return $this->record($kind, $model, LlmCall::OUTCOME_MALFORMED,
                startedAt: $startedAt,
                attempts: $attempts,
                usage: $usage,
                status: $response->status(),
                finishReason: $finishReason,
            );
        }

        return $this->record($kind, $model, LlmCall::OUTCOME_OK,
            startedAt: $startedAt,
            attempts: $attempts,
            usage: $usage,
            status: $response->status(),
            finishReason: $finishReason,
            text: $text,
        );
    }

    /**
     * Кладёт вызов в журнал и отдаёт ответ вызывающему.
     *
     * Единственная точка записи: любой выход из ask() проходит через неё,
     * поэтому «вызовов в журнале меньше, чем было на самом деле» — состояние,
     * которого по построению не бывает.
     *
     * @param  array<string, mixed>  $usage
     */
    private function record(
        string $kind,
        string $model,
        string $outcome,
        ?CarbonImmutable $startedAt = null,
        int $attempts = 0,
        array $usage = [],
        ?int $status = null,
        ?string $finishReason = null,
        ?string $text = null,
        ?string $error = null,
    ): LlmAnswer {
        $call = LlmCall::recording(fn (): LlmCall => LlmCall::create([
            'engine' => self::PROVIDER,
            'model' => $model,
            'kind' => $kind,
            'outcome' => $outcome,
            'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
            'thinking_tokens' => (int) ($usage['thoughtsTokenCount'] ?? 0),
            // NULL, а не ноль: у несостоявшегося вызова длительности не
            // существует, и ноль портил бы среднюю по всем остальным.
            'duration_ms' => $startedAt === null ? null : $this->elapsedMs($startedAt),
            'attempts' => $attempts,
            'http_status' => $status,
            'finish_reason' => $finishReason,
            // Тело ответа сюда приходит от чужого сервиса и текстом быть не
            // обязано: страница ошибки от прокси может оказаться бинарной, а
            // невалидный UTF-8 роняет INSERT — вызов пропал бы из журнала
            // целиком, хотя записать его мы как раз и хотели.
            'error' => $error === null ? null : mb_substr(
                mb_convert_encoding($error, 'UTF-8', 'UTF-8'), 0, 191
            ),
        ]));

        return new LlmAnswer($text, $call);
    }

    /**
     * Отличает исчерпанную квоту от обычного «слишком часто».
     *
     * Google сообщает это сам, машиночитаемо: в error.details лежит
     * QuotaFailure с полем quotaId вида
     * «GenerateRequestsPerDayPerProjectPerModel-FreeTier». Признак суточного
     * лимита — PerDay в идентификаторе.
     *
     * Раньше здесь стояла эвристика по времени: 429, переживший цепочку
     * повторов длиннее минутного окна, считался исчерпанной квотой. Она
     * оказалась слишком жадной. 24.08.2026 gemini-3.5-flash получила 429,
     * потратив за сутки всего пять HTTP-запросов из двадцати, — то есть это
     * был всплеск темпа, а не квота, — но четыре медленные попытки заняли 332
     * секунды, и здоровая модель была отключена на час. Цена ошибки
     * несимметрична: лишняя пауза выключает работающую модель, а её
     * отсутствие стоит одной цепочки повторов.
     *
     * Поэтому при отсутствии quotaId паузу НЕ ставим: молчание Google — не
     * доказательство исчерпания.
     */
    private function pauseIfQuotaIsSpent(Response $response): void
    {
        if ($response->status() !== 429) {
            return;
        }

        if (! $this->isDailyQuotaFailure($response)) {
            return;
        }

        $pause = (int) config('translation.quota_pause_seconds');

        Log::warning('GeminiClient: исчерпана суточная квота модели', [
            'model' => $this->model(),
        ]);

        // Ноль или отсутствие настройки — это «значения нет», а не «паузы не
        // надо»: подставляем обычную. Своего лога про длительность здесь нет
        // намеренно — его пишет markDown, и только когда пауза действительно
        // поставлена, иначе лог обещал бы несделанное.
        FallbackTranslator::markDown($this->model(), $pause > 0 ? $pause : null);
    }

    private function isDailyQuotaFailure(Response $response): bool
    {
        foreach ((array) $response->json('error.details', []) as $detail) {
            foreach ((array) ($detail['violations'] ?? []) as $violation) {
                if (str_contains((string) ($violation['quotaId'] ?? ''), 'PerDay')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function elapsedMs(CarbonImmutable $startedAt): int
    {
        return (int) round(abs($startedAt->diffInMilliseconds(CarbonImmutable::now())));
    }

    private function isRetryable(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return $exception->response->status() === 429
                || $exception->response->serverError();
        }

        return false;
    }
}
