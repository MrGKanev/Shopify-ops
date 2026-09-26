# TODO

- [x] **Detect a missed scheduled sync** — create an operational issue when a store's enabled daily audit has not completed one hour after its scheduled time; resolve it after a successful run. This catches a stalled scheduled audit, not a quiet store with no new orders.
- [ ] **Flag Shopify order changes after ShipStation push** — when an order changes after a successful push, create an issue so staff can verify whether its items or shipping details need updating in ShipStation.
- [ ] **Flag repeated shipping addresses before fulfillment** — detect several recent orders going to the same normalized address, especially under different names, and create a review issue. Keep it as a warning to avoid automatically blocking legitimate orders.
- [ ] **Translate the entire user interface into Bulgarian**
  - [x] Add Laravel Bulgarian JSON translations and make Bulgarian the default locale for new/local setups.
  - [x] Translate shared navigation, command palette, common component labels, page headings, report descriptions, table headers, empty states, and the dashboard.
  - [x] Add Bulgarian messages for common Laravel validation rules and attributes.
  - [x] Add translation support to diagnostic/report email bodies and Slack/Discord operational notifications.
  - [ ] Review all screens and translate remaining visible text: page copy, form labels and help text, placeholders, select options, table/result labels, and empty/loading/error states.
  - [ ] Translate dynamic output and messages: status names, counts/plurals, controller-generated flash/errors, and validation attributes and custom messages.
  - [ ] Review all accessibility text (`aria-label`, titles, alt text) and JavaScript-generated notices and search states.
  - [ ] Verify Bulgarian date/number formatting and check every screen for unintended English/Bulgarian mixing; keep product names, API fields, and technical identifiers unchanged where appropriate.

### Разлики, които не бива да се затварят механично

- **Log search:** `q` е реализиран, но старият план споменава и date range; текущият `RunLogController` филтрира tool/status/error. Ако date-range search е изискване, то остава отдå≈елна задача.
- **Приети продуктови отклонения:** по-строгите Note Flags/Duplicate Address/Spot-check и премахнатата settings history не трябва да се възстановяват автоматично като „липсващ parity“. Нужна е нова продуктова заявка, ако решението се променя.
