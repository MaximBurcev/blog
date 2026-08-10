<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default hash driver that will be used to hash
    | passwords for your application. By default, the bcrypt algorithm is
    | used; however, you remain free to modify this option if you wish.
    |
    | Supported: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => 'bcrypt',

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options that should be used when
    | passwords are hashed using the Bcrypt algorithm. This will allow you
    | to control the amount of time it takes to hash the given password.
    |
    */

    'bcrypt' => [
        // 12, а не 10: дефолт подняли в Laravel 11, а конфиг у нас от 10-й
        // ветки — composer его не обновляет. Cost 10 вчетверо дешевле для
        // офлайнового перебора при утечке дампа users.
        // Пересчёт бесшовный: hashing.rehash_on_login по умолчанию true,
        // хэш доапгрейдится при следующем входе каждого пользователя.
        'rounds' => env('BCRYPT_ROUNDS', 12),
        // Без этого ключа BcryptHasher::$verifyAlgorithm остаётся false, и
        // Hash::check() молча принимает хэш чужого алгоритма вместо ошибки.
        'verify' => env('HASH_VERIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options that should be used when
    | passwords are hashed using the Argon algorithm. These will allow you
    | to control the amount of time it takes to hash the given password.
    |
    */

    'argon' => [
        'verify' => env('HASH_VERIFY', true),
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
    ],

];
