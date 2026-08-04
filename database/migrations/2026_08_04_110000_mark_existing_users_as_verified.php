<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Включение MustVerifyEmail задним числом сделало бы все уже существующие
 * аккаунты неподтверждёнными: они регистрировались, когда подтверждения не
 * было в принципе, и требовать его теперь — значит без предупреждения отрезать
 * людей от действий под middleware 'verified'.
 *
 * Проставляем отметку по created_at (то есть «подтверждён тогда же, когда
 * заведён»), а не now(): так в данных не появляется ложной даты подтверждения,
 * которого на самом деле не происходило.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Обратного пути нет: какие из отметок были проставлены этой миграцией,
        // а какие — реальным подтверждением, в данных уже не различить.
    }
};
