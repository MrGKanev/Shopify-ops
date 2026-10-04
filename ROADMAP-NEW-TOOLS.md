# Нови инструменти: Shopify + ShipStation

Какво предлагат Shopify (Admin GraphQL API до версия 2026-10) и ShipStation (API V1 и V2), какво още не ползваме и кои инструменти можем да добавим. За всеки инструмент има **защо**, **какво трябва** и **как да се направи**.

> Свързани файлове: [REFACTORING.md](REFACTORING.md) — уеднаквяване и оптимизация на кода.

---

## Статус на изпълнението

Проверено по текущата работна директория на 2026-10-04; промените още не са комитнати.

- [ ] **Инструменти #1–#16:** нито един от предложените нови инструменти не е реализиран изцяло.
- [x] **Готови предпоставки:** общ регистър на отчетите, общи Blade компоненти и разделен Shopify клиент (REFACTORING §2–§4).
- [ ] **Оставащи предпоставки:** локално огледало, ShipStation уебхуци и мутации за remediation.

Съществуващите Duplicate Addresses, Return/RMA, Disputes и Webhook Health не покриват предложените разширения #11, #9, #10 и #16.

## 1. Какво ползваме сега и какво пропускаме

### Shopify (сега: `ShopifyAdminClient`, API `2026-07`)

| Ползваме | Не ползваме (налично в API) |
| --- | --- |
| Търсене и пагинация на поръчки (`orders(query:)`), до 100 страници × 250 | **Bulk Operations** (`bulkOperationRunQuery`): пълни експорти без ограничение на страниците, до 5 паралелно, списък с `bulkOperations` |
| Fulfillment orders (holds, partial, SLA) — само за четене | **Мутации върху fulfillment orders**: `fulfillmentOrderHold`, `fulfillmentOrderReleaseHold`, `fulfillmentCreate` (с tracking), асинхронно `shippingLabelPurchase` (2026-07) |
| Refunds, disputes, order events, metafields, tags | **Returns API** (`returnRequest`, `returnCreate`, `ReturnReasonDefinition`, транзакции върху `Return`) |
| Каталог и наличности (oversell, aging, forecast) | **Inventory transfers/shipments** (webhooks с ID на трансфер, действие `canceled receive`), количествени имена `incoming`, `committed`, `reserved`, `damaged` |
| Уебхуци: записваме всичко, но само `refunds/create` и `disputes/create` създават issue | **Next Gen Events** (GA от 2026-10): филтрирани абонаменти с GraphQL payload, по-малко доставки; **регистрация на уебхуци от UI** (`webhookSubscriptionCreate`) |
| `updateOrderNote` (единствената мутация) | `tagsAdd`/`tagsRemove`, `orderCancel` (вече връща структуриран `jobResult`), `orderUpdate` |
| — | **ShopifyQL** (`shopifyqlQuery`): агрегирани продажби/връщания без да теглим всяка поръчка |
| — | **Shopify Payments**: `balanceTransactions`, payouts, такси, конвертиране на валута (2026-09) |
| — | `InventoryItem.unitCost`, `harmonizedSystemCode`, `countryCodeOfOrigin`, тегло и **packed dimensions** (2026-09), `LineItem.weight` (2026-07) |
| — | `checkoutToken`/`cartToken` на поръчката (2026-07), SMS consent на `CustomerPhoneNumber` (2026-10) |

### ShipStation (сега: `ShipStationClient`, само V1 `ssapi.shipstation.com`)

| Ползваме | Не ползваме |
| --- | --- |
| `GET /orders` (по номер, по дата, awaiting, active) | **Уебхуци V1**: `ORDER_NOTIFY`, `ITEM_ORDER_NOTIFY`, `SHIP_NOTIFY`, `ITEM_SHIP_NOTIFY` — вместо постоянно запитване (polling) |
| `GET /shipments` (по дата, voided) | **Действия по поръчка**: `/orders/holduntil`, `/orders/restorefromhold`, `/orders/addtag`, `/orders/markasshipped` |
| `POST /orders/createorder` (push от Shopify) | `createorder` като **upsert** по `orderKey` — обновяване на вече изпратена поръчка |
| — | `/shipments/getrates` — сравнение на тарифи |
| — | `/stores/refreshstore` — пренасочване на импорта от магазина, преди ръчен push |
| — | `/products`, `/warehouses`, `/fulfillments` (поръчки, изпълнени извън ShipStation) |
| — | **API V2** (`api.shipstation.com/v2`, ранен етап): batches, **manifests**, pickups, **return labels**, inventory/warehouses, уебхуци `TRACK_EVENT_V2`, `LABEL_CREATED_V2`, `BATCH_PROCESSED_V2`, `FULFILLMENT_SHIPPED_V2`; планирани endpoint-и за address validation и tracking |

