<?php

use App\Application\Reports\RunActiveShipStationConflictReport;
use App\Application\Reports\RunAddressChangeReport;
use App\Application\Reports\RunAddressCheckReport;
use App\Application\Reports\RunAudit;
use App\Application\Reports\RunBundleCheckReport;
use App\Application\Reports\RunCarrierPerformanceReport;
use App\Application\Reports\RunCatalogQualityReport;
use App\Application\Reports\RunConsentAuditReport;
use App\Application\Reports\RunCountryMismatchReport;
use App\Application\Reports\RunCustomerLtvReport;
use App\Application\Reports\RunDiscountAbuseReport;
use App\Application\Reports\RunDisputeReport;
use App\Application\Reports\RunDuplicateAddressReport;
use App\Application\Reports\RunDuplicateOrderReport;
use App\Application\Reports\RunEmailCheckReport;
use App\Application\Reports\RunFraudRiskReport;
use App\Application\Reports\RunFulfilledItemsReport;
use App\Application\Reports\RunFulfillmentSlaReport;
use App\Application\Reports\RunGiftCardsReport;
use App\Application\Reports\RunHighValueNoPhoneReport;
use App\Application\Reports\RunInventoryAgingReport;
use App\Application\Reports\RunInventoryForecastReport;
use App\Application\Reports\RunInventoryOversellReport;
use App\Application\Reports\RunItemMismatchReport;
use App\Application\Reports\RunNoteFlagReport;
use App\Application\Reports\RunNoTrackingReport;
use App\Application\Reports\RunOnHoldStallReport;
use App\Application\Reports\RunOperationalDigest;
use App\Application\Reports\RunOrderContributionReport;
use App\Application\Reports\RunOrderEditReport;
use App\Application\Reports\RunOrphanOrderReport;
use App\Application\Reports\RunPartialFulfillmentReport;
use App\Application\Reports\RunPostShipAddressChangeReport;
use App\Application\Reports\RunProductCompletenessReport;
use App\Application\Reports\RunProductSyncReport;
use App\Application\Reports\RunRefundTrackerReport;
use App\Application\Reports\RunRepeatRefundReport;
use App\Application\Reports\RunReturnedItemsReport;
use App\Application\Reports\RunReturnRmaReport;
use App\Application\Reports\RunSameIpReport;
use App\Application\Reports\RunShipmentActivityReport;
use App\Application\Reports\RunShipmentAgingReport;
use App\Application\Reports\RunShipmentBacklogReport;
use App\Application\Reports\RunShipmentMovementMonitorReport;
use App\Application\Reports\RunShippedUnfulfilledReport;
use App\Application\Reports\RunShippingMarginReport;
use App\Application\Reports\RunShopifyBacklogReport;
use App\Application\Reports\RunSkuDuplicatesReport;
use App\Application\Reports\RunTagAuditReport;
use App\Application\Reports\RunTagPolicyReport;
use App\Application\Reports\RunTaxAuditReport;
use App\Application\Reports\RunVoidedShipmentsReport;
use App\Application\Reports\RunZombieProductsReport;
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
use App\Http\Controllers\Reports\FinancialExceptionsController;
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
use App\Http\Controllers\Reports\OperationalDigestController;
use App\Http\Controllers\Reports\OrderContributionController;
use App\Http\Controllers\Reports\OrderEditController;
use App\Http\Controllers\Reports\OrphanOrderController;
use App\Http\Controllers\Reports\PartialFulfillmentController;
use App\Http\Controllers\Reports\PostShipAddressChangeController;
use App\Http\Controllers\Reports\ProductCompletenessController;
use App\Http\Controllers\Reports\ProductSyncController;
use App\Http\Controllers\Reports\RateShoppingController;
use App\Http\Controllers\Reports\RefundTrackerController;
use App\Http\Controllers\Reports\RepeatRefundController;
use App\Http\Controllers\Reports\ReturnedItemsController;
use App\Http\Controllers\Reports\ReturnRmaController;
use App\Http\Controllers\Reports\RunAuditController;
use App\Http\Controllers\Reports\SameIpController;
use App\Http\Controllers\Reports\ShipmentActivityController;
use App\Http\Controllers\Reports\ShipmentAgingController;
use App\Http\Controllers\Reports\ShipmentMovementController;
use App\Http\Controllers\Reports\ShipmentMovementMonitorController;
use App\Http\Controllers\Reports\ShippedUnfulfilledController;
use App\Http\Controllers\Reports\ShippingMarginController;
use App\Http\Controllers\Reports\SkuDuplicatesController;
use App\Http\Controllers\Reports\TagAuditController;
use App\Http\Controllers\Reports\TagPolicyController;
use App\Http\Controllers\Reports\TaxAuditController;
use App\Http\Controllers\Reports\VoidedShipmentsController;
use App\Http\Controllers\Reports\ZombieProductsController;
use App\Http\Controllers\ReportTrendController;
use App\Integration;

