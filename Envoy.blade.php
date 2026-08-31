@include('vendor/autoload.php')

@setup
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $releaseRotate = 5;
    $timezone = 'Europe/Moscow';
    $date = new datetime('now', new DateTimeZone($timezone));

    # Сервер всего один, поэтому по умолчанию задачи выполняются на
    # production. Без этой переменной ['on' => $on] у задач давал 'on' => null,
    # то есть ПУСТОЙ список серверов: отдельный запуск
    # `./vendor/bin/envoy run up` (аварийное восстановление после упавшего
    # деплоя) молча ничего не делал.
    $on = 'production';

    # Используется в config_project (artisan ... --env=...). Раньше
    # переменная нигде не объявлялась, и флаг уходил пустым: `--env=`.
    $env = 'production';

    if(!($authUser = $_ENV['DEPLOY_USER'] ?? false)) { throw new Exception('--DEPLOY_USER must be specified'); }
    if(!($authKey = $_ENV['DEPLOY_USER_KEY'] ?? false)) { throw new Exception('--DEPLOY_USER_KEY must be specified'); }
    if(!($authServer = $_ENV['DEPLOY_SERVER'] ?? false)) { throw new Exception('--DEPLOY_SERVER must be specified'); }

    $gitBranch = 'main';
    if(!($gitRepository = $_ENV['DEPLOY_REPOSITORY'] ?? false)) { throw new Exception('--DEPLOY_REPOSITORY must be specified'); }


    if(!($dirBase = $_ENV['DEPLOY_PATH'] ?? false)) { throw new Exception('--DEPLOY_PATH must be specified'); }
    $dirShared = $dirBase . '/shared';
    $dirCurrent = $dirBase . '/current';
    $dirReleases = $dirBase . '/releases';
    $dirCurrentRelease = $dirReleases . '/' . $date->format('YmdHis');
@endsetup

@servers(['production' => 'deployer@103.137.249.210', '/home/sail/.ssh/id_rsa'])

{{--
    Push-check перед выкатом.

    Envoy клонирует код с GitHub (задача gitclone), поэтому локальные коммиты,
    которые не были запушены, на прод не доезжают — при этом деплой отчитывается
    успехом и выкатывает СТАРЫЙ код. Инцидент 28.08.2026: выкатили релиз без
    нужных коммитов, и никто этого не заметил.

    Проверка в @before, а не в @setup, сознательно: @setup выполняется при
    ЛЮБОМ вызове envoy, включая аварийные `envoy run up` / `envoy run rollback`,
    когда локальное дерево может быть в любом состоянии — блокировать их
    push-check'ом нельзя. Проверка привязана к gitclone — единственной задаче,
    которая забирает код с GitHub.

    Если `git fetch` не проходит (нет сети или доступа к GitHub), деплой
    ПАДАЕТ, а не предупреждает: предупреждение в простыне вывода никто не
    читает, и повтор инцидента 28.08 хуже, чем лишний прерванный деплой.
--}}
@before
    if ($task === 'gitclone') {
        $git = 'git -C ' . escapeshellarg(__DIR__);

        exec($git . ' fetch origin ' . escapeshellarg($gitBranch) . ' 2>&1', $fetchOutput, $fetchExit);
        if ($fetchExit !== 0) {
            throw new Exception("git fetch origin {$gitBranch} не прошёл (код {$fetchExit}): " . implode(' ', $fetchOutput) . '. Деплой остановлен намеренно: без свежего fetch нельзя гарантировать, что origin содержит локальные коммиты (инцидент 28.08.2026).');
        }

        $unpushed = (int) trim((string) shell_exec($git . ' rev-list --count ' . escapeshellarg("origin/{$gitBranch}..HEAD")));
        if ($unpushed > 0) {
            throw new Exception("В локальной ветке {$unpushed} незапушенных коммитов. Envoy клонирует код с GitHub и выкатит его БЕЗ этих коммитов (инцидент 28.08.2026). Сначала выполните: git push origin {$gitBranch}");
        }
    }
@endbefore

