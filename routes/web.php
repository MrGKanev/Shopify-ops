<?php

use App\Http\Controllers\ActiveStoreController;
use App\Http\Controllers\Admin\ActionLogController;
use App\Http\Controllers\Admin\ApiHealthController;
use App\Http\Controllers\Admin\AppearanceSettingsController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\BackupVerificationController;
use App\Http\Controllers\Admin\BannedIpController;
use App\Http\Controllers\Admin\CacheFlushController;
use App\Http\Controllers\Admin\ConfigCheckController;
use App\Http\Controllers\Admin\DiscordRulesController;
use App\Http\Controllers\Admin\EmailRulesController;
use App\Http\Controllers\Admin\HealthIncidentController;
use App\Http\Controllers\Admin\OperationalHealthController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SlackRulesController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookEventController;
use App\Http\Controllers\Admin\WebhookHealthController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\CommandPaletteController;
use App\Http\Controllers\CustomerLookupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\GoogleAuthenticationController;
use App\Http\Controllers\IgnoredOrderController;
use App\Http\Controllers\InstallApplicationController;
use App\Http\Controllers\JobQueueController;
use App\Http\Controllers\MetafieldController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\OperationalIssueController;
use App\Http\Controllers\OrderBatchLookupController;
use App\Http\Controllers\OrderComparisonController;
use App\Http\Controllers\OrderLookupController;
use App\Http\Controllers\OrderNoteController;
use App\Http\Controllers\OrderTagSearchController;
use App\Http\Controllers\OrderTimelineController;
use App\Http\Controllers\OrderTrackingController;
use App\Http\Controllers\PackingSlipController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PrintQueueController;
use App\Http\Controllers\PushLogController;
use App\Http\Controllers\PushToShipStationController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\Reports\ActiveShipStationConflictController;
use App\Http\Controllers\Reports\AddressChangeController;
use App\Http\Controllers\Reports\AddressCheckController;
use App\Http\Controllers\Reports\BundleCheckController;
use App\Http\Controllers\Reports\CarrierPerformanceController;
use App\Http\Controllers\Reports\CatalogQualityController;
use App\Http\Controllers\Reports\ConsentAuditController;
use App\Http\Controllers\Reports\CountryMismatchController;
use App\Http\Controllers\Reports\CustomerLtvController;
use App\Http\Controllers\Reports\DiscountAbuseController;
use App\Http\Controllers\Reports\DisputeController;
use App\Http\Controllers\Reports\DuplicateAddressController;
use App\Http\Controllers\Reports\DuplicateOrderController;
use App\Http\Controllers\Reports\EmailCheckController;
use App\Http\Controllers\Reports\FraudRiskController;
use App\Http\Controllers\Reports\FulfilledItemsController;
use App\Http\Controllers\Reports\FulfillmentSlaController;
use App\Http\Controllers\Reports\GiftCardsController;
use App\Http\Controllers\Reports\HighValueNoPhoneController;
use App\Http\Controllers\Reports\InventoryAgingController;
use App\Http\Controllers\Reports\InventoryForecastController;
use App\Http\Controllers\Reports\InventoryOversellController;
use App\Http\Controllers\Reports\ItemMismatchController;
use App\Http\Controllers\Reports\NoteFlagController;
use App\Http\Controllers\Reports\NoTrackingController;
use App\Http\Controllers\Reports\OnHoldStallController;
use App\Http\Controllers\Reports\OrderEditController;
use App\Http\Controllers\Reports\OrphanOrderController;
use App\Http\Controllers\Reports\PartialFulfillmentController;
use App\Http\Controllers\Reports\PostShipAddressChangeController;
use App\Http\Controllers\Reports\ProductCompletenessController;
use App\Http\Controllers\Reports\RefundTrackerController;
use App\Http\Controllers\Reports\RepeatRefundController;
use App\Http\Controllers\Reports\ReturnedItemsController;
use App\Http\Controllers\Reports\ReturnRmaController;
use App\Http\Controllers\Reports\RunAuditController;
use App\Http\Controllers\Reports\SameIpController;
use App\Http\Controllers\Reports\ShipmentAgingController;
use App\Http\Controllers\Reports\ShippedUnfulfilledController;
use App\Http\Controllers\Reports\ShippingMarginController;
use App\Http\Controllers\Reports\SkuDuplicatesController;
use App\Http\Controllers\Reports\TagAuditController;
use App\Http\Controllers\Reports\TagPolicyController;
use App\Http\Controllers\Reports\TaxAuditController;
use App\Http\Controllers\Reports\VoidedShipmentsController;
use App\Http\Controllers\Reports\ZombieProductsController;
use App\Http\Controllers\ReportTrendController;
use App\Http\Controllers\RunLogController;
use App\Http\Controllers\SavedReportController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TwoFactorSettingsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::get('/ready', ReadinessController::class)->name('ready');
Route::get('/status', StatusController::class)->name('status');
Route::get('/metrics', MetricsController::class)->name('metrics');
Route::post('/webhooks/shopify/{store:slug}', ShopifyWebhookController::class)->middleware('throttle:120,1')->name('webhooks.shopify');
Route::get('/install', [InstallApplicationController::class, 'create'])->name('install.create');
Route::post('/install', [InstallApplicationController::class, 'store'])->middleware('throttle:installation')->name('install.store');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');
    Route::get('/auth/google/redirect', [GoogleAuthenticationController::class, 'redirect'])->middleware('throttle:oauth')->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthenticationController::class, 'callback'])->middleware('throttle:oauth')->name('auth.google.callback');
    Route::post('/dev-login/{role}', [AuthenticatedSessionController::class, 'devLogin'])->whereIn('role', ['admin', 'operator'])->name('dev-login');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/security/two-factor', TwoFactorSettingsController::class)->middleware('active.store')->name('two-factor.settings');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/stores/{store}/active', ActiveStoreController::class)->name('stores.active');

    Route::middleware('active.store')->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/orders/lookup', OrderLookupController::class)->name('orders.lookup');
        Route::get('/orders/push', [PushToShipStationController::class, 'create'])->middleware('can:run-audits')->name('orders.push.create');
        Route::post('/orders/push', [PushToShipStationController::class, 'preview'])->middleware(['can:run-audits', 'throttle:push-order'])->name('orders.push.preview');
        Route::post('/orders/push/confirm', [PushToShipStationController::class, 'store'])->middleware(['can:run-audits', 'throttle:push-order'])->name('orders.push.store');
        Route::post('/orders/note', [OrderNoteController::class, 'update'])->middleware(['can:run-audits', 'throttle:push-order'])->name('orders.note.update');
        Route::get('/orders/spot-check', [OrderBatchLookupController::class, 'create'])->name('orders.spot-check');
        Route::post('/orders/spot-check', [OrderBatchLookupController::class, 'store'])
            ->middleware('throttle:spot-check')
            ->name('orders.spot-check.store');
        Route::get('/orders/compare', OrderComparisonController::class)->name('orders.compare');
        Route::get('/orders/timeline', OrderTimelineController::class)->name('orders.timeline');
        Route::get('/orders/tracking', [OrderTrackingController::class, 'create'])->name('orders.tracking');
        Route::post('/orders/tracking', [OrderTrackingController::class, 'store'])->middleware('throttle:tracking')->name('orders.tracking.store');
        Route::get('/orders/packing-slip', [PackingSlipController::class, 'create'])->name('orders.packing-slip');
        Route::post('/orders/packing-slip', [PackingSlipController::class, 'store'])->middleware('throttle:packing-slip')->name('orders.packing-slip.store');
        Route::get('/orders/tag-search', [OrderTagSearchController::class, 'create'])->name('orders.tag-search');
        Route::post('/orders/tag-search', [OrderTagSearchController::class, 'store'])->middleware('throttle:tag-search')->name('orders.tag-search.store');
        Route::get('/customers/lookup', [CustomerLookupController::class, 'create'])->name('customers.lookup');
        Route::post('/customers/lookup', [CustomerLookupController::class, 'store'])->middleware('throttle:audit-report')->name('customers.lookup.store');
        Route::get('/metafields', [MetafieldController::class, 'create'])->name('metafields.index');
        Route::get('/search', GlobalSearchController::class)->middleware('can:run-audits')->name('global-search');
        Route::get('/command-palette', CommandPaletteController::class)->middleware(['can:run-audits', 'throttle:120,1'])->name('command-palette');
        Route::post('/metafields/search', [MetafieldController::class, 'search'])->middleware('throttle:audit-report')->name('metafields.search');
        Route::post('/metafields/lookup', [MetafieldController::class, 'lookup'])->middleware('throttle:audit-report')->name('metafields.lookup');
        Route::get('/ignored-orders', [IgnoredOrderController::class, 'index'])->middleware('can:run-audits')->name('ignored-orders.index');
        Route::post('/ignored-orders', [IgnoredOrderController::class, 'store'])->middleware('can:run-audits')->name('ignored-orders.store');
        Route::post('/ignored-orders/import', [IgnoredOrderController::class, 'import'])->middleware('can:run-audits')->name('ignored-orders.import');
        Route::post('/ignored-orders/bulk', [IgnoredOrderController::class, 'bulkStore'])->middleware('can:run-audits')->name('ignored-orders.bulk-store');
        Route::delete('/ignored-orders', [IgnoredOrderController::class, 'bulkDestroy'])->middleware('can:run-audits')->name('ignored-orders.bulk-destroy');
        Route::delete('/ignored-orders/{ignoredOrder}', [IgnoredOrderController::class, 'destroy'])->middleware('can:run-audits')->name('ignored-orders.destroy');
        Route::get('/push-logs', PushLogController::class)->name('push-logs.index');
        Route::get('/issues', [OperationalIssueController::class, 'index'])->middleware('can:run-audits')->name('operational-issues.index');
        Route::put('/issues/{issue}', [OperationalIssueController::class, 'update'])->whereNumber('issue')->middleware('can:run-audits')->name('operational-issues.update');
        Route::get('/run-logs', RunLogController::class)->name('run-logs.index');
        Route::get('/saved-reports', [SavedReportController::class, 'index'])->middleware('can:run-audits')->name('saved-reports.index');
        Route::get('/report-trends', ReportTrendController::class)->middleware('can:run-audits')->name('report-trends.index');
        Route::get('/saved-reports/{report}', [SavedReportController::class, 'show'])->whereNumber('report')->middleware('can:run-audits')->name('saved-reports.show');
        Route::get('/saved-reports/{report}/export', [SavedReportController::class, 'export'])->whereNumber('report')->middleware('can:run-audits')->name('saved-reports.export');
        Route::get('/jobs', [JobQueueController::class, 'index'])->middleware('can:run-audits')->name('jobs.index');
        Route::get('/print-queue', [PrintQueueController::class, 'index'])->middleware('can:run-audits')->name('print-queue.index');
        Route::post('/print-queue', [PrintQueueController::class, 'store'])->middleware('can:run-audits')->name('print-queue.store');
        Route::delete('/print-queue', [PrintQueueController::class, 'clear'])->middleware('can:run-audits')->name('print-queue.clear');
        Route::delete('/print-queue/{item}', [PrintQueueController::class, 'destroy'])->whereNumber('item')->middleware('can:run-audits')->name('print-queue.destroy');
        Route::post('/jobs/failed/{uuid}/retry', [JobQueueController::class, 'retry'])->middleware('can:run-audits')->name('jobs.retry');
        Route::delete('/jobs/failed/{uuid}', [JobQueueController::class, 'destroy'])->middleware('can:run-audits')->name('jobs.destroy');
        Route::middleware('can:run-audits')->group(function (): void {
            Route::view('/reports', 'reports.index')->name('audits.index');
            Route::get('/reports/run-audit', [RunAuditController::class, 'create'])->name('reports.run-audit');
            Route::post('/reports/run-audit', [RunAuditController::class, 'store'])->middleware('throttle:audit-report')->name('reports.run-audit.store');
            Route::post('/reports/run-audit/queue', [RunAuditController::class, 'queue'])->middleware('throttle:audit-report')->name('reports.run-audit.queue');
            /**
             * Every report exposes the same create/store pair, plus an export endpoint when the
             * controller implements one.
             *
             * @var array<string, class-string> $reportControllers
             */
            $reportControllers = [
                'high-value-no-phone' => HighValueNoPhoneController::class,
                'country-mismatch' => CountryMismatchController::class,
                'consent-audit' => ConsentAuditController::class,
                'fraud-risk' => FraudRiskController::class,
                'email-check' => EmailCheckController::class,
                'address-check' => AddressCheckController::class,
                'discount-abuse' => DiscountAbuseController::class,
                'same-ip' => SameIpController::class,
                'duplicate-orders' => DuplicateOrderController::class,
                'customer-ltv' => CustomerLtvController::class,
                'tag-policy' => TagPolicyController::class,
                'disputes' => DisputeController::class,
                'duplicate-addresses' => DuplicateAddressController::class,
                'note-flags' => NoteFlagController::class,
                'order-edits' => OrderEditController::class,
                'address-changes' => AddressChangeController::class,
                'post-ship-address-changes' => PostShipAddressChangeController::class,
                'voided-shipments' => VoidedShipmentsController::class,
                'fulfillment-sla' => FulfillmentSlaController::class,
                'bundle-check' => BundleCheckController::class,
                'partial-fulfillment' => PartialFulfillmentController::class,
                'on-hold-stall' => OnHoldStallController::class,
                'no-tracking' => NoTrackingController::class,
                'shipment-aging' => ShipmentAgingController::class,
                'item-mismatch' => ItemMismatchController::class,
                'orphan-orders' => OrphanOrderController::class,
                'active-shipstation-conflicts' => ActiveShipStationConflictController::class,
                'shipped-unfulfilled' => ShippedUnfulfilledController::class,
                'repeat-refunds' => RepeatRefundController::class,
                'refund-tracker' => RefundTrackerController::class,
                'return-rma' => ReturnRmaController::class,
                'returned-items' => ReturnedItemsController::class,
                'fulfilled-items' => FulfilledItemsController::class,
                'carrier-performance' => CarrierPerformanceController::class,
                'shipping-margin' => ShippingMarginController::class,
                'tag-audit' => TagAuditController::class,
                'tax-audit' => TaxAuditController::class,
                'product-completeness' => ProductCompletenessController::class,
                'sku-duplicates' => SkuDuplicatesController::class,
                'inventory-oversell' => InventoryOversellController::class,
                'inventory-aging' => InventoryAgingController::class,
                'inventory-forecast' => InventoryForecastController::class,
                'zombie-products' => ZombieProductsController::class,
                'catalog-quality' => CatalogQualityController::class,
                'gift-cards' => GiftCardsController::class,
            ];
            foreach ($reportControllers as $slug => $controller) {
                Route::get("/reports/{$slug}", [$controller, 'create'])->name("reports.{$slug}");
                Route::post("/reports/{$slug}", [$controller, 'store'])->middleware('throttle:audit-report')->name("reports.{$slug}.store");
                if (method_exists($controller, 'export')) {
                    Route::post("/reports/{$slug}/export", [$controller, 'export'])->middleware('throttle:audit-report')->name("reports.{$slug}.export");
                }
            }
        });

        Route::prefix('admin')
            ->name('admin.')
            ->middleware('can:manage-administration')
            ->group(function (): void {
                Route::get('/api-health', [ApiHealthController::class, 'show'])->name('api-health');
                Route::get('/settings', SettingsController::class)->name('settings');
                Route::put('/appearance', [AppearanceSettingsController::class, 'update'])->name('appearance.update');
                Route::get('/config-check', ConfigCheckController::class)->name('config-check');
                Route::get('/webhook-health', WebhookHealthController::class)->name('webhook-health');
                Route::get('/webhook-events', WebhookEventController::class)->name('webhook-events');
                Route::post('/webhook-events/{event}/retry', [WebhookEventController::class, 'retry'])->whereNumber('event')->name('webhook-events.retry');
                Route::get('/slack-rules', [SlackRulesController::class, 'edit'])->name('slack-rules.edit');
                Route::put('/slack-rules', [SlackRulesController::class, 'update'])->name('slack-rules.update');
                Route::get('/discord-rules', [DiscordRulesController::class, 'edit'])->name('discord-rules.edit');
                Route::put('/discord-rules', [DiscordRulesController::class, 'update'])->name('discord-rules.update');
                Route::get('/email-rules', [EmailRulesController::class, 'edit'])->name('email-rules.edit');
                Route::put('/email-rules', [EmailRulesController::class, 'update'])->name('email-rules.update');
                Route::get('/action-log', ActionLogController::class)->name('action-log');
                Route::get('/banned-ips', [BannedIpController::class, 'index'])->name('banned-ips.index');
                Route::delete('/banned-ips', [BannedIpController::class, 'destroy'])->name('banned-ips.destroy');
                Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
                Route::post('/backups', [BackupController::class, 'store'])->middleware('throttle:api-health')->name('backups.store');
                Route::post('/backups/restore', [BackupController::class, 'restore'])->middleware('throttle:api-health')->name('backups.restore');
                Route::post('/backups/verify', BackupVerificationController::class)->middleware('throttle:api-health')->name('backups.verify');
                Route::get('/backups/download/{path}', [BackupController::class, 'download'])->where('path', '.*')->name('backups.download');
                Route::get('/health', OperationalHealthController::class)->name('health');
                Route::get('/health/incidents', HealthIncidentController::class)->name('health-incidents');
                Route::post('/api-health', [ApiHealthController::class, 'check'])->middleware('throttle:api-health')->name('api-health.check');
                Route::post('/api-health/test-email', [ApiHealthController::class, 'sendTestEmail'])->middleware('throttle:api-health')->name('api-health.test-email');
                Route::post('/api-health/test-slack', [ApiHealthController::class, 'sendTestSlack'])->middleware('throttle:api-health')->name('api-health.test-slack');
                Route::post('/api-health/test-discord', [ApiHealthController::class, 'sendTestDiscord'])->middleware('throttle:api-health')->name('api-health.test-discord');
                Route::resource('stores', StoreController::class)->except(['show', 'destroy']);
                Route::resource('users', UserController::class)->except(['show', 'destroy']);
                Route::post('/cache/flush', CacheFlushController::class)->name('cache.flush');
            });
    });
});
