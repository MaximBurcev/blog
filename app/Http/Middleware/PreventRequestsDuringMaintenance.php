<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * The URIs that should be reachable while maintenance mode is enabled.
     *
     * @var array<int, string>
     */
    protected $except = [
        /*
         * Health-check обязан отвечать и во время планового обслуживания.
         * Иначе `artisan down` на время миграции поднимает тревогу у внешнего
         * монитора: система оповещения начинает будить по расписанию, а на
         * такие оповещения быстро перестают смотреть.
         */
        'up',
    ];
}
