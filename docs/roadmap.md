# Shopify Ops — Feature Roadmap

_Last updated: 2026-06-27_

Базира се на преглед на текущия проект и анализ на това, което предлагат конкурентни инструменти (Lifetimely, NoFraud, Tacey, Riskified, ShipStation native dashboard и др.).

---

## Текущо покритие (вече съществуват)

Проектът вече покрива добре следните области:

- Shopify ↔ ShipStation order audit (missing, orphan, conflict detection)
- Fraud signals: country mismatch, email check, address scan, repeat refunds, discount abuse
- Inventory: oversell risk, aging zero-stock
- Fulfilment: SLA breaches, partial stalls, voided shipments, address changes
- Customer lookup, order timeline, tag search, metafields
- Slack notifications с rules engine
- Multi-store support (`stores.json`)
- Background job queue + worker
- Bundle check с `required_items` config
- Tag policy validation

---

## Приоритет 1 — Висок (следващи спринтове)

### 1.1 Email / SMTP Notifications

**Проблем:** Единственият канал за известия е Slack. Не всички екипи ползват Slack.

**Какво да се направи:**
- `SMTP_*` ENV vars + PHPMailer / Symfony Mailer
- `EmailNotifier` клас по образец на `SlackNotifier`
- Digest email: daily/weekly summary на audit резултати
- Alert email при критични прагове (missing orders > N)
- Configurable в Settings страницата

---

### 1.2 Return / RMA Tracker

**Проблем:** Системата не проследява върнати поръчки освен `repeat_refunds`. Нищо не се знае за процеса на връщане.

**Какво да се направи:**
- Нова страница `returns.php` (под Audit)
- Използва Shopify Refunds GraphQL API
- Показва: order#, item(s) върнати, причина, дата, refund amount, restock статус
- Return rate по SKU/product (кои продукти се връщат най-много)
- CSV export

**Защо важно:** Return rate по SKU директно информира inventory и procurement решения.

---

### 1.3 Courier / Carrier Performance Dashboard

**Проблем:** Системата има tracking links, но не агрегира данни за on-time delivery по carrier.

**Какво да се направи:**
- Нова страница `carrierperf.php`
- Parse tracking events от ShipStation (вече са достъпни)
- Метрики: avg delivery time, late delivery %, carrier distribution
- Groupby: carrier × region × order type
- Слоти: last 30/60/90 дни

---

### 1.4 Composite Risk Score

**Проблем:** Fraud сигналите са разпределени в отделни проверки (country mismatch, email, address). Операторът трябва да ги комбинира ментално.

**Какво да се направи:**
- `RiskScorer` клас в `src/`
- Събира signals от различни audit checks в единна оценка 0–100
- Signals: disposable email (+30), billing≠shipping country (+25), missing phone on high-value (+15), PO Box (+10), repeat refund customer (+20), fraud tag от Shopify (+35)
- Нова колона "Risk" в spot-check и customer lookup
- Configurable weights в `data/risk_weights.json`

---

### 1.5 Webhook Health Monitor

**Проблем:** Ако Shopify спре да изпраща webhook events (order created, fulfillment updated), нищо в системата няма да го засече.

**Какво да се направи:**
- Нова страница `webhookhealth.php` (под API Health)
- Използва Shopify Admin API `/webhook_subscriptions.json`
- Показва: всички регистрирани webhooks, последна доставка, failure rate (от Shopify Delivery Monitoring API)
- Alert ако webhook не е доставен за > N часа

---

## Приоритет 2 — Среден


### 2.3 Bulk Order Actions

**Проблем:** Ignore работи само на един ред наведнъж от повечето pages. Bulk операции са само в `ignored.php`.

**Какво да се направи:**
- Checkboxes в таблиците на audit pages
- Bulk actions toolbar: Ignore selected, Push to ShipStation, Add tag, Export selected
- Reuse съществуващия `Actions.php` клас

---

### 2.4 Inventory Forecast

**Проблем:** `inventoryoversell.php` показва текущ shortfall, но не прогнозира кога ще се стигне до 0.

**Какво да се направи:**
- Нова страница `inventoryforecast.php`
- Изчислява daily sell-through rate на variant за последните N дни
- Прогнозира: "at this rate, stock will hit 0 in X days"
- Цветово кодиране: червено < 7 дни, жълто < 14 дни
- Само за variants с `deny` oversell policy

---

### 2.5 Discord Notifications

**Проблем:** Slack е единственият chat канал. Много малки екипи ползват Discord.

**Какво да се направи:**
- `DiscordNotifier` клас (Discord Incoming Webhooks са идентични по структура)
- `DISCORD_WEBHOOK_URL` ENV var
- Добавя се до Slack в `SlackRules` → `NotificationRules` (или нов `DiscordRules`)
- Settings страницата да показва и Discord статус