{{--
    Порядок шагов важен:

    - backup — до миграций: точка восстановления БД, если миграция ляжет
      криво. migrate:rollback запрещён (prohibitDestructiveCommands, см.
      CLAUDE.md), поэтому бэкап — единственный путь назад для данных.
    - down/up — maintenance-окно вокруг миграций и переключения симлинка.
      Защиты от падения между down и up НЕТ (в Envoy нет try/finally): если
      деплой упадёт на migrate/set_current/cache-clear, сайт останется
      выключенным (503). Починка: `./vendor/bin/envoy run up` — задача
      отдельная и работает сама по себе.
    - cache-clear — ПОСЛЕ set_current, но до up: shared-кэш переживает деплой,
      и в окне между cache:clear сборки (config_project) и переключением
      симлинка старый код успевает закэшировать устаревшие ответы (инцидент
      28.08.2026: бот закэшировал feed.xml без новых элементов на час TTL).
      Пока сайт в maintenance, перекэшировать нечему.
    - health_check — строго ПОСЛЕ up: иначе при его падении сайт остался бы
      выключенным.
--}}
@story('deploy', ['on' => 'production'])
    gitclone
    composer
    env_link
    npm
    config_project
    backup
    down
    migrate
    set_current
    cache-clear
    up
    queue_restart
    health_check
    releases_clean
@endstory

