<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommandPaletteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CommandPaletteController extends Controller
{
    public function __invoke(CommandPaletteRequest $request): JsonResponse
    {
        $store = $this->resolveStore($request);
        $query = Str::of((string) ($request->validated('q') ?? ''))->squish()->toString();
        $searchableText = fn (array $item): string => Str::lower(implode(' ', [$item['label'], $item['description'], $item['keywords']]));
        $commands = collect($this->navigationCommands())
            ->when($query !== '', fn ($items) => $items->filter(fn (array $item): bool => Str::contains($searchableText($item), Str::lower($query))))
            ->take(8)
            ->map(fn (array $item): array => [...$item, 'label' => __($item['label']), 'description' => __($item['description']), 'kind' => 'Page'])
            ->values();

        if (mb_strlen($query) >= 2) {
            $issues = $store->operationalIssues()
                ->where(fn ($builder) => $builder->whereLike('title', "%{$query}%")->orWhereLike('reference', "%{$query}%")->orWhereLike('source_tool', "%{$query}%"))
                ->latest('last_seen_at')
                ->limit(5)
                ->get(['id', 'title', 'reference', 'priority'])
                ->map(fn ($issue): array => [
                    'label' => $issue->title,
                    'description' => trim(__(strtolower($issue->priority)).' · '.($issue->reference ?: __('Operational issue'))),
                    'url' => route('operational-issues.index').'#issue-'.$issue->getKey(),
                    'kind' => 'Issue',
                ]);
            $runs = $store->runLogs()
                ->where(fn ($builder) => $builder->whereLike('tool', "%{$query}%")->orWhereLike('status', "%{$query}%"))
                ->latest()
                ->limit(5)
                ->get(['id', 'tool', 'status', 'created_at'])
                ->map(fn ($run): array => [
                    'label' => Str::headline($run->tool),
                    'description' => __($run->status).' · '.$run->created_at->diffForHumans(),
                    'url' => route('run-logs.index', ['q' => $run->tool]),
                    'kind' => 'Run',
                ]);
            $reports = $store->auditSnapshots()
                ->whereLike('tool', "%{$query}%")
                ->latest('report_date')
                ->limit(5)
                ->get(['id', 'tool', 'report_date', 'rows_found'])
                ->map(fn ($report): array => [
                    'label' => __(Str::headline($report->tool)),
                    'description' => $report->report_date->toDateString().' · '.$report->rows_found.' results',
                    'url' => route('saved-reports.show', $report),
                    'kind' => 'Report',
                ]);
            $pushedOrders = $store->pushLogs()
                ->where(fn ($builder) => $builder->whereLike('order_number', "%{$query}%")->orWhere('shopify_id', $query)->orWhere('shipstation_order_id', $query))
                ->latest('pushed_at')
                ->limit(5)
                ->get(['order_number', 'shopify_id', 'shipstation_order_id', 'pushed_at'])
                ->map(fn ($order): array => [
                    'label' => '#'.$order->order_number,
                    'description' => __('Pushed order').' · '.$order->pushed_at->diffForHumans(),
                    'url' => route('push-logs.index', ['q' => $order->order_number]),
                    'kind' => 'Order',
                ]);
            $ignoredOrders = $store->ignoredOrders()
                ->where(fn ($builder) => $builder->whereLike('order_number', "%{$query}%")->orWhereLike('reason', "%{$query}%"))
                ->latest('ignored_at')
                ->limit(5)
                ->get(['order_number', 'reason'])
                ->map(fn ($order): array => [
                    'label' => '#'.$order->order_number,
                    'description' => __('Ignored order').($order->reason !== '' ? ' · '.$order->reason : ''),
                    'url' => route('ignored-orders.index'),
                    'kind' => 'Order',
                ]);
            $printQueue = $store->printQueueItems()
                ->where(fn ($builder) => $builder->whereLike('order_number', "%{$query}%")->orWhereLike('note', "%{$query}%"))
                ->oldest()
                ->limit(5)
                ->get(['order_number', 'note'])
                ->map(fn ($order): array => [
                    'label' => '#'.$order->order_number,
                    'description' => __('Print queue').($order->note !== '' ? ' · '.$order->note : ''),
                    'url' => route('print-queue.index'),
                    'kind' => 'Order',
                ]);
            $commands = $commands->concat($issues)->concat($runs)->concat($reports)->concat($pushedOrders)->concat($ignoredOrders)->concat($printQueue)->take(15)->values();
        }

        $orderNumber = preg_replace('/\D+/', '', $query) ?? '';
        if ($orderNumber !== '') {
            $commands = $commands->prepend([
                'label' => __('Look up order').' #'.$orderNumber,
                'description' => __('Search Shopify and ShipStation'),
                'url' => route('orders.lookup', ['order_number' => $orderNumber]),
                'kind' => 'Order',
            ])->prepend([
                'label' => __('Search local history for').' #'.$orderNumber,
                'description' => __('Saved reports, pushed and ignored orders'),
                'url' => route('global-search', ['q' => $orderNumber]),
                'kind' => 'Order',
            ])->take(15)->values();
        }

        return response()->json(['results' => $commands]);
    }

    /** @return array<int, array{label:string,description:string,url:string,keywords:string}> */
    private function navigationCommands(): array
    {
        $featureDetails = [
            'reports.duplicate-orders' => ['Find repeated or likely duplicate orders before fulfillment.', 'duplicate duplicates repeated order дублирани дубликат повторена поръчка'],
            'reports.refund-tracker' => ['Find orders with refunds and compare refund activity.', 'refund refunds returned money възстановяване върнати пари'],
            'reports.repeat-refunds' => ['Find customers or orders with repeated refunds.', 'repeat refund multiple refunds повторни възстановявания'],
            'reports.return-rma' => ['Review returns and return merchandise authorizations.', 'return returns rma merchandise върнати стоки рекламации'],
            'reports.orphan-orders' => ['Find orders present in one system but missing from the other.', 'orphan missing absent Shopify ShipStation липсващи несъответствие'],
            'reports.active-shipstation-conflicts' => ['Find orders that are active in ShipStation but cancelled or otherwise conflicting in Shopify.', 'conflict cancelled active shipstation несъответствия отменени'],
            'reports.shipped-unfulfilled' => ['Find orders shipped in ShipStation that Shopify still marks unfulfilled.', 'orders shipped but still unfulfilled shipped fulfilled unfulfilled tracking изпратени неизпълнени'],
            'reports.order-edits' => ['Review Shopify order changes and edit history.', 'edited changed order history промени редактирани поръчки'],
            'reports.note-flags' => ['Find orders with notes that need attention.', 'note notes flag warning бележки сигнал'],
            'reports.address-check' => ['Check shipping addresses for incomplete or suspicious details.', 'address invalid incomplete shipping адрес невалиден непълен доставка'],
            'reports.email-check' => ['Find malformed or suspicious customer email addresses.', 'email invalid typo имейл грешен клиент'],
            'reports.high-value-no-phone' => ['Find expensive orders without a customer phone number.', 'high value expensive no phone телефон скъпи поръчки'],
            'reports.address-changes' => ['Find orders whose shipping address changed after creation.', 'address changed edit адрес променен промяна'],
            'reports.post-ship-address-changes' => ['Find address changes made after an order shipped.', 'post ship address changed след изпращане адрес'],
            'reports.duplicate-addresses' => ['Find multiple orders shipping to the same address.', 'duplicate same address repeated fraud еднакъв адрес измама'],
            'reports.voided-shipments' => ['Find voided or cancelled shipping labels.', 'void cancelled label shipment анулирани етикети пратки'],
            'reports.fulfillment-sla' => ['Find orders that missed their fulfillment deadline.', 'late overdue fulfillment sla закъснели срок изпълнение'],
            'reports.partial-fulfillment' => ['Find orders stuck with only some items fulfilled.', 'partial incomplete items fulfillment частично изпълнени артикули'],
            'reports.on-hold-stall' => ['Find orders left on hold for too long.', 'hold stalled waiting задържани изчакващи'],
            'reports.no-tracking' => ['Find fulfilled orders that have no tracking number.', 'tracking missing fulfilled проследяване липсва изпълнени'],
            'reports.shipment-aging' => ['Find shipments that have been in transit for an unusually long time.', 'shipment aging delayed in transit доставка забавена пратка'],
            'reports.item-mismatch' => ['Compare shipped items against Shopify order items.', 'wrong item mismatch sku shipped грешни артикули сравнение'],
            'reports.carrier-performance' => ['Compare carrier delivery speed and performance.', 'carrier delivery speed performance куриер скорост доставка'],
            'reports.shipping-margin' => ['Find orders where shipping cost exceeds the amount charged.', 'shipping cost margin profit доставка цена марж'],
            'reports.inventory-oversell' => ['Find products at risk of selling beyond available inventory.', 'inventory stock oversell out of stock наличност изчерпване'],
            'reports.inventory-aging' => ['Find inventory that has not moved for a long time.', 'old stock aging inventory залежала наличност'],
            'reports.inventory-forecast' => ['Estimate when current inventory may run out.', 'forecast stock reorder inventory прогноза наличност зареждане'],
            'reports.fraud-risk' => ['Review orders with elevated fraud risk signals.', 'fraud risk suspicious измама съмнителни риск'],
            'reports.same-ip' => ['Find orders from the same IP address using different emails.', 'same ip different email измама един ip различни имейли'],
            'reports.disputes' => ['Review payment disputes and chargebacks.', 'dispute chargeback payment оспорвания чарджбек плащане'],
            'orders.compare' => ['Compare an order between Shopify and ShipStation.', 'compare mismatch difference поръчка сравнение разлика'],
            'orders.timeline' => ['See order events, integration history, and shipment status in one timeline.', 'timeline history tracking events хронология проследяване история'],
            'orders.tracking' => ['Search recent tracking updates and shipment status.', 'tracking shipment status проследяване статус пратка'],
            'orders.tag-search' => ['Find orders by Shopify tags.', 'tag tags search таг етикет поръчки'],
            'metafields.index' => ['Search Shopify order metafields.', 'metafield metadata custom fields метаполета данни'],
        ];
        $featureDetails += [
            'reports.run-audit' => ['Compare Shopify and ShipStation orders to find missing or mismatched orders.', 'missing mismatch compare проверка липсващи несъответствия'],
            'saved-reports.index' => ['Open and export previously saved audit results.', 'history saved export reports запазени резултати експорт'],
            'report-trends.index' => ['Track audit results over time.', 'trend history changes тенденции история промени'],
            'orders.spot-check' => ['Quickly inspect one order across connected systems.', 'lookup inspect order провери поръчка'],
            'customers.lookup' => ['Find a customer and review their order history.', 'customer orders history клиент история поръчки'],
            'reports.customer-ltv' => ['Review customer lifetime value and purchase history.', 'customer lifetime value revenue клиент стойност приходи'],
            'orders.packing-slip' => ['Preview a packing slip for an order.', 'packing slip print pack лист за опаковане печат'],
        ];

        $commands = [
            ['label' => 'Dashboard', 'description' => 'Store overview', 'url' => route('dashboard'), 'keywords' => 'home overview начало табло'],
            ['label' => 'Order lookup', 'description' => 'Find an order in Shopify', 'url' => route('orders.lookup'), 'keywords' => 'search order поръчка търсене'],
            ['label' => 'Run audit', 'description' => 'Start a new operational audit', 'url' => route('reports.run-audit'), 'keywords' => 'audit check проверка одит'],
            ['label' => 'Issue triage', 'description' => 'Review operational issues', 'url' => route('operational-issues.index'), 'keywords' => 'issue problem проблеми triage'],
            ['label' => 'Run history', 'description' => 'See recent audit runs', 'url' => route('run-logs.index'), 'keywords' => 'history log runs история'],
            ['label' => 'Job queue', 'description' => 'Inspect queued and failed jobs', 'url' => route('jobs.index'), 'keywords' => 'horizon queue jobs опашка'],
            ['label' => 'Saved reports', 'description' => 'Open stored audit results', 'url' => route('saved-reports.index'), 'keywords' => 'report export reports справки'],
            ['label' => 'Global order search', 'description' => 'Search local order history', 'url' => route('global-search'), 'keywords' => 'global search orders търсене'],
        ];

        foreach ([config('audit-hub'), config('search-hub')] as $hub) {
            foreach ($hub as $section => $links) {
                foreach ($links as $link) {
                    $commands[] = [
                        'label' => $link['label'],
                        'description' => $featureDetails[$link['route']][0] ?? $section.' · '.$link['label'],
                        'url' => route($link['route']),
                        'keywords' => Str::lower($section.' '.$link['label'].' '.($featureDetails[$link['route']][1] ?? '')),
                    ];
                }
            }
        }

        if (Gate::allows('manage-administration')) {
            $commands = [...$commands,
                ['label' => 'System health', 'description' => 'Check Laravel and infrastructure', 'url' => route('admin.health'), 'keywords' => 'health status system здраве'],
                ['label' => 'Health incidents', 'description' => 'Review outages and recoveries', 'url' => route('admin.health-incidents'), 'keywords' => 'health incidents downtime outage история инциденти'],
                ['label' => 'Backups', 'description' => 'Create and inspect backups', 'url' => route('admin.backups.index'), 'keywords' => 'backup restore архив'],
                ['label' => 'Configuration check', 'description' => 'Validate application configuration', 'url' => route('admin.config-check'), 'keywords' => 'configuration config settings диагностика'],
                ['label' => 'API health', 'description' => 'Check Shopify, ShipStation and notifications', 'url' => route('admin.api-health'), 'keywords' => 'api health shopify shipstation'],
                ['label' => 'Webhook health', 'description' => 'Check webhook delivery', 'url' => route('admin.webhook-health'), 'keywords' => 'webhook health'],
                ['label' => 'Webhook events', 'description' => 'Inspect incoming Shopify events', 'url' => route('admin.webhook-events'), 'keywords' => 'webhook shopify events събития'],
                ['label' => 'Slack notifications', 'description' => 'Configure Slack notification rules', 'url' => route('admin.slack-rules.edit'), 'keywords' => 'slack notification rules'],
                ['label' => 'Discord notifications', 'description' => 'Configure Discord notification rules', 'url' => route('admin.discord-rules.edit'), 'keywords' => 'discord notification rules'],
                ['label' => 'Email notifications', 'description' => 'Configure email notification rules', 'url' => route('admin.email-rules.edit'), 'keywords' => 'email notification rules'],
                ['label' => 'Stores', 'description' => 'Manage connected stores', 'url' => route('admin.stores.index'), 'keywords' => 'stores settings магазини'],
                ['label' => 'Users', 'description' => 'Manage user access', 'url' => route('admin.users.index'), 'keywords' => 'users access roles'],
                ['label' => 'Action log', 'description' => 'Review administrative actions', 'url' => route('admin.action-log'), 'keywords' => 'activity action log'],
                ['label' => 'Banned IPs', 'description' => 'Manage blocked IP addresses', 'url' => route('admin.banned-ips.index'), 'keywords' => 'banned blocked ip security'],
                ['label' => 'Settings', 'description' => 'Application configuration', 'url' => route('admin.settings'), 'keywords' => 'settings config настройки'],
            ];
        }

        return $commands;
    }
}
