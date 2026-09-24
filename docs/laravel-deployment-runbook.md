# Laravel rewrite — deployment runbook

Последно обновяване: **2026-09-24**.

Целева платформа: **самостоятелен VPS**, управляван от екипа, без load
balancer пред приложението (единичен сървър — ако това се промени, добави
`TRUSTED_PROXIES` в `.env`, виж [`configuration.md`](configuration.md)).
Приема се, че OS-ниво пакетите вече са инсталирани (PHP 8.5+ с нужните
extensions, Composer, Node.js 24+/pnpm 12.5.1, nginx/apache + php-fpm,
supervisor, git, и избраната database — SQLite или MySQL/MariaDB). Приложението
живее в repo-то root ниво (няма отделна `laravel/` поддиректория). Този
документ покрива application-ниво настройката и routine deploy
процедурата. Проверимите условия за production sign-off са в
[`laravel-uat-cutover-checklist.md`](laravel-uat-cutover-checklist.md).

## Release gate

Преди production deploy запиши точния release commit и резултата от CI за
него. `.github/workflows/ci.yml` изпълнява `composer audit`, `composer test`,
`composer analyse`, `vendor/bin/pint --test`, `pnpm install --frozen-lockfile`,
`pnpm build` и `pnpm audit --audit-level moderate` на PHP 8.5, Node.js 24 и
pnpm 12.5.1. Локалният `composer ci` изпълнява същите проверки, но негов
успех на друг commit не замества CI резултата на release commit-а. В текущия
workflow няма differential/parity test job.

## Първоначална инсталация