@task('releases_clean')
    # Сортировка по имени (каталоги — таймстемпы), а не по mtime: у mtime
    # порядок сбивается от любой записи внутрь релиза, вплоть до того что
    # «самым свежим» становится недоудалённый старый каталог.
    #
    # `tail -n +N` выводит начиная с N-й строки, то есть пропускает N-1.
    # Поэтому +$releaseRotate оставлял 4 релиза вместо заявленных 5 —
    # нужен +($releaseRotate + 1).
    purging=$(ls -d {{$dirReleases}}/* | sort -r | tail -n +{{$releaseRotate + 1}});

    if [ "$purging" != "" ]; then
        echo "# Purging old releases: $purging;"
        # sudo обязателен: внутри релиза Apache (www-data) насоздавал файлов
        # кэша в storage/framework/cache, у deployer нет прав их удалить —
        # без sudo шаг падал и весь деплой отчитывался ошибкой.
        sudo rm -rf $purging;
    else
        echo "# No releases found for purging at this time";
    fi
@endtask

@task('gitclone', ['on' => $on])
    echo "# Gitclone task"

    mkdir -p {{$dirCurrentRelease}}
    git clone --depth 1 -b {{$gitBranch}} {{$gitRepository}} {{$dirCurrentRelease}}

    echo "# Repository has been cloned"
@endtask

@task('composer', ['on' => $on])
    echo "# Composer task"

    cd {{$dirCurrentRelease}}
    composer install --no-interaction --quiet --no-dev --prefer-dist --optimize-autoloader

    echo "# Composer dependencies have been installed"
@endtask

@task('env_link', ['on' => $on])
    echo "# Linking .env before asset build";

    # .env обязан появиться ДО `npm run build`: Vite подставляет import.meta.env
    # на этапе сборки, читая .env из корня проекта. Раньше симлинк создавался
    # только в config_project, то есть ПОСЛЕ npm — и все VITE_* уходили в бандл
    # пустыми. Из-за этого laravel-echo собирался без ключа и хоста Reverb
    # (проверено: ключа приложения в public/build/assets/app-*.js не было).
    cd {{$dirCurrentRelease}};
    ln -nfs {{$dirBase}}/.env .env;
@endtask

@task('npm', ['on' => $on])
    echo "# Npm task"

    cd {{$dirCurrentRelease}}
    export NVM_DIR="$HOME/.nvm"
    [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"  # This loads nvm
    [ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"  # This loads nvm bash_completion
    npm install
    npm run build

    echo "# Npm dependencies have been installed"
@endtask

@task('backup', ['on' => $on])
    echo '# Backup (DB only)';
    # Только база (--only-db), а не полный backup:run: полный архив включает
    # storage/app/public (картинки постов) и собирается заметно дольше —
    # перед каждым деплоем это лишнее окно. Полный бэкап и так делает
    # планировщик ежедневно (app/Console/Kernel.php, 01:30), а здесь цель —
    # точка восстановления БД на случай кривой миграции (migrate:rollback
    # запрещён, см. CLAUDE.md).
    #
    # Выполняется в current (живом релизе), а не в собираемом: бэкапим ту БД,
    # которую сейчас обслуживает прод.
    cd {{$dirCurrent}};
    php artisan backup:run --only-db;
@endtask

@task('config_project', ['on' => $on])
    echo "# Config project task";

    echo "# Linking storage directory";
    # .htaccess из репозитория обязан доехать до shared: storage/app ниже
    # подменяется симлинком, и версия из релиза была бы просто выброшена.
    # Файл запрещает отдавать из /storage исполняемое и активное содержимое —
    # каталог наполняет скрейпер файлами со сторонних сайтов.
    mkdir -p {{$dirShared}}/storage/app/public;
    cp {{$dirCurrentRelease}}/storage/app/public/.htaccess {{$dirShared}}/storage/app/public/.htaccess;
    rm -rf {{$dirCurrentRelease}}/storage/app;
    cd {{$dirCurrentRelease}};
    # storage/app подменяется симлинком на shared, поэтому storage/app/imports
    # из репозитория до прода не доезжает — создаём его в shared. Каталог
    # ограничивает --html-file у post:parse (config releases.html_import_dir):
    # без него realpath() не резолвится и любой импорт из файла отвергается.
    mkdir -p {{$dirShared}}/storage/app/imports;
    ln -nfs {{$dirShared}}/storage/app storage/app;

    # Шарилось только storage/app, поэтому сессии, кэш и логи жили ВНУТРИ
    # релиза: каждый выкат разлогинивал всех посетителей и ломал CSRF у
    # открытых форм, сбрасывал cache-локи ShouldBeUnique, а логи уезжали
    # вместе со старым релизом и удалялись при ротации пятого.
    echo "# Linking shared session/cache/log directories";
    mkdir -p {{$dirShared}}/storage/framework/sessions;
    mkdir -p {{$dirShared}}/storage/framework/cache/data;
    mkdir -p {{$dirShared}}/storage/framework/views;
    mkdir -p {{$dirShared}}/storage/logs;
    rm -rf storage/framework/sessions storage/framework/cache storage/framework/views storage/logs;
    ln -nfs {{$dirShared}}/storage/framework/sessions storage/framework/sessions;
    ln -nfs {{$dirShared}}/storage/framework/cache storage/framework/cache;
    ln -nfs {{$dirShared}}/storage/framework/views storage/framework/views;
    ln -nfs {{$dirShared}}/storage/logs storage/logs;

    php artisan storage:link

    echo "# Linking .env file";
    cd {{$dirCurrentRelease}};
    ln -nfs {{$dirBase}}/.env .env;

    sudo chgrp -R www-data {{$dirShared}};
    sudo chgrp -R www-data {{$dirCurrentRelease}};

    # Код релиза — www-data только на чтение. Раньше здесь стояло ug+rwx на
    # весь релиз: веб-пользователь мог писать в app/, vendor/ и public/index.php,
    # то есть любой примитив записи из веба становился постоянным вебшеллом.
    # Писать нужно только в shared (storage) — туда права и выдаём.
    # Заглавная X вешает бит исполнения на каталоги, но не на обычные файлы.
    sudo chmod -R g+rX,g-w,o-rwx {{$dirCurrentRelease}};
    sudo chmod -R ug+rwX,o-rwx {{$dirShared}};

    # setgid на каталогах shared: подкаталоги, созданные ЛЮБЫМ пользователем,
    # наследуют группу www-data вместо группы создателя.
    #
    # Без этого artisan, запущенный от deployer (шаги деплоя ниже, планировщик,
    # ручной прогон команды), создавал в storage/framework/cache каталоги с
    # группой deployer — и веб-процесс в них писать не мог. Ронялся весь сайт
    # пятисоткой на file_put_contents, причём не при деплое, а позже, когда
    # кэш впервые попадал в такой каталог.
    sudo find {{$dirShared}} -type d -exec chmod g+s {} \; ;

    echo "# Optimising installation";
    php artisan clear-compiled --env={{$env}};
    php artisan optimize --env={{$env}};
    php artisan config:cache --env={{$env}};
    php artisan cache:clear --env={{$env}};

    # ПОСЛЕ optimize, а не вместе с общим chmod выше: config:cache создаёт
    # bootstrap/cache/config.php уже после него, с umask деплойщика — файл
    # получался -rw-rw-r-- deployer:deployer, то есть APP_KEY, DB_PASSWORD,
    # MEILISEARCH_KEY и BACKUP_ARCHIVE_PASSWORD в открытом виде читал любой
    # локальный аккаунт сервера. www-data нужен только read.
    echo "# Securing compiled config";
    sudo chgrp -R www-data {{$dirCurrentRelease}}/bootstrap/cache;
    sudo chmod -R g+rX,g-w,o-rwx {{$dirCurrentRelease}}/bootstrap/cache;
@endtask

@task('down', ['on' => $on])
    echo "# Down task"
    # ВАЖНО: выполняется в current (живом релизе), а не в собираемом. В shared
    # выносятся только storage/framework/{sessions,cache,views} и логи, а файл
    # storage/framework/down остаётся ВНУТРИ релиза — `artisan down` в новом
    # релизе живой сайт бы вообще не закрыл. Обратная сторона: после
    # set_current новый релиз down-файла не содержит, и сайт открывается сам;
    # задача up ниже — страховка на этот случай.
    #
    # Отдельная задача, чтобы при упавшем между down и up деплое сайт можно
    # было поднять без повторного выката: `./vendor/bin/envoy run up`.
    cd {{$dirCurrent}};
    php artisan down;
@endtask

@task('migrate', ['on' => $on])
    echo "# Running migrations";
    cd {{$dirCurrentRelease}};
    php artisan migrate --env=production --force;
@endtask

@task('key-generate', ['on' => $on])
    cd {{ $dirCurrentRelease }}
    php artisan key:generate
@endtask

@task('up', ['on' => $on])
    echo "# Up task"
    # После set_current current указывает на новый релиз, где down-файла нет
    # (см. комментарий в down), так что в story это страховочный no-op.
    # Реальная работа задачи — аварийный запуск `./vendor/bin/envoy run up`
    # после деплоя, упавшего между down и up: current тогда всё ещё указывает
    # на старый релиз, где down-файл и лежит.
    cd {{$dirCurrent}};
    php artisan up;
@endtask

@task('set_current', ['on' => $on])
    echo '# Linking current release';
    ln -nfs {{$dirCurrentRelease}} {{$dirCurrent}};
@endtask

@task('queue_restart', ['on' => $on])
    # Без этого воркеры продолжали исполнять код релиза, из которого стартовали:
    # он оставался в их памяти до перезапуска и через пять деплоев физически
    # удалялся шагом releases_clean. Выполняется ПОСЛЕ set_current, чтобы
    # поднявшийся заново воркер подхватил уже новый релиз.
    echo '# Restarting queue workers';
    cd {{$dirCurrent}};
    php artisan queue:restart;

    # Reverb — тоже долгоживущий процесс, но queue:restart его не касается:
    # тот сигнал слушают только воркеры очереди. Без явного перезапуска
    # WebSocket-сервер продолжал бы крутить код релиза, из которого стартовал,
    # и сломался бы, когда releases_clean физически удалит этот каталог.
    # `|| true` — чтобы деплой не падал на машине, где программы ещё нет.
    echo '# Restarting Reverb';
    sudo supervisorctl restart blog-reverb || true;
@endtask

@task('cache-clear', ['on' => 'production'])
    # Прикладной кэш лежит в shared/ и переживает деплой. В окне между
    # cache:clear сборки и переключением Apache на новый релиз старый код
    # может успеть положить в кэш устаревший ответ (поймано на feed.xml
    # 28.08.2026: бот закэшировал RSS без новых элементов на час TTL).
    echo '# Clearing application cache';
    cd {{$dirCurrent}};
    # sudo обязателен: файлы кэша в shared/ созданы Apache (www-data),
    # у deployer нет прав их удалить — как и в releases_clean.
    sudo php artisan cache:clear;
@endtask

@task('health_check', ['on' => $on])
    echo '# Health check';

    # Дёргаем публичный APP_URL из .env, а не http://127.0.0.1/up: запрос без
    # Host-заголовка Apache может отдать default-vhost, а не блог. /up —
    # собственный маршрут (routes/health.php), закрыт в robots.txt, но
    # доступен; отвечает 200 или 503 (app/Http/Controllers/HealthController.php).
    app_url=$(grep -E '^APP_URL=' {{$dirCurrent}}/.env | head -1 | cut -d= -f2-);
    if [ -z "$app_url" ]; then
        app_url="http://127.0.0.1";
    fi;

    # Известное ограничение: mod_php кэширует realpath ~2 минуты, поэтому сразу
    # после set_current запрос может обслужить СТАРЫЙ релиз — он тоже ответит
    # 200. Шаг проверяет «сайт жив после выката», а не «отвечает именно новый
    # код»; полноценная проверка версии релиза — отдельная история.
    #
    # `curl -sf` на не-2xx возвращает ненулевой код: задача падает, и деплой
    # отчитывается ошибкой. Сайт при этом уже поднят (up идёт раньше), то есть
    # падение health-check НЕ оставляет сайт в maintenance.
    curl -sf --max-time 10 "$app_url/up" > /dev/null;
    echo '# Health check passed';
@endtask

{{--
    Откат на предыдущий релиз: переключение симлинка current + перезапуск
    долгоживущих процессов + сброс shared-кэша.

    Миграции НЕ откатываются: DB::prohibitDestructiveCommands() запрещает
    migrate:rollback (см. CLAUDE.md), а строка запрета снимается только руками
    на время осознанной операции. Поэтому откат безопасен только для кода,
    обратно совместимого с уже накатанными миграциями. Если миграции нового
    релиза несовместимы со старым кодом — чинить надо вперёд, а не откатом.

    Запуск: ./vendor/bin/envoy run rollback
--}}
@story('rollback', ['on' => 'production'])
    rollback_release
@endstory

@task('rollback_release', ['on' => 'production'])
    echo '# Rolling back to previous release';

    # Предыдущий релиз — второй свежий каталог: имена — таймстемпы, поэтому
    # сортировки по имени достаточно (см. releases_clean про mtime).
    previous=$(ls -d {{$dirReleases}}/* | sort -r | sed -n '2p');
    if [ -z "$previous" ]; then
        echo '# Предыдущего релиза нет — откатывать не на что' >&2;
        exit 1;
    fi;

    echo "# Switching current to $previous";
    ln -nfs $previous {{$dirCurrent}};
    cd {{$dirCurrent}};

    # На случай, если откат делается после деплоя, упавшего между down и up:
    # down-файл остался в storage/framework/down того релиза, на который мы
    # сейчас переключились, — без up сайт остался бы в maintenance.
    php artisan up;

    # Воркеры и Reverb продолжают исполнять код релиза, из которого стартовали
    # (см. queue_restart) — возвращаем их на откаченный код тем же способом.
    php artisan queue:restart;
    sudo supervisorctl restart blog-reverb || true;

    # shared-кэш переживает деплой и может содержать ответы уже откаченного
    # нового кода (инцидент 28.08.2026 с feed.xml). sudo — файлы кэша в shared/
    # создавал www-data, у deployer нет прав их удалить.
    sudo php artisan cache:clear;

    # ВНИМАНИЕ: mod_php кэширует realpath ~2 минуты — Apache может продолжать
    # отдавать прежний релиз. При необходимости: sudo systemctl reload apache2.
    echo '# Rolled back';
@endtask

@story('post-parse', ['on' => 'production'])
    run_post_parse
@endstory

@task('posts-stats', ['on' => 'production'])
    # Срез контента прода: сколько всего, сколько опубликовано, сколько
    # черновиков ждут вычитки. Нужен, чтобы решения про публикацию
    # принимались по цифрам, а не по ощущению от админки.
    # llm_сегодня/llm_вчера — контроль, что полуденная партия
    # posts:translate-drafts реально отработала: числа черновиков сами по себе
    # этого не показывают (перепереведённый черновик остаётся черновиком до
    # вычитки). черновиков_llm — сколько из них уже переведено Gemini
    # (translated_by начинается с 'gemini'), это и есть прогресс разбора.
    cd {{$dirCurrent}};
    php artisan tinker --execute="printf('всего=%d опубликовано=%d черновиков_ok=%d failed=%d ревью_перевода=%d llm_сегодня=%d llm_вчера=%d черновиков_llm=%d'.PHP_EOL, \App\Models\Post::count(), \App\Models\Post::where('published', true)->count(), \App\Models\Post::where('published', false)->where('parse_status', 'ok')->count(), \App\Models\Post::where('parse_status', 'failed')->count(), \App\Models\Post::where('translation_incomplete', true)->count(), \App\Models\LlmCall::whereDate('created_at', today())->count(), \App\Models\LlmCall::whereDate('created_at', today()->subDay())->count(), \App\Models\Post::where('published', false)->where('parse_status', 'ok')->where('translated_by', 'like', 'gemini%')->count());"
@endtask

@task('run_post_parse', ['on' => 'production'])
    cd {{$dirCurrent}}
    php artisan post:parse "{{$url}}" {{ isset($selector) ? '--selector=' . $selector : '' }} {{ isset($sync) ? '--sync' : '' }}
@endtask


{{--
    Установка cron для планировщика Laravel.

    Отдельная команда, а НЕ шаг деплоя: запись в /etc/cron.d переживает выкаты,
    а переписывать её каждый раз — лишний sudo в горячем пути деплоя.

    Заводить обязательно: без этой строки app/Console/Kernel.php не выполняется
    вообще — ни backup:run, ни telescope:prune, ни чистки таблиц. Именно так и
    было до 11.08.2026: расписание лежало в коде, бэкапов при этом не
    существовало. (news:import здесь больше не пример — он снят с расписания
    24.08.2026, см. комментарий в Kernel.php.)

    Пользователь www-data — тот же, под которым работают Apache и queue:work.
    Под root артефакты в storage/ получали бы root:root и отбирали запись у веба.
    umask 002 — чтобы deployer (он в группе www-data) мог читать и выгружать архивы.

    Запуск: ./vendor/bin/envoy run scheduler-cron
--}}
@story('scheduler-cron', ['on' => 'production'])
    install_scheduler_cron
@endstory

@task('install_scheduler_cron', ['on' => 'production'])
    echo '# Installing /etc/cron.d/blog-scheduler';

    # stdout глушим: schedule:run печатает строку на каждый холостой запуск,
    # это 1440 строк в сутки. stderr — наоборот, единственный сигнал о том,
    # что планировщик падает ещё до записи в laravel.log.
    printf '%s\n' \
        'SHELL=/bin/sh' \
        'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
        '* * * * * www-data umask 002 && cd {{$dirCurrent}} && php artisan schedule:run >/dev/null 2>>{{$dirShared}}/storage/logs/scheduler-error.log' \
        | sudo tee /etc/cron.d/blog-scheduler > /dev/null;

    # cron молча игнорирует файл с правами шире 0644 или с чужим владельцем.
    sudo chown root:root /etc/cron.d/blog-scheduler;
    sudo chmod 0644 /etc/cron.d/blog-scheduler;

    echo '# Installed:';
    cat /etc/cron.d/blog-scheduler;
@endtask


{{--
    Пересоздание контейнера FlareSolverr — headless-браузера, которым парсер
    обходит antibot-проверки (см. config/releases.php, challenge_solver_url).

    Отдельная команда, а НЕ шаг деплоя: контейнер живёт сам по себе с
    --restart=unless-stopped и переживает перезагрузку сервера. Пересоздавать
    его на каждый выкат незачем — это только сбрасывало бы браузерную сессию.

    Запуск: ./vendor/bin/envoy run challenge_solver
--}}
@story('challenge-solver', ['on' => 'production'])
    restart_challenge_solver
@endstory

@task('restart_challenge_solver', ['on' => 'production'])
    echo '# Recreating FlareSolverr container';

    sudo docker pull -q ghcr.io/flaresolverr/flaresolverr:latest;
    sudo docker rm -f flaresolverr 2>/dev/null || true;

    # --memory=900m проверено опытом: с 512m Chrome не укладывался в challenge
    # и FlareSolverr отдавал «Timeout after 60.0 seconds». Пик нужен только на
    # время решения, в покое контейнер держит ~110 МБ.
    #
    # Порт публикуется ТОЛЬКО на 127.0.0.1: наружу сервис торчать не должен —
    # он умеет ходить по произвольным URL от имени сервера.
    sudo docker run -d --name flaresolverr \
        -p 127.0.0.1:8191:8191 \
        --memory=900m --memory-swap=900m \
        --restart=unless-stopped \
        -e LOG_LEVEL=warning \
        ghcr.io/flaresolverr/flaresolverr:latest;

    echo '# Waiting for readiness';
    for i in $(seq 1 40); do sleep 5; curl -sf --max-time 5 http://127.0.0.1:8191/ >/dev/null && echo '# FlareSolverr is ready' && break; done;
@endtask

{{--
    Tesseract OCR — им DiagramTranslatorService читает текст на картинках
    (диаграммы, скриншоты, обложки статей), чтобы перевести его на русский.

    Отдельная команда, а НЕ шаг деплоя: это системный пакет, он ставится один
    раз на машину и переживает выкаты. Локально его ставит docker/8.4/Dockerfile
    — на сервере ставить было некому, и до 17.08.2026 перевод текста на
    картинках не работал ни разу: 124 записи «no text detected» и ноль
    перерисованных картинок. Ошибку скрывал `2>/dev/null` в вызове, из-за
    которого «command not found» выглядел как «на картинке нет текста».

    Языковых пакетов нужно ДВА. Английский — чтобы прочитать исходный текст.
    Русский — чтобы узнать уже переведённую картинку и не тронуть её: без него
    английская модель читает кириллицу как похожую латиницу («Топ-16
    обязательных ресурсов» → «Ton-16 pecypcosB»), перевод этого мусора ложится
    поверх нормального текста, а файл переписывается на месте.

    Оба пакета — жёсткое рантайм-требование: сервис зовёт tesseract с
    `-l eng+rus`, и при отсутствии любого из них падает на КАЖДОЙ картинке.
    Поэтому задачу надо выполнить ДО выката кода, который на неё рассчитывает.

    Запуск: ./vendor/bin/envoy run ocr-install
--}}
@story('ocr-install', ['on' => 'production'])
    install_ocr
@endstory

@task('install_ocr', ['on' => 'production'])
    echo '# Installing Tesseract OCR';

    # sudo env VAR=val, а не sudo VAR=val: при env_reset в sudoers второй
    # вариант отвергается («not allowed to set the following environment
    # variables») и задача падает целиком.
    #
    # Языковой набор тот же, что в docker/8.4/Dockerfile: расхождение сред —
    # ровно то, из-за чего OCR молчал на проде, и заводить его заново незачем.
    sudo apt-get update -qq;
    sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq tesseract-ocr tesseract-ocr-eng tesseract-ocr-rus;

    # Шрифт с кириллицей нужен уже не OCR, а отрисовке перевода поверх картинки
    # (DiagramTranslatorService::FONT). Без него GD молча рисует пустоту.
    test -f /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf \
        || sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq fonts-dejavu-core;

    # Проверка обязана уметь провалить задачу. `tesseract --version | head -1`
    # этого не умеет: под set -e статус пайплайна берётся от head и всегда 0,
    # то есть шаг рапортовал бы успех и при неустановленном пакете.
    echo '# Verifying';
    command -v tesseract;
    # Обе модели: сервис зовёт `-l eng+rus`, и нехватка любой роняет каждый
    # вызов («Error opening data file ... traineddata»).
    tesseract --list-langs 2>&1 | grep -qx eng;
    tesseract --list-langs 2>&1 | grep -qx rus;
    test -f /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf;
    echo "# OCR ready: $(tesseract --version 2>&1 | head -1), langs: eng+rus";
@endtask
