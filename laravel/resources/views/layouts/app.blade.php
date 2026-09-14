<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@php
    $route = request()->route()?->getName() ?? '';
    $group = str_starts_with($route, 'admin.') ? 'settings' : (in_array($route, ['ignored-orders.index','push-logs.index','run-logs.index','jobs.index','print-queue.index'], true) ? 'manage' : (str_starts_with($route, 'orders.') || in_array($route, ['customers.lookup','metafields.index','global-search'], true) ? 'search' : (str_starts_with($route, 'reports.') || str_starts_with($route, 'saved-reports.') || $route === 'report-trends.index' ? 'audit' : 'dashboard')));
    $contextLinks = match($group) {
        'manage' => [['Ignored Orders','ignored-orders.index'],['Push Log','push-logs.index'],['Run History','run-logs.index'],['Job Queue','jobs.index'],['Print Queue','print-queue.index']],
        'settings' => [['Configuration','admin.settings'],['API Health','admin.api-health'],['Config Check','admin.config-check'],['Webhook Health','admin.webhook-health'],['Slack Rules','admin.slack-rules.edit'],['Discord Rules','admin.discord-rules.edit'],['Email Rules','admin.email-rules.edit'],['Stores','admin.stores.index'],['Users','admin.users.index'],['Action Log','admin.action-log'],['Banned IPs','admin.banned-ips.index'],['Backups','admin.backups.index'],['Health','admin.health']],
        default => [],
    };
    // Laravel-only search additions with no equivalent in legacy's grouped hub (search-hub.php mirrors legacy exactly).
    $searchExtras = [['Order Lookup','orders.lookup'],['Push Order / Fix Note','orders.push.create'],['Global Search','global-search']];
@endphp
<header class="mobile-header"><div class="brand">Shopify Ops <span class="header-store">{{ $activeStore->label }}</span></div><button class="hamburger" id="js-hamburger" type="button" aria-label="Menu"><span></span><span></span><span></span></button></header>
<div class="sidebar-overlay" id="js-overlay"></div>
<div class="layout">
    <aside class="sidebar" id="js-sidebar">
        <div class="sidebar-header">
            <div class="sidebar-header-top"><a class="brand" href="{{ route('dashboard') }}">Shopify <span>Ops</span></a><button class="theme-icon-btn" id="js-theme-toggle" type="button" title="Toggle theme"><span id="js-theme-icon">🌙</span></button></div>
            <div class="store"><span class="store-label">Store</span> {{ $activeStore->shopify_store }}</div>
            @if($availableStores->count() > 1)<form class="store-switcher" method="POST" action="{{ route('stores.active', $activeStore) }}" data-store-switcher>@csrf<select class="store-select" title="Switch store">@foreach($availableStores as $store)<option value="{{ route('stores.active', $store) }}" @selected($store->is($activeStore))>{{ $store->label }}</option>@endforeach</select></form>@endif
        </div>
        <nav class="flat-nav" aria-label="Primary">
            <a class="flat-nav-link {{ $group === 'dashboard' ? 'active' : '' }}" href="{{ route('dashboard') }}"><span class="flat-nav-icon">🏠</span><span class="nav-label">Dashboard</span></a>
            @can('run-audits')<a class="flat-nav-link {{ $group === 'audit' ? 'active' : '' }}" href="{{ route('reports.run-audit') }}"><span class="flat-nav-icon">📋</span><span class="nav-label">Audit</span></a>@endcan
            <a class="flat-nav-link {{ $group === 'search' ? 'active' : '' }}" href="{{ route('orders.lookup') }}"><span class="flat-nav-icon">🔎</span><span class="nav-label">Search &amp; Lookup</span></a>
            <a class="flat-nav-link {{ $group === 'manage' ? 'active' : '' }}" href="{{ route('push-logs.index') }}"><span class="flat-nav-icon">📂</span><span class="nav-label">Manage</span></a>
            @can('manage-administration')<a class="flat-nav-link {{ $group === 'settings' ? 'active' : '' }}" href="{{ route('admin.settings') }}"><span class="flat-nav-icon">⚙</span><span class="nav-label">Settings</span></a>@endcan
        </nav>
        @if(in_array($group, ['audit', 'search'], true))
            @if($group === 'search')<div class="sidebar-section">Search</div><ul class="sidebar-nav">@foreach($searchExtras as [$label,$name])@if(!in_array($name,['orders.push.create','global-search'],true) || auth()->user()->can('run-audits'))<li><a class="{{ request()->routeIs($name) ? 'active' : '' }}" href="{{ route($name) }}">{{ $label }}</a></li>@endif @endforeach</ul>@endif
            @foreach(config($group === 'audit' ? 'audit-hub' : 'search-hub') as $section => $links)<div class="sidebar-section">{{ $section }}</div><ul class="sidebar-nav">@foreach($links as $link)<li><a class="{{ request()->routeIs($link['route']) ? 'active' : '' }}" href="{{ route($link['route']) }}">{{ $link['label'] }}</a></li>@endforeach</ul>@endforeach
        @elseif($contextLinks)<div class="sidebar-section">{{ ucfirst($group) }}</div><ul class="sidebar-nav">@foreach($contextLinks as [$label,$name])@if((!in_array($name,['jobs.index','print-queue.index'],true) || auth()->user()->can('run-audits')) && (!str_starts_with($name,'admin.') || auth()->user()->can('manage-administration')))<li><a class="{{ request()->routeIs($name) ? 'active' : '' }}" href="{{ route($name) }}">{{ $label }}</a></li>@endif @endforeach</ul>@endif
        @can('run-audits')<div class="sidebar-search"><form method="GET" action="{{ route('global-search') }}"><input class="sidebar-search-input" name="q" type="search" placeholder="Search order #…" value="{{ request('q') }}" autocomplete="off"></form></div>@endcan
        <div class="sidebar-footer"><form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost btn-sm btn-full sidebar-signout-btn" type="submit">Sign out</button></form><div class="sidebar-footer-row"><span class="sidebar-github">{{ auth()->user()->name }} · {{ auth()->user()->role->value }}</span><button class="sidebar-collapse-btn" id="js-sidebar-collapse" type="button" title="Collapse sidebar" aria-label="Collapse sidebar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg></button></div></div>
    </aside>
    <main class="main">@if(session('status'))<div class="flash toast">{{ session('status') }}</div>@endif @yield('content')</main>
</div>
<div id="toast-container"></div>
</body>
</html>