/*
|--------------------------------------------------------------------------
| Tool registry
|--------------------------------------------------------------------------
|
| The single list of report and audit tools, read through App\Application\Reports\ReportRegistry.
| Report routes (routes/web.php), the audit navigation (config/audit-hub.php), the notification
| tool catalog (config/tool-catalog.php) and the generated section of docs/tools.md
| (`php artisan docs:tools`) are all derived from it.
|
| Tools are listed in navigation order and keyed by the exact `tool` string recorded in run_logs
| and report_runs. Each tool has:
|
| - slug:          URL segment; routes are named reports.<slug>, .store, .result and .export
| - label:         name in the navigation and docs
| - catalog_label: name in the notification rules, when it differs from the label
| - section:       navigation section, or null when the tool is linked from elsewhere
| - description:   one line for docs/tools.md
| - controller:    report controller; tools without one are plain navigation links (route)
| - requires:      integrations whose store credentials the tool needs
| - custom_routes: true when the controller's routes are declared by hand in routes/web.php
|
*/

return [
    'sections' => [
        'Core Audit',
        'Order Issues',
        'Address & Contact',
        'Fulfillment',
        'Carrier Analytics',
        'Products & Inventory',
        'Gift Cards',
        'Fraud & Compliance',
    ],

    'tools' => [
        'saved_reports' => [
            'route' => 'saved-reports.index',
            'label' => 'Saved Reports',
            'section' => 'Core Audit',
            'description' => 'View and download saved audit reports',
        ],
        'run_audit' => [
            'slug' => 'run-audit',
            'label' => 'Run Audit',
            'section' => 'Core Audit',
            'description' => 'Compare Shopify vs ShipStation for any date range',
            'controller' => RunAuditController::class,
            'schedule_action' => RunAudit::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
            'custom_routes' => true,
        ],
        'operational_digest' => [
            'slug' => 'operational-digest',
            'label' => 'Operational Digest',
            'section' => 'Core Audit',
            'description' => 'Paid pending orders, sync findings, unresolved issues and upcoming fulfillment deadlines',
            'controller' => OperationalDigestController::class,
            'schedule_action' => RunOperationalDigest::class,
            'requires' => [Integration::Shopify],
        ],
        'rate_shopping' => [
            'slug' => 'rate-shopping',
            'label' => 'Rate Shopping Audit',
            'section' => 'Core Audit',
            'description' => 'Captured quote decisions and clearly labelled current-rate simulations under approved service conditions',
            'controller' => RateShoppingController::class,
            'requires' => [Integration::ShipStation],
            'custom_routes' => true,
        ],
        'shipment_activity' => [
            'slug' => 'shipment-activity',
            'label' => 'Order arrivals and carrier acceptance',
            'section' => 'Core Audit',
            'description' => 'Measured Shopify order creations and recorded carrier acceptance events, with explicit coverage',
            'controller' => ShipmentActivityController::class,
            'schedule_action' => RunShipmentActivityReport::class,
            'requires' => [Integration::Shopify],
        ],
        'shipment_movement_monitor' => [
            'slug' => 'shipment-movement-monitor',
            'label' => 'Shipment movement monitoring',
            'section' => 'Core Audit',
            'description' => 'Bounded tracking cohort checks with verified access, shared UTC daily budget and fair rotation',
            'controller' => ShipmentMovementMonitorController::class,
            'schedule_action' => RunShipmentMovementMonitorReport::class,
            'requires' => [Integration::ShipStation],
        ],
        'shipment_movement' => [
            'slug' => 'shipment-movement',
            'label' => 'Shipment movement check',
            'section' => 'Core Audit',
            'description' => 'Manual carrier tracking checks for acceptance, stalled progress and exceptions',
            'controller' => ShipmentMovementController::class,
            'requires' => [Integration::ShipStation],
        ],
        'shipment_backlog' => [
            'route' => 'report-trends.index',
            'controller' => ReportTrendController::class,
            'custom_routes' => true,
            'label' => 'Shipment backlog history',
            'description' => 'Daily ShipStation backlog observations',
            'requires' => [Integration::ShipStation],
            'schedule_action' => RunShipmentBacklogReport::class,
        ],
        'shopify_backlog' => [
            'route' => 'report-trends.index',
            'controller' => ReportTrendController::class,
            'custom_routes' => true,
            'label' => 'Shopify fulfillment backlog',
            'description' => 'Observed remaining Shopify fulfillment quantities and estimated merchandise value by currency',
            'requires' => [Integration::Shopify],
            'schedule_action' => RunShopifyBacklogReport::class,
        ],
        'report_trends' => [
            'route' => 'report-trends.index',
            'label' => 'Trends',
            'section' => 'Core Audit',
            'description' => 'Aggregated stats across all audit reports',
        ],

        'duplicate_orders' => [
            'slug' => 'duplicate-orders',
            'label' => 'Duplicate Detector',
            'section' => 'Order Issues',
            'description' => 'Same customer, same total - placed within 10 minutes',
            'controller' => DuplicateOrderController::class,
            'schedule_action' => RunDuplicateOrderReport::class,
            'requires' => [Integration::Shopify],
        ],
        'refund_tracker' => [
            'slug' => 'refund-tracker',
            'label' => 'Refunds Tracker',
            'section' => 'Order Issues',
            'description' => 'Refunded Shopify orders cross-checked against ShipStation',
            'controller' => RefundTrackerController::class,
            'schedule_action' => RunRefundTrackerReport::class,
            'requires' => [Integration::Shopify],
        ],
        'repeat_refunds' => [
            'slug' => 'repeat-refunds',
            'label' => 'Repeat Refunds',
            'section' => 'Order Issues',
            'description' => 'Customers with multiple refunded orders in a date range',
            'controller' => RepeatRefundController::class,
            'schedule_action' => RunRepeatRefundReport::class,
            'requires' => [Integration::Shopify],
        ],
        'return_rma' => [
            'slug' => 'return-rma',
            'label' => 'Return / RMA Tracker',
            'section' => 'Order Issues',
            'description' => 'Overdue return requests, received items awaiting processing and unfinished exchanges',
            'controller' => ReturnRmaController::class,
            'schedule_action' => RunReturnRmaReport::class,
            'requires' => [Integration::Shopify],
        ],
        'returned_items' => [
            'slug' => 'returned-items',
            'label' => 'Returned Items Report',
            'section' => 'Order Issues',
            'description' => 'Itemized quantity totals for refunded line items in a date range',
            'controller' => ReturnedItemsController::class,
            'schedule_action' => RunReturnedItemsReport::class,
            'requires' => [Integration::Shopify],
        ],
        'orphan_orders' => [
            'slug' => 'orphan-orders',
            'label' => 'Orphan Detector',
            'section' => 'Order Issues',
            'description' => 'ShipStation orders with no matching Shopify order',
            'controller' => OrphanOrderController::class,
            'schedule_action' => RunOrphanOrderReport::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'active_shipstation_conflicts' => [
            'slug' => 'active-shipstation-conflicts',
            'label' => 'Active SS Conflicts',
            'catalog_label' => 'Active ShipStation Conflicts',
            'section' => 'Order Issues',
            'description' => 'Refunded or cancelled Shopify orders still active in ShipStation',
            'controller' => ActiveShipStationConflictController::class,
            'schedule_action' => RunActiveShipStationConflictReport::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'shipped_unfulfilled' => [
            'slug' => 'shipped-unfulfilled',
            'label' => 'SS Shipped / Shopify Unfulfilled',
            'section' => 'Order Issues',
            'description' => 'ShipStation shipped orders that Shopify still shows as unfulfilled (sync failure)',
            'controller' => ShippedUnfulfilledController::class,
            'schedule_action' => RunShippedUnfulfilledReport::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'order_edits' => [
            'slug' => 'order-edits',
            'label' => 'Order Edit History',
            'section' => 'Order Issues',
            'description' => 'Orders with post-placement edits: line items, discounts, notes or custom attributes',
            'controller' => OrderEditController::class,
            'schedule_action' => RunOrderEditReport::class,
            'requires' => [Integration::Shopify],
        ],
        'note_flags' => [
            'slug' => 'note-flags',
            'label' => 'Note Flags',
            'section' => 'Order Issues',
            'description' => 'Paid unfulfilled orders with flagged keywords in the order note',
            'controller' => NoteFlagController::class,
            'schedule_action' => RunNoteFlagReport::class,
            'requires' => [Integration::Shopify],
        ],

        'address_check' => [
            'slug' => 'address-check',
            'label' => 'Address Scanner',
            'catalog_label' => 'Address Check',
            'section' => 'Address & Contact',
            'description' => 'Paid orders with incomplete or invalid shipping addresses — required fields and postal code formats per country, plus phone validation for the shipping country',
            'controller' => AddressCheckController::class,
            'schedule_action' => RunAddressCheckReport::class,
            'requires' => [Integration::Shopify],
        ],
        'email_check' => [
            'slug' => 'email-check',
            'label' => 'Email Checker',
            'section' => 'Address & Contact',
            'description' => 'Orders with invalid, disposable or suspicious emails',
            'controller' => EmailCheckController::class,
            'schedule_action' => RunEmailCheckReport::class,
            'requires' => [Integration::Shopify],
        ],
        'high_value_no_phone' => [
            'slug' => 'high-value-no-phone',
            'label' => 'High-Value No Phone',
            'section' => 'Address & Contact',
            'description' => 'High-value unfulfilled orders whose shipping phone is missing or not valid for the shipping country',
            'controller' => HighValueNoPhoneController::class,
            'schedule_action' => RunHighValueNoPhoneReport::class,
            'requires' => [Integration::Shopify],
        ],
        'address_changes' => [
            'slug' => 'address-changes',
            'label' => 'Address Changes',
            'section' => 'Address & Contact',
            'description' => 'Orders whose shipping address was edited after placement',
            'controller' => AddressChangeController::class,
            'schedule_action' => RunAddressChangeReport::class,
            'requires' => [Integration::Shopify],
        ],
        'post_ship_address_changes' => [
            'slug' => 'post-ship-address-changes',
            'label' => 'Post-Ship Address Change',
            'section' => 'Address & Contact',
            'description' => 'Address edited AFTER the order was already fulfilled - package already in transit',
            'controller' => PostShipAddressChangeController::class,
            'schedule_action' => RunPostShipAddressChangeReport::class,
            'requires' => [Integration::Shopify],
        ],
        'duplicate_addresses' => [
            'slug' => 'duplicate-addresses',
            'label' => 'Duplicate Shipping Addresses',
            'section' => 'Address & Contact',
            'description' => 'Different customer emails shipping to the exact same address',
            'controller' => DuplicateAddressController::class,
            'schedule_action' => RunDuplicateAddressReport::class,
            'requires' => [Integration::Shopify],
        ],

        'voided_shipments' => [
            'slug' => 'voided-shipments',
            'label' => 'Voided Shipments',
            'section' => 'Fulfillment',
            'description' => 'ShipStation shipments voided in the selected date range',
            'controller' => VoidedShipmentsController::class,
            'schedule_action' => RunVoidedShipmentsReport::class,
            'requires' => [Integration::ShipStation],
        ],
        'fulfillment_sla' => [
            'slug' => 'fulfillment-sla',
            'label' => 'Fulfillment SLA Breaches',
            'section' => 'Fulfillment',
            'description' => 'Orders exceeding your time-to-first-fulfillment SLA by shipping method and region',
            'controller' => FulfillmentSlaController::class,
            'schedule_action' => RunFulfillmentSlaReport::class,
            'requires' => [Integration::Shopify],
        ],
        'bundle_check' => [
            'slug' => 'bundle-check',
            'label' => 'Bundle Check',
            'section' => 'Fulfillment',
            'description' => 'Bundled orders missing required companion items (Addon items)',
            'controller' => BundleCheckController::class,
            'schedule_action' => RunBundleCheckReport::class,
            'requires' => [Integration::Shopify],
        ],
        'partial_fulfillment' => [
            'slug' => 'partial-fulfillment',
            'label' => 'Partial Fulfillment Stalls',
            'section' => 'Fulfillment',
            'description' => 'Open orders partially shipped with unfulfilled items stalled for N+ days',
            'controller' => PartialFulfillmentController::class,
            'schedule_action' => RunPartialFulfillmentReport::class,
            'requires' => [Integration::Shopify],
        ],
        'on_hold_stall' => [
            'slug' => 'on-hold-stall',
            'label' => 'On-Hold Stall',
            'section' => 'Fulfillment',
            'description' => 'Fulfillment orders sitting on hold - sorted by how long the order has been waiting',
            'controller' => OnHoldStallController::class,
            'schedule_action' => RunOnHoldStallReport::class,
            'requires' => [Integration::Shopify],
        ],
        'no_tracking' => [
            'slug' => 'no-tracking',
            'label' => 'Fulfilled Without Tracking',
            'section' => 'Fulfillment',
            'description' => 'Fulfilled orders with no tracking number after a configurable grace period',
            'controller' => NoTrackingController::class,
            'schedule_action' => RunNoTrackingReport::class,
            'requires' => [Integration::Shopify],
        ],
        'shipment_aging' => [
            'slug' => 'shipment-aging',
            'label' => 'Shipment Aging',
            'section' => 'Fulfillment',
            'description' => 'ShipStation awaiting-shipment orders older than a configurable threshold',
            'controller' => ShipmentAgingController::class,
            'schedule_action' => RunShipmentAgingReport::class,
            'requires' => [Integration::ShipStation],
        ],
        'item_mismatch' => [
            'slug' => 'item-mismatch',
            'label' => 'Shipped Item Mismatch',
            'section' => 'Fulfillment',
            'description' => "ShipStation shipped items that don't match what was ordered in Shopify - catches picking errors, especially missing accessories on bundled products",
            'controller' => ItemMismatchController::class,
            'schedule_action' => RunItemMismatchReport::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'fulfilled_items' => [
            'slug' => 'fulfilled-items',
            'label' => 'Fulfilled Items Report',
            'section' => 'Fulfillment',
            'description' => 'Itemized quantity totals for orders fulfilled in a date range',
            'controller' => FulfilledItemsController::class,
            'schedule_action' => RunFulfilledItemsReport::class,
            'requires' => [Integration::Shopify],
        ],

        'carrier_performance' => [
            'slug' => 'carrier-performance',
            'label' => 'Carrier Performance',
            'section' => 'Carrier Analytics',
            'description' => 'Avg delivery time, late rate, and order count grouped by carrier for a date range',
            'controller' => CarrierPerformanceController::class,
            'schedule_action' => RunCarrierPerformanceReport::class,
            'requires' => [Integration::ShipStation],
        ],
        'shipping_billing' => [
            'route' => 'shipping-billing.index',
            'label' => 'Shipping Billing Audit',
            'section' => 'Carrier Analytics',
            'description' => 'Imported carrier charges, credits, disputes and expected label refunds',
        ],
        'financial_exceptions' => [
            'slug' => 'financial-exceptions',
            'label' => 'Financial Exceptions',
            'section' => 'Carrier Analytics',
            'description' => 'Manual Shopify Payments payout anomaly checks using included transactions and explicit thresholds',
            'controller' => FinancialExceptionsController::class,
            'requires' => [Integration::Shopify],
        ],
        'order_contribution' => [
            'slug' => 'order-contribution',
            'label' => 'Order Contribution Margin',
            'section' => 'Carrier Analytics',
            'description' => 'Shopify-reported net revenue and historical product costs, payment fees and matched ShipStation labels, with explicit missing costs and currency coverage',
            'controller' => OrderContributionController::class,
            'schedule_action' => RunOrderContributionReport::class,
            'requires' => [Integration::Shopify],
        ],
        'shipping_margin' => [
            'slug' => 'shipping-margin',
            'label' => 'Shipping Margin Erosion',
            'section' => 'Carrier Analytics',
            'description' => 'Orders where the ShipStation label cost exceeds what the customer was charged for shipping — flags orders shipped at a loss',
            'controller' => ShippingMarginController::class,
            'schedule_action' => RunShippingMarginReport::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],

        'product_completeness' => [
            'slug' => 'product-completeness',
            'label' => 'Product Completeness',
            'section' => 'Products & Inventory',
            'description' => 'Active products missing images, descriptions, or variant SKUs',
            'controller' => ProductCompletenessController::class,
            'schedule_action' => RunProductCompletenessReport::class,
            'requires' => [Integration::Shopify],
        ],
        'sku_duplicates' => [
            'slug' => 'sku-duplicates',
            'label' => 'SKU Duplicates',
            'section' => 'Products & Inventory',
            'description' => 'Variants sharing the same SKU across your product catalog',
            'controller' => SkuDuplicatesController::class,
            'schedule_action' => RunSkuDuplicatesReport::class,
            'requires' => [Integration::Shopify],
        ],
        'inventory_oversell' => [
            'slug' => 'inventory-oversell',
            'label' => 'Inventory Oversell Risk',
            'section' => 'Products & Inventory',
            'description' => 'SKUs where ShipStation awaiting qty exceeds available Shopify stock',
            'controller' => InventoryOversellController::class,
            'schedule_action' => RunInventoryOversellReport::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'inventory_aging' => [
            'slug' => 'inventory-aging',
            'label' => 'Inventory Aging',
            'section' => 'Products & Inventory',
            'description' => 'Zero-stock active variants that still sold recently',
            'controller' => InventoryAgingController::class,
            'schedule_action' => RunInventoryAgingReport::class,
            'requires' => [Integration::Shopify],
        ],
        'inventory_forecast' => [
            'slug' => 'inventory-forecast',
            'label' => 'Inventory Forecast',
            'section' => 'Products & Inventory',
            'description' => 'Days until zero stock based on 30-day sell-through rate per SKU',
            'controller' => InventoryForecastController::class,
            'schedule_action' => RunInventoryForecastReport::class,
            'requires' => [Integration::Shopify],
        ],
        'zombie_products' => [
            'slug' => 'zombie-products',
            'label' => 'Zombie Products',
            'section' => 'Products & Inventory',
            'description' => 'Active products with no variants or all tracked variants permanently out of stock',
            'controller' => ZombieProductsController::class,
            'schedule_action' => RunZombieProductsReport::class,
            'requires' => [Integration::Shopify],
        ],
        'product_sync' => [
            'slug' => 'product-sync',
            'label' => 'Product Sync & Package Weight',
            'section' => 'Products & Inventory',
            'description' => 'Compare SKU counterparts, product customs defaults and the declared weight of a selected shipment',
            'controller' => ProductSyncController::class,
            'schedule_action' => RunProductSyncReport::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'catalog_quality' => [
            'slug' => 'catalog-quality',
            'label' => 'Catalog Quality',
            'section' => 'Products & Inventory',
            'description' => 'Active product publishing, SEO and collection gaps, with optional Shopify/ShipStation customs-default checks and source coverage',
            'controller' => CatalogQualityController::class,
            'schedule_action' => RunCatalogQualityReport::class,
            'requires' => [Integration::Shopify],
        ],

        'gift_cards' => [
            'slug' => 'gift-cards',
            'label' => 'Gift Cards',
            'section' => 'Gift Cards',
            'description' => 'Unused or soon-to-expire gift card balances',
            'controller' => GiftCardsController::class,
            'schedule_action' => RunGiftCardsReport::class,
            'requires' => [Integration::Shopify],
        ],

        'country_mismatch' => [
            'slug' => 'country-mismatch',
            'label' => 'Billing ≠ Shipping Country',
            'catalog_label' => 'Billing / Shipping Country Mismatch',
            'section' => 'Fraud & Compliance',
            'description' => 'Paid orders where billing and shipping countries differ - a documented fraud signal',
            'controller' => CountryMismatchController::class,
            'schedule_action' => RunCountryMismatchReport::class,
            'requires' => [Integration::Shopify],
        ],
        'discount_abuse' => [
            'slug' => 'discount-abuse',
            'label' => 'Discount Abuse',
            'section' => 'Fraud & Compliance',
            'description' => 'Discount code clusters at the same shipping address across different emails',
            'controller' => DiscountAbuseController::class,
            'schedule_action' => RunDiscountAbuseReport::class,
            'requires' => [Integration::Shopify],
        ],
        'tag_policy' => [
            'slug' => 'tag-policy',
            'label' => 'Tag Policy Audit',
            'section' => 'Fraud & Compliance',
            'description' => 'Required and forbidden Shopify tag combinations from local policy rules',
            'controller' => TagPolicyController::class,
            'schedule_action' => RunTagPolicyReport::class,
            'requires' => [Integration::Shopify],
        ],
        'tax_audit' => [
            'slug' => 'tax-audit',
            'label' => 'Tax Audit',
            'section' => 'Fraud & Compliance',
            'description' => 'Paid orders above a minimum amount with $0 tax charged to a non-exempt customer',
            'controller' => TaxAuditController::class,
            'schedule_action' => RunTaxAuditReport::class,
            'requires' => [Integration::Shopify],
        ],
        'consent_audit' => [
            'slug' => 'consent-audit',
            'label' => 'Marketing Consent Audit',
            'section' => 'Fraud & Compliance',
            'description' => 'Orders from customers without active email marketing consent - a compliance risk if targeted',
            'controller' => ConsentAuditController::class,
            'schedule_action' => RunConsentAuditReport::class,
            'requires' => [Integration::Shopify],
        ],
        'fraud_risk' => [
            'slug' => 'fraud-risk',
            'label' => 'Fraud Risk Report',
            'section' => 'Fraud & Compliance',
            'description' => 'Paid orders scored by combined fraud signals - disposable email, country mismatch, HIGH risk level, and more',
            'controller' => FraudRiskController::class,
            'schedule_action' => RunFraudRiskReport::class,
            'requires' => [Integration::Shopify],
        ],
        'same_ip' => [
            'slug' => 'same-ip',
            'label' => 'Same IP, Different Emails',
            'section' => 'Fraud & Compliance',
            'description' => 'Client IP addresses used by two or more distinct customer emails - a fraud ring signal',
            'controller' => SameIpController::class,
            'schedule_action' => RunSameIpReport::class,
            'requires' => [Integration::Shopify],
        ],
        'disputes' => [
            'slug' => 'disputes',
            'label' => 'Chargebacks / Disputes',
            'section' => 'Fraud & Compliance',
            'description' => 'Open Shopify Payments disputes needing evidence, sorted by response deadline',
            'controller' => DisputeController::class,
            'schedule_action' => RunDisputeReport::class,
            'requires' => [Integration::Shopify],
        ],

        /* Linked from the search navigation (config/search-hub.php), not the audit navigation. */
        'customer_ltv' => [
            'slug' => 'customer-ltv',
            'label' => 'Customer LTV',
            'section' => null,
            'description' => 'Top customers by lifetime value and monthly cohort retention for the selected period.',
            'controller' => CustomerLtvController::class,
            'schedule_action' => RunCustomerLtvReport::class,
            'requires' => [Integration::Shopify],
        ],
        'tag_audit' => [
            'slug' => 'tag-audit',
            'label' => 'Tag Audit',
            'section' => null,
            'description' => 'Build a complete tag inventory across a date range with frequency and last-seen info.',
            'controller' => TagAuditController::class,
            'schedule_action' => RunTagAuditReport::class,
            'requires' => [Integration::Shopify],
        ],
    ],
];
