<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Sentry\Laravel\Integration;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        /*
         * Ошибки уезжают в Sentry. До 18.08.2026 узнать о падении на проде было
         * неоткуда: единственным сигналом наружу оставалось письмо
         * backup:monitor в 06:00, а всё остальное лежало в laravel.log, куда
         * никто не смотрел — падение планировщика так прожило неделю.
         *
         * Integration::handles() не подходит: он принимает объект Exceptions из
         * скелета Laravel 11, а приложение живёт на легаси-скелете с этим самым
         * Handler'ом. Зато captureUnhandledException — как раз то, что
         * документация Sentry предписывает звать отсюда.
         *
         * Не голый captureException: тот отправляет событие без механизма
         * исключения, и в Sentry ВСЁ помечается как «handled» — фильтр
         * «Unhandled» и метрика crash-free перестают что-либо значить. Метод
         * ниже сам определяет, пришло исключение из report() или упало
         * по-настоящему.
         *
         * Проверка на регистрацию сервиса не нужна: SentrySdk::getCurrentHub()
         * внутри работает всегда, а без DSN клиент просто никуда не отправляет.
         */
        $this->reportable(function (Throwable $e) {
            Integration::captureUnhandledException($e);
        });
    }
}
