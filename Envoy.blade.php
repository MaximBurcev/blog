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
    rm -rf {{$dirCurrentRelease}}/storage/app;
    cd {{$dirCurrentRelease}};
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
    sudo chmod -R ug+rwx {{$dirShared}};
    sudo chmod -R ug+rwx {{$dirCurrentRelease}};

    echo "# Optimising installation";
    php artisan clear-compiled --env={{$env}};
    php artisan optimize --env={{$env}};
    php artisan config:cache --env={{$env}};
    php artisan cache:clear --env={{$env}};
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
@endtask

@story('post-parse', ['on' => 'production'])
    run_post_parse
@endstory

@task('run_post_parse', ['on' => 'production'])
    cd {{$dirCurrent}}
    php artisan post:parse "{{$url}}" {{ isset($selector) ? '--selector=' . $selector : '' }} {{ isset($sync) ? '--sync' : '' }}
@endtask