**Ограничения, които трябва да се спазват:** ShipStation V1 позволява 40 заявки/мин за двойка ключ+секрет (хедъри `X-Rate-Limit-Remaining` / `X-Rate-Limit-Reset`). Shopify GraphQL работи с бюджет по цена на заявката (`extensions.cost.throttleStatus`). Общият `IntegrationThrottle` координира 38 ShipStation заявки/60 s по API ключ и Shopify бюджета по магазин чрез атомични cache lock-ове.

---

## 2. Приоритизиран списък

| # | Инструмент | Тип | Изисква | Усилие | Стойност |
| --- | --- | --- | --- | --- | --- |
| 1 | Локално огледало на поръчките (Order Mirror) | Основа | Shopify Bulk Ops + уебхуци | L | ★★★★★ |
| 2 | ShipStation уебхуци (Ship/Order Notify) | Основа | SS V1 | M | ★★★★★ |
| 3 | Промяна в поръчка след push към SS (от TODO) | Нов детектор | #1 или уебхук `orders/updated` | S–M | ★★★★★ |
| 4 | Remediation действия („Поправи“) | Действия | Shopify + SS мутации | M | ★★★★★ |
| 5 | Печалба по поръчка (Order Profitability) | Комбиниран | Shopify `unitCost` + Payments такси + SS label cost | M | ★★★★☆ |
| 6 | Несъответствие в теглото (Weight Discrepancy) | Комбиниран | Shopify тегло + SS shipment weight | S | ★★★★☆ |
| 7 | Rate Shopping Audit (пропуснати спестявания) | Комбиниран | SS `getrates` | M | ★★★★☆ |
| 8 | Customs Readiness (международни пратки) | Shopify | HS код, държава на произход, тегло | S | ★★★☆☆ |
| 9 | Returns Workbench | Комбиниран | Shopify Returns API (+ SS V2 return labels) | M | ★★★☆☆ |
| 10 | Payments Reconciliation | Shopify | Shopify Payments API | M | ★★★☆☆ |
| 11 | Повтарящи се адреси преди изпълнение (от TODO) | Детектор | #1 препоръчително | S | ★★★☆☆ |
| 12 | Sales Pulse чрез ShopifyQL | Shopify | `shopifyqlQuery` | S | ★★★☆☆ |
| 13 | Inbound / Transfer Tracker | Shopify | Inventory transfers | S–M | ★★☆☆☆ |
| 14 | SS ↔ Shopify Product Sync Check | Комбиниран | SS `/products` | S | ★★☆☆☆ |
| 15 | End-of-Day Close / Manifest Check | Комбиниран | SS V2 manifests (или V1 shipments) | M | ★★☆☆☆ |
| 16 | Мениджър на уебхуци в UI | Админ | `webhookSubscriptionCreate` | S | ★★★☆☆ |

S = до 2 дни, M = до седмица, L = повече от седмица.

---

## 3. Подробно описание

### 1. Локално огледало на поръчките (Order Mirror) — основа

**Защо.** В момента всеки отчет тегли поръчките на живо от Shopify, с лимит от 100 страници × 250 поръчки (`paginateGraphql`, `$maxPages`). Голям диапазон → бавен отчет, `truncated = true` и непълни резултати. Отчетите през `QueuedReportRunner` вече експортират запазения резултат без ново сканиране; Run Audit остава отделен път. `report_runs` пази резултати, но няма локално огледало на поръчките.

