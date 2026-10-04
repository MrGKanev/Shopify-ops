<?php

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
            'requires' => [Integration::Shopify, Integration::ShipStation],
            'custom_routes' => true,
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
            'requires' => [Integration::Shopify],
        ],
        'refund_tracker' => [
            'slug' => 'refund-tracker',
            'label' => 'Refunds Tracker',
            'section' => 'Order Issues',
            'description' => 'Refunded Shopify orders cross-checked against ShipStation',
            'controller' => RefundTrackerController::class,
            'requires' => [Integration::Shopify],
        ],
        'repeat_refunds' => [
            'slug' => 'repeat-refunds',
            'label' => 'Repeat Refunds',
            'section' => 'Order Issues',
            'description' => 'Customers with multiple refunded orders in a date range',
            'controller' => RepeatRefundController::class,
            'requires' => [Integration::Shopify],
        ],
        'return_rma' => [
            'slug' => 'return-rma',
            'label' => 'Return / RMA Tracker',
            'section' => 'Order Issues',
            'description' => 'Refunded orders with item-level return details and per-SKU return rate summary',
            'controller' => ReturnRmaController::class,
            'requires' => [Integration::Shopify],
        ],
        'returned_items' => [
            'slug' => 'returned-items',
            'label' => 'Returned Items Report',
            'section' => 'Order Issues',
            'description' => 'Itemized quantity totals for refunded line items in a date range',
            'controller' => ReturnedItemsController::class,
            'requires' => [Integration::Shopify],
        ],
        'orphan_orders' => [
            'slug' => 'orphan-orders',
            'label' => 'Orphan Detector',
            'section' => 'Order Issues',
            'description' => 'ShipStation orders with no matching Shopify order',
            'controller' => OrphanOrderController::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'active_shipstation_conflicts' => [
            'slug' => 'active-shipstation-conflicts',
            'label' => 'Active SS Conflicts',
            'catalog_label' => 'Active ShipStation Conflicts',
            'section' => 'Order Issues',
            'description' => 'Refunded or cancelled Shopify orders still active in ShipStation',
            'controller' => ActiveShipStationConflictController::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'shipped_unfulfilled' => [
            'slug' => 'shipped-unfulfilled',
            'label' => 'SS Shipped / Shopify Unfulfilled',
            'section' => 'Order Issues',
            'description' => 'ShipStation shipped orders that Shopify still shows as unfulfilled (sync failure)',
            'controller' => ShippedUnfulfilledController::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'order_edits' => [
            'slug' => 'order-edits',
            'label' => 'Order Edit History',
            'section' => 'Order Issues',
            'description' => 'Orders with post-placement edits: line items, discounts, notes or custom attributes',
            'controller' => OrderEditController::class,
            'requires' => [Integration::Shopify],
        ],
        'note_flags' => [
            'slug' => 'note-flags',
            'label' => 'Note Flags',
            'section' => 'Order Issues',
            'description' => 'Paid unfulfilled orders with flagged keywords in the order note',
            'controller' => NoteFlagController::class,
            'requires' => [Integration::Shopify],
        ],

        'address_check' => [
            'slug' => 'address-check',
            'label' => 'Address Scanner',
            'catalog_label' => 'Address Check',
            'section' => 'Address & Contact',
            'description' => 'Paid orders with incomplete or invalid shipping addresses — required fields and postal code formats per country, plus phone validation for the shipping country',
            'controller' => AddressCheckController::class,
            'requires' => [Integration::Shopify],
        ],
        'email_check' => [
            'slug' => 'email-check',
            'label' => 'Email Checker',
            'section' => 'Address & Contact',
            'description' => 'Orders with invalid, disposable or suspicious emails',
            'controller' => EmailCheckController::class,
            'requires' => [Integration::Shopify],
        ],
        'high_value_no_phone' => [
            'slug' => 'high-value-no-phone',
            'label' => 'High-Value No Phone',
            'section' => 'Address & Contact',
            'description' => 'High-value unfulfilled orders whose shipping phone is missing or not valid for the shipping country',
            'controller' => HighValueNoPhoneController::class,
            'requires' => [Integration::Shopify],
        ],
        'address_changes' => [
            'slug' => 'address-changes',
            'label' => 'Address Changes',
            'section' => 'Address & Contact',
            'description' => 'Orders whose shipping address was edited after placement',
            'controller' => AddressChangeController::class,
            'requires' => [Integration::Shopify],
        ],
        'post_ship_address_changes' => [
            'slug' => 'post-ship-address-changes',
            'label' => 'Post-Ship Address Change',
            'section' => 'Address & Contact',
            'description' => 'Address edited AFTER the order was already fulfilled - package already in transit',
            'controller' => PostShipAddressChangeController::class,
            'requires' => [Integration::Shopify],
        ],
        'duplicate_addresses' => [
            'slug' => 'duplicate-addresses',
            'label' => 'Duplicate Shipping Addresses',
            'section' => 'Address & Contact',
            'description' => 'Different customer emails shipping to the exact same address',
            'controller' => DuplicateAddressController::class,
            'requires' => [Integration::Shopify],
        ],

        'voided_shipments' => [
            'slug' => 'voided-shipments',
            'label' => 'Voided Shipments',
            'section' => 'Fulfillment',
            'description' => 'ShipStation shipments voided in the selected date range',
            'controller' => VoidedShipmentsController::class,
            'requires' => [Integration::ShipStation],
        ],
        'fulfillment_sla' => [
            'slug' => 'fulfillment-sla',
            'label' => 'Fulfillment SLA Breaches',
            'section' => 'Fulfillment',
            'description' => 'Orders exceeding your time-to-first-fulfillment SLA by shipping method and region',
            'controller' => FulfillmentSlaController::class,
            'requires' => [Integration::Shopify],
        ],
        'bundle_check' => [
            'slug' => 'bundle-check',
            'label' => 'Bundle Check',
            'section' => 'Fulfillment',
            'description' => 'Bundled orders missing required companion items (Addon items)',
            'controller' => BundleCheckController::class,
            'requires' => [Integration::Shopify],
        ],
        'partial_fulfillment' => [
            'slug' => 'partial-fulfillment',
            'label' => 'Partial Fulfillment Stalls',
            'section' => 'Fulfillment',
            'description' => 'Open orders partially shipped with unfulfilled items stalled for N+ days',
            'controller' => PartialFulfillmentController::class,
            'requires' => [Integration::Shopify],
        ],
        'on_hold_stall' => [
            'slug' => 'on-hold-stall',
            'label' => 'On-Hold Stall',
            'section' => 'Fulfillment',
            'description' => 'Fulfillment orders sitting on hold - sorted by how long the order has been waiting',
            'controller' => OnHoldStallController::class,
            'requires' => [Integration::Shopify],
        ],
        'no_tracking' => [
            'slug' => 'no-tracking',
            'label' => 'Fulfilled Without Tracking',
            'section' => 'Fulfillment',
            'description' => 'Fulfilled orders with no tracking number after a configurable grace period',
            'controller' => NoTrackingController::class,
            'requires' => [Integration::Shopify],
        ],
        'shipment_aging' => [
            'slug' => 'shipment-aging',
            'label' => 'Shipment Aging',
            'section' => 'Fulfillment',
            'description' => 'ShipStation awaiting-shipment orders older than a configurable threshold',
            'controller' => ShipmentAgingController::class,
            'requires' => [Integration::ShipStation],
        ],
        'item_mismatch' => [
            'slug' => 'item-mismatch',
            'label' => 'Shipped Item Mismatch',
            'section' => 'Fulfillment',
            'description' => "ShipStation shipped items that don't match what was ordered in Shopify - catches picking errors, especially missing accessories on bundled products",
            'controller' => ItemMismatchController::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'fulfilled_items' => [
            'slug' => 'fulfilled-items',
            'label' => 'Fulfilled Items Report',
            'section' => 'Fulfillment',
            'description' => 'Itemized quantity totals for orders fulfilled in a date range',
            'controller' => FulfilledItemsController::class,
            'requires' => [Integration::Shopify],
        ],

        'carrier_performance' => [
            'slug' => 'carrier-performance',
            'label' => 'Carrier Performance',
            'section' => 'Carrier Analytics',
            'description' => 'Avg delivery time, late rate, and order count grouped by carrier for a date range',
            'controller' => CarrierPerformanceController::class,
            'requires' => [Integration::ShipStation],
        ],
        'shipping_margin' => [
            'slug' => 'shipping-margin',
            'label' => 'Shipping Margin Erosion',
            'section' => 'Carrier Analytics',
            'description' => 'Orders where the ShipStation label cost exceeds what the customer was charged for shipping — flags orders shipped at a loss',
            'controller' => ShippingMarginController::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],

        'product_completeness' => [
            'slug' => 'product-completeness',
            'label' => 'Product Completeness',
            'section' => 'Products & Inventory',
            'description' => 'Active products missing images, descriptions, or variant SKUs',
            'controller' => ProductCompletenessController::class,
            'requires' => [Integration::Shopify],
        ],
        'sku_duplicates' => [
            'slug' => 'sku-duplicates',
            'label' => 'SKU Duplicates',
            'section' => 'Products & Inventory',
            'description' => 'Variants sharing the same SKU across your product catalog',
            'controller' => SkuDuplicatesController::class,
            'requires' => [Integration::Shopify],
        ],
        'inventory_oversell' => [
            'slug' => 'inventory-oversell',
            'label' => 'Inventory Oversell Risk',
            'section' => 'Products & Inventory',
            'description' => 'SKUs where ShipStation awaiting qty exceeds available Shopify stock',
            'controller' => InventoryOversellController::class,
            'requires' => [Integration::Shopify, Integration::ShipStation],
        ],
        'inventory_aging' => [
            'slug' => 'inventory-aging',
            'label' => 'Inventory Aging',
            'section' => 'Products & Inventory',
            'description' => 'Zero-stock active variants that still sold recently',
            'controller' => InventoryAgingController::class,
            'requires' => [Integration::Shopify],
        ],
        'inventory_forecast' => [
            'slug' => 'inventory-forecast',
            'label' => 'Inventory Forecast',
            'section' => 'Products & Inventory',
            'description' => 'Days until zero stock based on 30-day sell-through rate per SKU',
            'controller' => InventoryForecastController::class,
            'requires' => [Integration::Shopify],
        ],
        'zombie_products' => [
            'slug' => 'zombie-products',
            'label' => 'Zombie Products',
            'section' => 'Products & Inventory',
            'description' => 'Active products with no variants or all tracked variants permanently out of stock',
            'controller' => ZombieProductsController::class,
            'requires' => [Integration::Shopify],
        ],
        'catalog_quality' => [
            'slug' => 'catalog-quality',
            'label' => 'Catalog Quality',
            'section' => 'Products & Inventory',
            'description' => 'Active products not published to Online Store, missing SEO fields, or not in any collection',
            'controller' => CatalogQualityController::class,
            'requires' => [Integration::Shopify],
        ],

        'gift_cards' => [
            'slug' => 'gift-cards',
            'label' => 'Gift Cards',
            'section' => 'Gift Cards',
            'description' => 'Unused or soon-to-expire gift card balances',
            'controller' => GiftCardsController::class,
            'requires' => [Integration::Shopify],
        ],

        'country_mismatch' => [
            'slug' => 'country-mismatch',
            'label' => 'Billing ≠ Shipping Country',
            'catalog_label' => 'Billing / Shipping Country Mismatch',
            'section' => 'Fraud & Compliance',
            'description' => 'Paid orders where billing and shipping countries differ - a documented fraud signal',
            'controller' => CountryMismatchController::class,
            'requires' => [Integration::Shopify],
        ],
        'discount_abuse' => [
            'slug' => 'discount-abuse',
            'label' => 'Discount Abuse',
            'section' => 'Fraud & Compliance',
            'description' => 'Discount code clusters at the same shipping address across different emails',
            'controller' => DiscountAbuseController::class,
            'requires' => [Integration::Shopify],
        ],
        'tag_policy' => [
            'slug' => 'tag-policy',
            'label' => 'Tag Policy Audit',
            'section' => 'Fraud & Compliance',
            'description' => 'Required and forbidden Shopify tag combinations from local policy rules',
            'controller' => TagPolicyController::class,
            'requires' => [Integration::Shopify],
        ],
        'tax_audit' => [
            'slug' => 'tax-audit',
            'label' => 'Tax Audit',
            'section' => 'Fraud & Compliance',
            'description' => 'Paid orders above a minimum amount with $0 tax charged to a non-exempt customer',
            'controller' => TaxAuditController::class,
            'requires' => [Integration::Shopify],
        ],
        'consent_audit' => [
            'slug' => 'consent-audit',
            'label' => 'Marketing Consent Audit',
            'section' => 'Fraud & Compliance',
            'description' => 'Orders from customers without active email marketing consent - a compliance risk if targeted',
            'controller' => ConsentAuditController::class,
            'requires' => [Integration::Shopify],
        ],
        'fraud_risk' => [
            'slug' => 'fraud-risk',
            'label' => 'Fraud Risk Report',
            'section' => 'Fraud & Compliance',
            'description' => 'Paid orders scored by combined fraud signals - disposable email, country mismatch, HIGH risk level, and more',
            'controller' => FraudRiskController::class,
            'requires' => [Integration::Shopify],
        ],
        'same_ip' => [
            'slug' => 'same-ip',
            'label' => 'Same IP, Different Emails',
            'section' => 'Fraud & Compliance',
            'description' => 'Client IP addresses used by two or more distinct customer emails - a fraud ring signal',
            'controller' => SameIpController::class,
            'requires' => [Integration::Shopify],
        ],
        'disputes' => [
            'slug' => 'disputes',
            'label' => 'Chargebacks / Disputes',
            'section' => 'Fraud & Compliance',
            'description' => 'Open Shopify Payments disputes needing evidence, sorted by response deadline',
            'controller' => DisputeController::class,
            'requires' => [Integration::Shopify],
        ],

        /* Linked from the search navigation (config/search-hub.php), not the audit navigation. */
        'customer_ltv' => [
            'slug' => 'customer-ltv',
            'label' => 'Customer LTV',
            'section' => null,
            'description' => 'Top customers by lifetime value and monthly cohort retention for the selected period.',
            'controller' => CustomerLtvController::class,
            'requires' => [Integration::Shopify],
        ],
        'tag_audit' => [
            'slug' => 'tag-audit',
            'label' => 'Tag Audit',
            'section' => null,
            'description' => 'Build a complete tag inventory across a date range with frequency and last-seen info.',
            'controller' => TagAuditController::class,
            'requires' => [Integration::Shopify],
        ],
    ],
];
