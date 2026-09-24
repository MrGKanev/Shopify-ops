<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $appSettings->displayName() }} · Internal Tools</title>
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@php
    $route = request()->route()?->getName() ?? '';
    $group = str_starts_with($route, 'admin.') ? 'settings' : (in_array($route, ['ignored-orders.index','push-logs.index','run-logs.index','jobs.index','print-queue.index'], true) ? 'manage' : (str_starts_with($route, 'orders.') || in_array($route, ['customers.lookup','metafields.index','global-search'], true) ? 'search' : (str_starts_with($route, 'reports.') || str_starts_with($route, 'saved-reports.') || in_array($route, ['report-trends.index','audits.index'], true) ? 'audit' : 'dashboard')));
    $contextLinks = match($group) {
        'manage' => [['Issues','operational-issues.index'],['Ignored Orders','ignored-orders.index'],['Push Log','push-logs.index'],['Run History','run-logs.index'],['Job Queue','jobs.index'],['Print Queue','print-queue.index']],
        'settings' => [['Configuration','admin.settings'],['Diagnostics',['admin.health','admin.config-check']],['API Health','admin.api-health'],['Webhook Health','admin.webhook-health'],['Webhook Events','admin.webhook-events'],['Notifications',['admin.slack-rules.edit','admin.discord-rules.edit','admin.email-rules.edit']],['Stores','admin.stores.index'],['Users','admin.users.index'],['Action Log','admin.action-log'],['Banned IPs','admin.banned-ips.index'],['Backups','admin.backups.index'],['Incidents','admin.health-incidents']],
        default => [],
    };
    // Laravel-only search additions with no equivalent in legacy's grouped hub (search-hub.php mirrors legacy exactly).
    $searchExtras = [['Order Lookup','orders.lookup'],['Push Order / Fix Note','orders.push.create'],['Global Search','global-search']];
    $recentRuns = $group === 'audit' ? $activeStore->runLogs()->latest()->limit(10)->get(['id','tool','status','created_at']) : collect();
    $customLinks = $appSettings->linksFor(auth()->user());
