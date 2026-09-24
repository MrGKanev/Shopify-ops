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
        $commands = collect($this->navigationCommands())
            ->when($query !== '', fn ($items) => $items->filter(fn (array $item): bool => Str::contains(Str::lower($item['label'].' '.$item['keywords']), Str::lower($query))))
            ->take(8)
            ->map(fn (array $item): array => [...$item, 'kind' => 'Page'])
            ->values();

        if (mb_strlen($query) >= 2) {
            $issues = $store->operationalIssues()
                ->where(fn ($builder) => $builder->whereLike('title', "%{$query}%")->orWhereLike('reference', "%{$query}%")->orWhereLike('source_tool', "%{$query}%"))
                ->latest('last_seen_at')
                ->limit(5)
                ->get(['id', 'title', 'reference', 'priority'])
                ->map(fn ($issue): array => [
                    'label' => $issue->title,
                    'description' => trim($issue->priority.' · '.($issue->reference ?: 'Operational issue')),
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
                    'description' => $run->status.' · '.$run->created_at->diffForHumans(),
                    'url' => route('run-logs.index', ['q' => $run->tool]),
                    'kind' => 'Run',
                ]);
            $reports = $store->auditSnapshots()
                ->whereLike('tool', "%{$query}%")
                ->latest('report_date')
                ->limit(5)
                ->get(['id', 'tool', 'report_date', 'rows_found'])
                ->map(fn ($report): array => [
                    'label' => Str::headline($report->tool),
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
                    'description' => 'Pushed order · '.$order->pushed_at->diffForHumans(),
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
                    'description' => 'Ignored order'.($order->reason !== '' ? ' · '.$order->reason : ''),
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
                    'description' => 'Print queue'.($order->note !== '' ? ' · '.$order->note : ''),
                    'url' => route('print-queue.index'),
                    'kind' => 'Order',
                ]);
            $commands = $commands->concat($issues)->concat($runs)->concat($reports)->concat($pushedOrders)->concat($ignoredOrders)->concat($printQueue)->take(15)->values();
        }

        $orderNumber = preg_replace('/\D+/', '', $query) ?? '';
        if ($orderNumber !== '') {
            $commands = $commands->prepend([
                'label' => 'Look up order #'.$orderNumber,
                'description' => 'Search Shopify and ShipStation',
                'url' => route('orders.lookup', ['order_number' => $orderNumber]),
                'kind' => 'Order',
            ])->prepend([
                'label' => 'Search local history for #'.$orderNumber,
                'description' => 'Saved reports, pushed and ignored orders',
                'url' => route('global-search', ['q' => $orderNumber]),
                'kind' => 'Order',
            ])->take(15)->values();
        }

        return response()->json(['results' => $commands]);
    }

    /** @return array<int, array{label:string,description:string,url:string,keywords:string}> */
    private function navigationCommands(): array
    {
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
                        'description' => $section.' tool',
                        'url' => route($link['route']),
                        'keywords' => Str::lower($section.' '.$link['label']),
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
