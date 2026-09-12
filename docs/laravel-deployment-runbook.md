# Laravel rewrite — deployment runbook

Последно обновяване: **2026-09-11**.

Целева платформа: **самостоятелен VPS**, управляван от екипа. Приема се, че
OS-ниво пакетите вече са инсталирани (PHP 8.5+ с нужните extensions, Composer,
Node.js 24+/pnpm, nginx или друг reverse proxy, php-fpm, supervisor, git, и
избраната database — SQLite или MySQL/PostgreSQL). Този документ покрива само
application-ниво настройката и routine deploy процедурата.

Свързани документи: [Laravel rewrite план](laravel-rewrite.md) (git/release
стратегия, one-way cutover), [platform audit](laravel-platform-audit.md)
(release gate checklist) и [UAT и cutover checklist](laravel-uat-cutover-checklist.md)
(golden fixtures, rehearsal процедура, sign-off evidence).

## Първоначална инсталация

1. Клонирай repo-то на сървъра, `cd laravel`.
2. `composer install --no-dev --optimize-autoloader`
3. `cp .env.example .env` и попълни production стойности (виж по-долу).
4. `php artisan key:generate`
5. `php artisan migrate --force`
6. `npm ci && npm run build` (или `pnpm install && pnpm run build`, според lockfile-а).
7. `php artisan storage:link`
8. Създай първия administrator през наличната Artisan install команда (виж
   `laravel-platform-audit.md` → "First administrator").
9. Настрой supervisor и cron съгласно секциите по-долу.
10. Направи smoke checks (виж по-долу) преди да пуснеш реален трафик.

## .env стойности, които изискват решение при deploy

Не просто копирай `.env.example` — следните ключове трябва да получат
реални production стойности, иначе съответните capability-та остават
изключени "по подразбиране безопасно":

| Ключ | Ефект ако е празен |
|---|---|
| `APP_KEY`, `APP_URL` | Задължителни за всякаква работа |
| `SLACK_NOTIFICATION_WEBHOOK_URL` / `DISCORD_NOTIFICATION_WEBHOOK_URL` | Slack/Discord notifications мълчаливо се пропускат |
| `METRICS_SCRAPE_TOKEN` | `/metrics` връща 404 (изключен endpoint) |
| Google OAuth ключове (виж `config/services.php`) | Google sign-in бутонът остава скрит/неконфигуриран |
| `QUEUE_CONNECTION` | Production решение: **`redis`**, управляван от Horizon (виж по-долу). Изисква работещ Redis и попълнени `REDIS_*`/`HORIZON_*` ключове |
| Mail (`MAIL_*`) | Email notifications/digest не могат да се доставят |
| `BACKUP_MAX_AGE_DAYS` и `spatie/laravel-backup` destination конфигурация | Backup health check не може да оцени свежест |
| `LOG_CHANNEL`/`LOG_DAILY_DAYS` | Production решение: **file logging** (`daily` channel), 180 дни retention (`LOG_DAILY_DAYS=180`, вече по подразбиране в `.env.example`) |

## Queue worker (Horizon)

Production решение: `QUEUE_CONNECTION=redis`, worker-ите се управляват от
Horizon (вече инсталиран и конфигуриран — `config/horizon.php`,
admin-only dashboard на `/admin/horizon`, `Schedule::command('horizon:snapshot')`
на всеки 5 мин). Supervisor пази `horizon` вдигнат:

```ini
[program:shipstation-checker-horizon]
process_name=%(program_name)s
command=php /path/to/laravel/artisan horizon
directory=/path/to/laravel
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/laravel/storage/logs/horizon.log
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
* * * * * cd /path/to/laravel && php artisan schedule:run >> /dev/null 2>&1
```

## Routine deploy (след първоначалната инсталация)

1. **Write freeze** (по избор, за миграции с schema промяна): спри приема
   на нови requests или пусни `php artisan down` при рискови миграции.
2. `git pull` до release commit-а.
3. `composer install --no-dev --optimize-autoloader`
4. `npm ci && npm run build`
5. `php artisan migrate --force`
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
7. `php artisan horizon:terminate`
8. `supervisorctl restart shipstation-checker-horizon:*` (ако supervisor не
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
само ако database и queue конфигурацията са здрави. `/metrics` трябва да
върне Prometheus текст с `checker_*` броячи.

## Fix-forward policy

Деплойментите не се връщат назад автоматично. При счупен deploy: коригирай
проблема в нов commit и повтори routine deploy процедурата (стъпки 2–10)
максимално бързо. Ако миграция трябва да се отмени, пусни explicit
`php artisan migrate:rollback --step=1 --force` като част от fix-forward
commit-а, не като отделна ръчна операция извън git history-то.

## Все още отворено

- Production observability активиране — Sentry, Pulse, Horizon dashboards и
  `/metrics` вече са инсталирани и wired (виж [platform
  audit](laravel-platform-audit.md) → "Production observability"), но
  `SENTRY_LARAVEL_DSN` и кой получава `/metrics` scrape/alerting остават
  съзнателно отложени production решения. `QUEUE_CONNECTION=redis`+Horizon
  вече е решено (виж "Queue worker (Horizon)" по-горе).
- UAT и cutover repetition — виж [UAT и cutover checklist](laravel-uat-cutover-checklist.md)
  и release gate-а в [platform audit](laravel-platform-audit.md).