1. Клонирай repo-то на сървъра.
2. `composer install --no-dev --optimize-autoloader`
3. `cp .env.example .env` и попълни production стойности (виж по-долу).
4. `php artisan key:generate`
5. `php artisan migrate --force`
6. `pnpm install --frozen-lockfile && pnpm build`
7. `php artisan storage:link`
8. Създай първия administrator: през `php artisan ops:install` (shell достъп)
   или `/install` в браузъра (без shell достъп — виж
   [`installation.md`](installation.md#hostedno-shell-installation)). И двата
   пътя приемат SMTP/Slack/Discord настройки директно при инсталацията.
9. Настрой supervisor и cron съгласно секциите по-долу.
10. Потвърди release gate-а по-горе за deploy-вания commit.
11. Направи smoke checks (виж по-долу) преди да пуснеш реален трафик.

## .env стойности, които изискват решение при deploy

Не просто копирай `.env.example` — следните ключове трябва да получат
реални production стойности, иначе съответните capability-та остават
изключени "по подразбиране безопасно":

| Ключ | Ефект ако е празен |
|---|---|
| `APP_KEY`, `APP_URL` | Задължителни за всякаква работа |
| `SESSION_SECURE_COOKIE`, `HSTS_ENABLED`, `TRUSTED_PROXIES` | За production HTTPS задай secure cookie; включи HSTS след end-to-end TLS проверка; посочи само действителните proxy IP/CIDR, ако има reverse proxy |
| `SLACK_NOTIFICATION_WEBHOOK_URL` / `DISCORD_NOTIFICATION_WEBHOOK_URL` | Slack/Discord notifications мълчаливо се пропускат |
| `METRICS_SCRAPE_TOKEN` | `/metrics` връща 404 (изключен endpoint) |
| Google OAuth ключове (виж `config/services.php`) | Google sign-in бутонът остава скрит/неконфигуриран |
| `QUEUE_CONNECTION` | Production решение: **`redis`**, управляван от Horizon (виж по-долу). Изисква работещ Redis и попълнени `REDIS_*`/`HORIZON_*` ключове |
| `CACHE_STORE` / `CACHE_PREFIX` | **`redis`**; prefix-ът е уникален за deployment-а. Cache-ът е само за locks, unique jobs и health heartbeats; Shopify/ShipStation/report reads не се кешират |
| Mail (`MAIL_*`) | Email notifications/digest не могат да се доставят |
| `BACKUP_MAX_AGE_DAYS` и `spatie/laravel-backup` destination конфигурация | Backup health check не може да оцени свежест |
| `LOG_CHANNEL`/`LOG_DAILY_DAYS` | Production решение: **file logging** (`daily` channel), 180 дни retention (`LOG_DAILY_DAYS=180`, вече по подразбиране в `.env.example`) |

## Queue worker (Horizon)

Production решение: `QUEUE_CONNECTION=redis`, worker-ите се управляват от
Horizon (вече инсталиран и конфигуриран — `config/horizon.php`,
admin-only dashboard на `/admin/horizon`, `Schedule::command('horizon:snapshot')`
на всеки 5 мин). Supervisor пази `horizon` вдигнат:

```ini
[program:shopify-ops-horizon]
process_name=%(program_name)s
command=php /path/to/shopify-ops/artisan horizon
directory=/path/to/shopify-ops
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/shopify-ops/storage/logs/horizon.log
stopwaitsecs=3600
```

При deploy: `php artisan horizon:terminate` праща сигнал на Horizon да
приключи текущите jobs и излезе gracefully; supervisor го вдига наново
с новия код (аналог на `queue:restart` за non-Horizon setup).

Изисква работещ Redis сървър и попълнени `REDIS_HOST`/`REDIS_PORT`/
`REDIS_PASSWORD` (и `HORIZON_REDIS_CONNECTION`, ако различен от `default`)
в production `.env`-а.

## Scheduler (cron)

Един cron entry стартира вградения Laravel scheduler, който вече съдържа
всички нужни задачи (`routes/console.php`) — email digest, activity log
pruning, health heartbeats, backup run/monitor/clean:

```
* * * * * cd /path/to/shopify-ops && php artisan schedule:run >> /dev/null 2>&1
```

## Routine deploy (след първоначалната инсталация)

1. **Write freeze** (по избор, за миграции с schema промяна): спри приема
   на нови requests или пусни `php artisan down` при рискови миграции.
2. Изтегли и checkout-ни точно одобрения release commit; потвърди неговия
   SHA и успешния CI run преди следващите стъпки.
3. `composer install --no-dev --optimize-autoloader`
4. `pnpm install --frozen-lockfile && pnpm build`
5. `php artisan migrate --force`
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
7. `php artisan horizon:terminate`
8. `supervisorctl restart shopify-ops-horizon:*` (ако supervisor не
   е уловил рестарта автоматично)
9. `php artisan up` (ако е бил спрян в стъпка 1)
10. Smoke checks (виж по-долу).

## Smoke checks след всеки deploy

```bash
curl -fsS https://<host>/up
curl -fsS https://<host>/ready
curl -fsS -H "Authorization: Bearer $METRICS_SCRAPE_TOKEN" https://<host>/metrics | head -5
```

`/up` и `/ready` трябва да върнат 200. `/ready` съдържа `{"status":"ready"}`
само ако database, cache, queue, worker и scheduler са здрави. Добра
production практика е външен uptime monitor да проверява `/ready`
поне веднъж минутно. Доставчикът и мястото на monitor-а са решение на
deployment owner-а; препоръчително е да не работи в същия process като
Laravel scheduler-а. `/metrics` трябва да върне Prometheus текст с
`checker_*` броячи.

## Backup ownership, retention и restore rehearsal

Deployment owner-ът избира destination-а. Може да е отделен mounted
disk/сървър чрез `BACKUP_LOCAL_PATH`, S3-compatible disk или друг Laravel
filesystem disk в `BACKUP_DISKS`. Добра практика е поне едно копие
да е извън application VPS-а и credentials-ите за destination-а да могат
да пишат backup-и, без да дават по-широк достъп от нужното.

Текущата retention policy в `config/backup.php` пази всички backup-и
за 7 дни, дневни до 16 дни, седмични за 8 седмици, месечни за 4 месеца
и годишни за 2 години, ограничени и от `BACKUP_MAX_STORAGE_MB`. Owner-ът
може да избере по-дълъг срок според бизнес и regulatory нуждите.

Проверка на backup-ите:

```bash
php artisan backup:run
php artisan backup:list
php artisan backup:monitor
```

Restore на живо (`php artisan backup:restore {path?} {--force}`) взима
последния архив по подразбиране (или посочен път на диска `backups`),
възстановява database dump-а и файловете от `storage/app/private`, и пита за
потвърждение преди да презапише текущата база. Поддържа SQLite и
MySQL/MariaDB (изисква `mysql` client в `PATH` за последните). Виж
[`operations.md`](operations.md#backups).

Преди да разчиташ на него в реален инцидент, провери го поне веднъж в
изолирана среда, различна от production:

1. Създай изолиран rehearsal host/database без production credentials,
   workers, scheduler и outbound notification delivery.
2. Осигури същия `APP_KEY`, с който са шифровани store credentials в архива,
   и отделни rehearsal настройки за database, URL, queue и outbound доставки.
   Осигури архива на диска `backups`, като запазиш нужния
   `BACKUP_ARCHIVE_PASSWORD`, ако архивът е шифрован.
3. Пусни `php artisan backup:restore --force` само срещу изолираната rehearsal
   база и провери изхода
   (възстановен dump filename и брой файлове).
4. Провери правата върху възстановените файлове, login, users/stores,
   decrypt на store credentials, броя `run_logs`/`audit_snapshots`, един
   saved report и един CSV download. Пусни `/up`; `/ready` може да е 503,
   докато rehearsal worker/scheduler са съзнателно спрени.
5. Запази дата, среда, commit, archive ID, резултати и отговорник в UAT
   evidence запис; почисти rehearsal данните според политиката на средата.

При encrypted archive (`BACKUP_ARCHIVE_PASSWORD`) командата чете паролата от
`config('backup.backup.password')` — не се въвежда interactive.

## Fix-forward policy

Деплойментите не се връщат назад автоматично. При счупен deploy: коригирай
проблема в нов commit и повтори routine deploy процедурата (стъпки 2–10)
максимално бързо. Ако миграция трябва да се отмени, пусни explicit
`php artisan migrate:rollback --step=1 --force` като част от fix-forward
commit-а, не като отделна ръчна операция извън git history-то.

## Все още отворено

- Production observability активиране — Sentry, Pulse, Horizon dashboards и
  `/metrics` вече са инсталирани и wired, но `SENTRY_LARAVEL_DSN` и кой получава
  `/metrics` scrape/alerting остават съзнателно отложени production решения.
  `QUEUE_CONNECTION=redis`+Horizon вече е решено (виж "Queue worker (Horizon)"
  по-горе). Условията за живото пускане са в
  [UAT checklist-а](laravel-uat-cutover-checklist.md).
- Реалните SMTP, restore, TLS/proxy, worker/scheduler и notification проверки
  изискват доказателства от целевата среда; следи ги в
  [UAT checklist-а](laravel-uat-cutover-checklist.md).