**Какво трябва.**
- Таблици `shopify_orders`, `shopify_order_line_items`, `shopify_fulfillments` (store-scoped) и `shipstation_orders`, `shipstation_shipments`, съдържащи само полетата, които ползват анализаторите, плюс `raw` JSON колона за всичко друго.
- Първоначално зареждане чрез **Bulk Operation** (`bulkOperationRunQuery` → JSONL файл → `LazyCollection` → `upsert` на порции).
- Инкрементално обновяване: уебхуци `orders/create|updated|cancelled|fulfilled|paid`, `refunds/create`, `fulfillments/create|update` (или Next Gen Events, когато пренесем приложението към конфигурация с TOML) + нощно „догонване“ по `updated_at:>` за пропуснати събития.
- ShipStation: уебхуци (#2) + нощно `modifyDateStart` догонване.

**Как.**
1. `php artisan make:model ShopifyOrder -mf` (и останалите) с `store_id` + уникален индекс `(store_id, shopify_id)`.
2. `App\Application\Sync\StartShopifyBulkExport` (мутация) и `ImportShopifyBulkResult` (job, който чете JSONL поточно; уебхук `bulk_operations/finish` го пуска).
3. `ProcessShopifyWebhookEvent` → да вика `UpsertShopifyOrder` според темата.
4. Анализаторите **не се пипат** — те приемат масиви. Добавяме втори източник: `ShopifyAdminGateway` получава имплементация `MirroredShopifyGateway`, която чете от БД; превключване per-store (`stores.data_source = live|mirror`).
5. Значка „данни към HH:MM“ в отчетите + бутон „опресни на живо“.

**С какво помага.** Отчетите стават почти мигновени, без отрязване, без харчене на Shopify rate limit; позволява глобално търсене, тенденции по всеки инструмент и детекторите #3 и #11.

### 2. ShipStation уебхуци

**Защо.** Всички SS проверки (Shipment Aging, Delivery Exceptions, Shipped/Unfulfilled, Orphans) правят polling при 40 заявки/мин. Shipped-but-unfulfilled се хваща чак при пускане на отчета.

**Какво трябва.** Абонамент чрез `POST /webhooks/subscribe` за `SHIP_NOTIFY` и `ORDER_NOTIFY`. V1 payload-ът съдържа само `resource_url` → трябва да изтеглим ресурса. V1 уебхуците **нямат подпис** → тайна в URL-а (`/webhooks/shipstation/{store}/{token}`) + rate limit на route-а.

**Как.** Таблицата `webhook_events` вече е готова — добавяме колона `source` (`shopify|shipstation`). Нов `ShipStationWebhookController` (по образеца на `ShopifyWebhookController`) → job `ProcessShipStationWebhookEvent` → тегли `resource_url` през `ShipStationClient` → upsert в огледалото и проверка: „SS shipped, а Shopify няма fulfillment 2 часа по-късно“ → `OperationalIssue`. Бутон в Webhook Health: „Абонирай“ / „Провери абонаментите“ (`GET /webhooks`).

**С какво помага.** Проблемите със синхронизацията се хващат за минути, а не на следващия ден; по-малко API заявки.

### 3. Промяна в Shopify поръчка след push към ShipStation (TODO)

**Защо.** Ако клиентът смени адреса или артикулите след push, ShipStation изпраща стари данни.

**Какво трябва.** При push записваме хеш на релевантните полета (артикули + количества, адрес за доставка, метод на доставка) в `push_logs.payload_hash`. При уебхук `orders/updated` (или в огледалото) смятаме хеша отново.

**Как.** `App\Domain\Orders\ShipStationRelevantFingerprint::for(array $order): string` (използва същите полета като `ShipStationClient::buildOrderPayload`). При разлика → `OperationalIssue` (`source_tool = post_push_change`, priority `high`, ако SS статусът още е `awaiting_shipment`; `urgent`, ако е `shipped`). В issue-то: diff + бутон „Обнови в ShipStation“ (V1 `createorder` с **същия** `orderKey` прави upsert).

**С какво помага.** Предотвратява грешни пратки — най-скъпата грешка в операциите.

### 4. Remediation действия („Поправи“)

**Защо.** Почти всички 46 инструмента само **показват** проблема. Операторът после отваря Shopify/ShipStation и поправя на ръка.

**Какво трябва (по едно действие на отчет):**

| Отчет | Действие | API |
| --- | --- | --- |
| SS Shipped / Shopify Unfulfilled, Fulfilled Without Tracking | Създай fulfillment с tracking от SS | Shopify `fulfillmentCreate` |
| Active SS Conflicts (отказани/възстановени в Shopify) | Hold / cancel в SS | SS `/orders/holduntil`, `/orders/createorder` със `orderStatus=cancelled` |
| Fraud Risk, Same IP, Billing≠Shipping | Задържане на изпълнението + таг | Shopify `fulfillmentOrderHold` + `tagsAdd`; SS `/orders/holduntil` |
| On-Hold Stall | Освобождаване на hold | `fulfillmentOrderReleaseHold`, SS `/orders/restorefromhold` |
| Orphan Detector | Таг „orphan“ в SS | `/orders/addtag` |
| Липсва в SS (Run Audit) | Refresh store, после push | `/stores/refreshstore` → `createorder` |
| Tag Policy | Добави/махни таг | `tagsAdd`/`tagsRemove` |

**Как.** Единен шаблон: `App\Application\Remediation\{Action}` клас с `preview(): array` (dry-run, какво ще се промени) и `execute(): RemediationResult`. Пускане като job (bulk избор от таблицата, както при bulk ignore), запис в Action Log (spatie/activitylog вече е наличен), Gate `operator`. За масови действия — `Bus::batch()` с лента за прогрес в Job Queue.

**С какво помага.** Превръща конзолата от „табло с проблеми“ в работен инструмент; затваря цикъла с Operational Issues.

### 5. Печалба по поръчка (Order Profitability)

**Защо.** Shipping Margin Erosion вижда само доставката. Липсва пълната картина: приход − себестойност − такса за плащане − етикет − отстъпки/възстановени суми.

**Какво трябва.** Shopify `LineItem` → `variant.inventoryItem.unitCost`; транзакции → такси от `shopifyPaymentsAccount.balanceTransactions`; ShipStation `shipments.shipmentCost + insuranceCost`; refunds.

**Как.** `ProfitabilityAnalyzer` в `app/Domain/Reports` (чиста функция над нормализирани масиви), отчет с групиране по SKU / канал / метод на доставка / държава и „Top 50 поръчки на загуба“. С огледалото (#1) — моментален.

**С какво помага.** Показва нерентабилни продукти, зони и безплатна доставка, която „изяжда“ маржа.

### 6. Несъответствие в теглото (Weight Discrepancy)

**Защо.** Превозвачите начисляват корекции, когато реалното тегло е по-голямо от декларираното. Грешно тегло в Shopify → грешна тарифа.

**Какво трябва.** Shopify `LineItem.weight` (2026-07) / `variant.inventoryItem.measurement.weight` × количество, срещу SS `shipment.weight` (+ `dimensions`).

**Как.** Анализатор с прагове (% и абсолютни грамове), групиране по SKU → „тези SKU-та имат грешно тегло в каталога“. Действие: линк към продукта в Shopify admin. Отделна проверка: активни физически варианти без тегло / без packed dimensions (2026-09).

**С какво помага.** По-малко carrier adjustments, точни тарифи при checkout.

### 7. Rate Shopping Audit

**Защо.** Не знаем дали избраната услуга е била най-евтината възможна при същия срок.

**Как.** За извадка от изпратени пратки (напр. последните 200) → `POST /shipments/getrates` със същите тегло/размер/адреси → разлика спрямо `shipmentCost`. Задължително като **queued job** с ограничение на скоростта (40/мин!) и кеширане по (ZIP-префикс, тегло, услуга). Резултат: „пропуснати спестявания по услуга/зона“ и препоръки за automation rules в SS.

**С какво помага.** Конкретни пари: правилата за избор на превозвач се коригират по данни, а не на усет.

### 8. Customs Readiness

**Защо.** Международните пратки се бавят или се връщат без HS код, държава на произход или декларирана стойност.

**Как.** Shopify: `InventoryItem.harmonizedSystemCode`, `countryCodeOfOrigin`, `countryHarmonizedSystemCodes`. Два режима: (а) каталог — активни варианти без тези полета; (б) неизпълнени международни поръчки, съдържащи такива варианти → issue преди етикета. Добавя се и в Catalog Quality.

### 9. Returns Workbench

**Защо.** Return/RMA Tracker се базира на refunds. Shopify вече има истински Returns (заявки, причини с `ReturnReasonDefinition`, статуси, транзакции).

**Как.** Отчет за: заявени, но неодобрени връщания > N дни; връщания, получени без refund; refund без return (обратното); причини по SKU (за качеството). Действие: генериране на return label чрез SS V2 (`return labels`), когато сметката има V2 ключ — иначе само линк.

### 10. Payments Reconciliation

**Защо.** Disputes вече се показват, но payout-ите, таксите и chargeback загубите не се свързват с поръчките.

**Как.** `shopifyPaymentsAccount { payouts, balanceTransactions }` → дневен отчет: payout vs. сбор от поръчките, такси %, chargeback-ове и техния изход, транзакции с конвертиране на валута. Изисква scope `read_shopify_payments_payouts` — добавяме го в API Health → required scopes.

### 11. Повтарящи се адреси преди изпълнение (TODO)

**Как.** Нормализиран адрес (`NormalizesText` concern вече съществува) → хеш → брой неизпълнени поръчки за последните N дни с различни имена/имейли. Само предупреждение (issue, `normal`), без блокиране — както е записано в TODO. С огледалото е една SQL заявка; без него — уебхук `orders/create` + проверка в последните 7 дни.

### 12. Sales Pulse чрез ShopifyQL

**Защо.** Дашбордът и аномалиите теглят поръчки, за да броят. ShopifyQL връща агрегати директно.

**Как.** `shopifyqlQuery(query: "FROM sales SHOW total_sales, orders BY day SINCE -30d")` (новото поле `parseWarnings`, 2026-10, се логва). Ползва се за дашборда и като baseline в `DetectOperationalAnomalies` (по-точен от собствените броячи). Изисква scope `read_reports`.

### 13. Inbound / Transfer Tracker

Inventory transfers и shipments (с webhooks, които вече носят ID на трансфер) → отчет „закъснели входящи доставки“ и корекция на Inventory Forecast (включване на `incoming` количества — в момента прогнозата е без тях).

### 14. ShipStation ↔ Shopify Product Sync Check

SS `GET /products` срещу вариантите в Shopify: SKU без пара, разлики в тегло, customs описание, неактивни продукти, които още са активни в SS. Хваща причините за Item Mismatch, преди да станат грешни пратки.

### 15. End-of-Day Close

Една страница в края на деня: платени днес / изпратени днес / остават в срок / ще пробият SLA утре; етикети без manifest (V2 `manifests`), voided етикети без възстановена сума. Изпраща се като дайджест (има инфраструктура за `reports:email-digest`).

### 16. Мениджър на уебхуци в UI

Webhook Health само чете. Добавяме „Регистрирай липсващите теми“ (`webhookSubscriptionCreate` за списъка от `config/shopify-webhooks.php`) и „Премахни остарели“. Предпоставка за #1 и #3. Next Gen Events изискват приложение с TOML конфигурация (Dev Dashboard) — оценява се отделно, когато класическите уебхуци станат недостатъчни.

---

## 4. Laravel пакети, които спестяват работа

Правилото е без нови зависимости без одобрение — това са **предложения**. Вече инсталирани и използвани: `giggsey/libphonenumber-for-php` и `commerceguys/addressing` (`app/Domain/Orders`), `spatie/laravel-schedule-monitor`. Ползвайте ги и в новите инструменти (#8 Customs Readiness, #11 повтарящи се адреси).

| Пакет | За кой инструмент | Препоръка |
| --- | --- | --- |
| `spatie/laravel-webhook-client` | #2 ShipStation уебхуци | **По желание.** Нашият Shopify контролер е добър — по-лесно е да го копираме, отколкото да мигрираме. |
| Вградени в Laravel 13: `Bus::batch`, `Concurrency`, `Redis::throttle`, job middleware `RateLimited` / `ThrottlesExceptions`, `LazyCollection` | #1, #4, #7 | **Да — без нова зависимост.** Покриват bulk import, масови действия и rate limiting. |
| `saloonphp/saloon` (+ rate-limit plugin) | Интеграции | **Не сега.** Добър пакет, но е пренаписване на два работещи клиента; лимитът се координира от `IntegrationThrottle` чрез общия Redis cache. |
| `shopify/shopify-api` (официален PHP) | Shopify | **Не.** Нашият клиент е по-тесен и тестван; пакетът е за embedded/OAuth приложения. |

---

## 5. Предложен ред на изпълнение

1. **Фаза 1 (основа):** #16 мениджър на уебхуци → #2 SS уебхуци → #3 промяна след push (бърза победа, затваря TODO).
2. **Фаза 2 (действия):** #4 remediation за 3-те най-често ползвани отчета (Shipped/Unfulfilled, Active Conflicts, Fraud hold).
3. **Фаза 3 (данни):** #1 огледало → превключване на отчетите един по един → #11, #12.
4. **Фаза 4 (пари):** #6 тегло → #5 печалба → #7 тарифи → #10 payments.
5. **Фаза 5:** #8, #9, #13, #14, #15.

## Източници

- [Shopify 2026-07 release notes](https://shopify.dev/release-notes/2026-07) · [Shopify changelog](https://shopify.dev/changelog) · [Next Gen Events GA](https://shopify.dev/changelog/blog/next-generation-events-are-now-generally-available) · [2026-01 release notes](https://shopify.dev/changelog/release-notes/2026-01)
- [ShipStation API V2 – Getting started](https://docs.shipstation.com/getting-started) · [V2 Webhooks](https://docs.shipstation.com/apis/openapi/webhooks) · [V2 Rates](https://docs.shipstation.com/apis/openapi/rates) · [V1 Requirements / rate limits](https://www.shipstation.com/docs/api/requirements/)
