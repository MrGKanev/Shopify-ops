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
| `QUEUE_CONNECTION` | По подразбиране `database`; смени на `redis` само ако Horizon е управляван отделно |
| Mail (`MAIL_*`) | Email notifications/digest не могат да се доставят |
| `BACKUP_MAX_AGE_DAYS` и `spatie/laravel-backup` destination конфигурация | Backup health check не може да оцени свежест |

## Queue worker (supervisor)

По подразбиране `QUEUE_CONNECTION=database` — не изисква Redis. Supervisor
процес пази `queue:work` вдигнат и го рестартира при срив:

```ini
[program:shipstation-checker-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/laravel/artisan queue:work --queue=default,notifications --sleep=3 --tries=3 --backoff=10 --max-time=3600
directory=/path/to/laravel
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/laravel/storage/logs/worker.log
stopwaitsecs=3600
```

`--max-time=3600` кара worker процеса да се самоприключи на час, а
`autorestart=true` го вдига веднага — това е graceful restart без ръчна
намеса и без загуба на in-flight job (worker довършва текущия job преди
изход). При deploy: `php artisan queue:restart` праща сигнал на всички
активни worker-и да приключат след текущия job; supervisor ги вдига наново
с новия код.

Ако по-късно преминете към `QUEUE_CONNECTION=redis` с Horizon, заменете
`command`-а с `php artisan horizon` (Horizon управлява собствените си
worker процеси и вече е добавен в health checks-а и `Schedule::command('horizon:snapshot')`).

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
7. `php artisan queue:restart`
8. `supervisorctl restart shipstation-checker-worker:*` (ако supervisor не
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
  `SENTRY_LARAVEL_DSN`, изборът дали `QUEUE_CONNECTION=redis` за Horizon, и
  кой получава `/metrics` scrape/alerting остават съзнателно отложени
  production решения.
- UAT и cutover repetition — виж [UAT и cutover checklist](laravel-uat-cutover-checklist.md)
  и release gate-а в [platform audit](laravel-platform-audit.md).