---

### 2.6 Role-Based Access (Basic)

**Проблем:** Системата има единно парола (`AUTH_PASSWORD`). Няма разграничение между read-only и admin потребители.

**Какво да се направи:**
- `USERS` ENV var или `data/users.json`: `[{name, password_hash, role}]`
- Роли: `viewer` (само четене), `operator` (може да ignore/push), `admin` (всичко + settings) - оператора трябва да може и да пуска аудитите
- Middleware в `Auth.php`
- Backward compatible: ако само `AUTH_PASSWORD` е зададен, всички са admin

---

## Приоритет 3 — Нисък / Качество

### 3.1 Mobile-Responsive UI

**Проблем:** Dashboard-ът е проектиран за desktop. Таблиците overflow на mobile.

**Какво да се направи:**
- Horizontal scroll wrapper за таблиците на малки екрани
- Collapsible sidebar (hamburger menu)
- Topbar да се collapse на ≤ 768px
- Само CSS промени, без JS framework

---

### 3.2 Dark / Light Theme Toggle

**Проблем:** Системата вероятно има само един цветови режим.

**Какво да се направи:**
- CSS custom properties за theme colors (вероятно вече ги има: `var(--ok)`, `var(--danger)`)
- `prefers-color-scheme` media query за auto detect
- Toggle бутон в topbar с `localStorage` запазване

---

### 3.3 Note Templates

**Проблем:** Операторите пишат едни и същи order notes ръчно в ShipStation/Shopify.

**Какво да се направи:**
- `data/note_templates.json` — configurable шаблони с placeholders (`{{order_number}}`, `{{customer_email}}`)
- Quick-insert в order detail pages
- Uses Shopify API за запис на note
- може да ги взема и ота там каквито нотес са вече написани

---

### 3.4 Prometheus / Metrics Endpoint

**Проблем:** Няма начин системата да се интегрира с Grafana или друг monitoring stack.

**Какво да се направи:**
- `metrics.php` — protected endpoint, връща Prometheus text format
- Метрики: `shopify_ops_missing_orders_total`, `shopify_ops_audit_duration_seconds`, `shopify_ops_cache_entries`, `shopify_ops_job_queue_depth`
- `METRICS_TOKEN` ENV var за basic auth

---

### 3.5 Print Queue

**Проблем:** Packing slips се отварят един по един. Няма batch print.

**Какво да се направи:**
- Checkbox selection от order таблиците
- "Add to Print Queue" action
- `printqueue.php` — показва всички queued slip URLs, един `window.print()` бутон
- File-backed queue в `data/print_queue.json`

---

## Анализ на конкурентите

| Инструмент | Какво предлагат, което ние нямаме |
|---|---|
| **Lifetimely** | LTV cohort heatmap, profit per order, COGS tracking |
| **Triple Whale** | Attribution, blended ROAS, pixel tracking |
| **NoFraud / Riskified** | ML-based composite risk score с chargeback guarantee |
| **Tacey** | AI order agents, address validation в реално време при checkout |
| **ShipStation native** | Carrier rate comparison, batch label printing |
| **Gorgias** | Order edit от customer support ticket (вграден в helpdesk) |
| **Klaviyo** | Post-purchase email flows с order data enrichment |

**Ключово наблюдение:** Нашият инструмент е по-добър в _аудит след факта_. Конкурентите се движат към _превенция в реално време_. Следващата голяма стъпка трябва да е Risk Score (1.4) — от reactive към proactive.

---

## Резюме по приоритет

| # | Фийчър | Сложност | Стойност |
|---|---|---|---|
| 1.1 | Email/SMTP Notifications | Ниска | Висока |
| 1.2 | Return / RMA Tracker | Средна | Висока |
| 1.3 | Carrier Performance | Средна | Висока |
| 1.4 | Composite Risk Score | Средна | Много висока |
| 1.5 | Webhook Health Monitor | Ниска | Висока |
| 2.1 | Customer Cohort & LTV | Висока | Висока |
| 2.2 | Scheduled Reports | Средна | Средна |
| 2.3 | Bulk Order Actions | Ниска | Средна |
| 2.4 | Inventory Forecast | Средна | Средна |
| 2.5 | Discord Notifications | Ниска | Ниска |
| 2.6 | Role-Based Access | Средна | Средна |
| 3.1 | Mobile Responsive UI | Ниска | Ниска |
| 3.2 | Dark/Light Theme | Ниска | Ниска |
| 3.3 | Note Templates | Ниска | Ниска |
| 3.4 | Prometheus Metrics | Ниска | Средна |
| 3.5 | Print Queue | Ниска | Ниска |
