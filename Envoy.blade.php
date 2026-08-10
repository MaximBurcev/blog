@include('vendor/autoload.php')

@setup
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $releaseRotate = 5;
    $timezone = 'Europe/Moscow';
    $date = new datetime('now', new DateTimeZone($timezone));

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

@story('deploy', ['on' => 'production'])
    gitclone
    composer
    env_link
    npm
    config_project
    migrate
    set_current
    queue_restart
    releases_clean
@endstory

@task('releases_clean')
    # Сортировка по имени (каталоги — таймстемпы), а не по mtime: у mtime
    # порядок сбивается от любой записи внутрь релиза, вплоть до того что
    # «самым свежим» становится недоудалённый старый каталог.
    purging=$(ls -d {{$dirReleases}}/* | sort -r | tail -n +{{$releaseRotate}});

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
    echo '# Backup';
    cd {{$dirCurrentRelease}};
    php artisan backup:run
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
    cd {{$dirCurrentRelease}};
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
    cd {{$dirCurrentRelease}};
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

@story('post-parse', ['on' => 'production'])
    run_post_parse
@endstory

@task('run_post_parse', ['on' => 'production'])
    cd {{$dirCurrent}}
    php artisan post:parse "{{$url}}" {{ isset($selector) ? '--selector=' . $selector : '' }} {{ isset($sync) ? '--sync' : '' }}
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