@endphp
<header class="mobile-header"><div class="mobile-brand"><a class="brand" href="{{ route('dashboard') }}">@if($appSettings->logoUrl())<img class="mobile-logo" src="{{ $appSettings->logoUrl() }}" alt="{{ $appSettings->displayName() }}">@else{{ $appSettings->displayName() }}@endif</a><span class="header-store">{{ $activeStore->label }}</span></div><button class="hamburger" id="js-hamburger" type="button" aria-label="Menu"><span></span><span></span><span></span></button></header>
<div class="sidebar-overlay" id="js-overlay"></div>
<div class="layout">
    <aside class="sidebar" id="js-sidebar">
        <div class="sidebar-header">
            <div class="sidebar-header-top"><a class="brand" href="{{ route('dashboard') }}">@if($appSettings->logoUrl())<img class="sidebar-logo" src="{{ $appSettings->logoUrl() }}" alt="{{ $appSettings->displayName() }}">@else{{ $appSettings->displayName() }}@endif</a><button class="theme-icon-btn" id="js-theme-toggle" type="button" title="Toggle theme"><span id="js-theme-icon">🌙</span></button></div>
            <div class="store"><span class="store-label">Store</span> {{ $activeStore->shopify_store }}</div>
            @if($availableStores->count() > 1)<form class="store-switcher" method="POST" action="{{ route('stores.active', $activeStore) }}" data-store-switcher>@csrf<select class="store-select" title="Switch store">@foreach($availableStores as $store)<option value="{{ route('stores.active', $store) }}" @selected($store->is($activeStore))>{{ $store->label }}</option>@endforeach</select></form>@endif
        </div>
        <nav class="flat-nav" aria-label="Primary">
            <a class="flat-nav-link {{ $group === 'dashboard' ? 'active' : '' }}" href="{{ route('dashboard') }}"><span class="flat-nav-icon">🏠</span><span class="nav-label">Dashboard</span></a>
            @can('run-audits')<a class="flat-nav-link {{ $group === 'audit' ? 'active' : '' }}" href="{{ route('audits.index') }}"><span class="flat-nav-icon">📋</span><span class="nav-label">Audit</span></a>@endcan
            <a class="flat-nav-link {{ $group === 'search' ? 'active' : '' }}" href="{{ route('orders.lookup') }}"><span class="flat-nav-icon">🔎</span><span class="nav-label">Search &amp; Lookup</span></a>
            <a class="flat-nav-link {{ $group === 'manage' ? 'active' : '' }}" href="{{ route('push-logs.index') }}"><span class="flat-nav-icon">📂</span><span class="nav-label">Manage</span></a>
            @can('manage-administration')<a class="flat-nav-link {{ $group === 'settings' ? 'active' : '' }}" href="{{ route('admin.settings') }}"><span class="flat-nav-icon">⚙</span><span class="nav-label">Settings</span></a>@endcan
        </nav>
        @if(in_array($group, ['audit', 'search'], true))
            @if($group === 'search')
                <div class="sidebar-section">Search</div><ul class="sidebar-nav">@foreach($searchExtras as [$label,$name])@if(!in_array($name,['orders.push.create','global-search'],true) || auth()->user()->can('run-audits'))<li><a class="{{ request()->routeIs($name) ? 'active' : '' }}" href="{{ route($name) }}">{{ $label }}</a></li>@endif @endforeach</ul>
                @foreach(config('search-hub') as $section => $links)<div class="sidebar-section">{{ $section }}</div><ul class="sidebar-nav">@foreach($links as $link)<li><a class="{{ request()->routeIs($link['route']) ? 'active' : '' }}" href="{{ route($link['route']) }}">{{ $link['label'] }}</a></li>@endforeach</ul>@endforeach
            @else
                <div class="sidebar-section">Recent Runs</div>
                <ul class="sidebar-nav">
                    @forelse($recentRuns as $run)<li><a href="{{ route('run-logs.index', ['q' => $run->tool]) }}">{{ ucfirst(str_replace('_', ' ', $run->tool)) }} · {{ $run->created_at->diffForHumans() }}</a></li>@empty<li>No runs yet</li>@endforelse
                    <li><a class="{{ request()->routeIs('run-logs.index') ? 'active' : '' }}" href="{{ route('run-logs.index') }}">Full run history →</a></li>
                </ul>
            @endif
        @elseif($contextLinks)<div class="sidebar-section">{{ ucfirst($group) }}</div><ul class="sidebar-nav">@foreach($contextLinks as [$label,$name])@php($names = (array) $name)@if((!in_array($names[0],['jobs.index','print-queue.index'],true) || auth()->user()->can('run-audits')) && (!str_starts_with($names[0],'admin.') || auth()->user()->can('manage-administration')))<li><a class="{{ request()->routeIs(...$names) ? 'active' : '' }}" href="{{ route($names[0]) }}">{{ $label }}</a></li>@endif @endforeach</ul>@endif
        @can('run-audits')<div class="sidebar-search"><button class="command-palette-trigger" type="button" data-command-palette-open><span>Quick search</span><kbd>⌘ K</kbd></button></div>@endcan
        <div class="sidebar-footer"><div class="flex items-center gap-2">@unless(auth()->user()->hasEnabledTwoFactorAuthentication())<a class="sidebar-version" href="{{ route('two-factor.settings') }}">Two-factor authentication</a>@endunless<form class="grow" method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost btn-sm btn-full sidebar-signout-btn" type="submit">Sign out</button></form></div><a class="sidebar-version" href="{{ config('app.repository_url') }}" target="_blank" rel="noopener noreferrer">v{{ config('app.version') }} · GitHub</a><div class="sidebar-footer-row"><span class="sidebar-github">{{ auth()->user()->name }} · {{ auth()->user()->role->value }}</span><button class="sidebar-collapse-btn" id="js-sidebar-collapse" type="button" title="Collapse sidebar" aria-label="Collapse sidebar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg></button></div></div>
    </aside>
    <main class="main">@if($customLinks !== [])<nav class="custom-links" aria-label="Custom links">@foreach($customLinks as $link)<a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">{{ $link['label'] }}</a>@endforeach</nav>@endif @if(session('status'))<x-alert class="toast">{{ session('status') }}</x-alert>@endif @yield('content')</main>
</div>
@can('run-audits')
<div class="command-palette" data-command-palette hidden>
    <button class="command-palette-backdrop" type="button" aria-label="Close quick search" data-command-palette-close></button>
    <section class="command-palette-dialog" role="dialog" aria-modal="true" aria-labelledby="command-palette-title">
        <h2 class="sr-only" id="command-palette-title">Quick search</h2>
        <div class="command-palette-input-wrap">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.35-4.35"></path></svg>
            <input data-command-palette-input type="search" placeholder="Search pages, orders, issues, runs and reports…" autocomplete="off" data-endpoint="{{ route('command-palette') }}">
            <kbd>Esc</kbd>
        </div>
        <div class="command-palette-results" data-command-palette-results role="listbox" aria-label="Search results"></div>
        <footer><span>↑↓ Navigate</span><span>↵ Open</span><span>Ctrl/⌘ K Search</span></footer>
    </section>
</div>
@endcan
<div id="toast-container"></div>
</body>
</html>
