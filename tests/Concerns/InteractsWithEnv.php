<?php

namespace Tests\Concerns;

/**
 * Временная подмена переменных окружения для тестов, которые проверяют сами
 * конфиг-файлы (их значения вычисляются через env() в момент require).
 *
 * Писать через Illuminate\Support\Env::getRepository()->set() нельзя: Laravel
 * собирает репозиторий как immutable (Env::getRepository(), ->immutable()), и
 * запись в переменную, определённую снаружи репозитория — например APP_ENV из
 * phpunit.xml — молча игнорируется. Тест при этом не падает, а тихо проверяет
 * не то, что задумано. Поэтому пишем в putenv/$_ENV/$_SERVER напрямую: чтение
 * env() идёт именно оттуда и иммутабельностью не ограничено.
 */
trait InteractsWithEnv
{
    /**
     * @param  array<string, string|null>  $values  null — переменную снять
     * @return mixed результат $callback
     */
    protected function withEnv(array $values, callable $callback): mixed
    {
        $previous = [];

        foreach ($values as $name => $value) {
            $previous[$name] = $_SERVER[$name] ?? $_ENV[$name] ?? (getenv($name) === false ? null : getenv($name));
            $this->putEnvValue($name, $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $name => $value) {
                $this->putEnvValue($name, $value);
            }
        }
    }

    private function putEnvValue(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
