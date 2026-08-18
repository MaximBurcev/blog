<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        /*
         * daily, а не single: единственный файл laravel.log не ротировался
         * вообще и на проде рос с 4 августа непрерывно (1.6 МБ к 18-му) — а
         * лежит он в shared-каталоге, то есть переживает все релизы и растёт
         * до диска. Канал daily описан ниже давно, но подключён не был.
         *
         * days = 14: этого хватает разобрать инцидент выходных, а security-лог
         * со своим сроком в 90 дней живёт отдельным каналом и под ротацию не
         * попадает.
         */
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily'],
            'ignore_exceptions' => false,
        ],

        /*
         * permission 0660, а не 0640: в один и тот же файл пишут ДВА
         * пользователя — www-data из веба и deployer из artisan/queue:work, —
         * и общая у них только группа. С 0640 тот из них, кто не владелец
         * файла, терял право записи, и логирование из веба молча отваливалось
         * (проверено на проде). Смысл правки (было 0775) сохранён: снят бит
         * исполнения и доступ для посторонних локальных аккаунтов, а логи
         * содержат стектрейсы, SQL и — в канале security — email и IP.
         */
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'permission' => 0660,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'replace_placeholders' => true,
            'permission' => 0660,
        ],

        /*
         * Audit-trail событий безопасности: входы, неудачные попытки, lockout,
         * смена пароля и роли, удаление пользователей (App\Support\SecurityAudit).
         *
         * Отдельный файл, а не общий laravel.log: разбор инцидента не должен
         * тонуть в трафике парсера, а срок хранения тут нужен заметно длиннее
         * (компрометацию замечают не в тот же день).
         *
         * level захардкожен: канал обязан пережить понижение LOG_LEVEL до
         * warning/error на проде, иначе audit-trail молча исчезнет — ровно в
         * тот момент, когда логи ужимают из-за нагрузки.
         */
        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'level' => 'info',
            'days' => 90,
            'replace_placeholders' => true,
            'permission' => 0660,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => LOG_USER,
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        /*
         * Свой файл, а не общий laravel.log: сюда пишется только то, что не
         * удалось записать штатным каналом, и после перевода стека на daily
         * общий laravel.log перестал ротироваться вовсе — ежедневная ротация
         * подбирает laravel-*.log, а плоское имя под маску не попадает.
         * Оставить emergency там значило бы завести второй вечный файл.
         */
        'emergency' => [
            'path' => storage_path('logs/laravel-emergency.log'),
            'permission' => 0660,
        ],
    ],

];
