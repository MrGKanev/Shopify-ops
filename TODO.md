# TODO

## Improvements

- [ ] Enable `Model::shouldBeStrict()` / `preventLazyLoading()` outside production and fix the N+1s it exposes
- [ ] Find and fix the PHPUnit notice in the suite (1 notice)
- [ ] Split `ShopifyClient` (768 lines) if it keeps changing: queries, pagination, transforms
- [ ] Audit database indexes on the most-queried columns
- [ ] Review caching of external API calls (Shopify / ShipStation)
- [ ] Log search: date-range filter is not implemented (`RunLogController` filters tool/status/error)
