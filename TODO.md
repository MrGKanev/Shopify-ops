# TODO

## Improvements

- [ ] Split `ShopifyClient` (768 lines) if it keeps changing: queries, pagination, transforms
- [ ] Audit database indexes on the most-queried columns
- [ ] Review caching of external API calls (Shopify / ShipStation)
- [ ] Log search: date-range filter is not implemented (`RunLogController` filters tool/status/error)
