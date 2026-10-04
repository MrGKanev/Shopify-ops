<?php

namespace App\Integrations\Shopify\Contracts;

/** Full API contract for the implementation and transport-level integration tests. */
interface ShopifyAdminGateway extends ShopifyCatalog, ShopifyCustomers, ShopifyHealth, ShopifyOrders, ShopifyPayments, ShopifyTransport {}
