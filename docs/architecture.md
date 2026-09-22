# Project structure

```text
app/Application/       Use-case orchestration for reports, orders, and health
app/Domain/            Analysis and business rules
app/Http/              Controllers, middleware, and validated requests
app/Integrations/      Shopify and ShipStation clients
app/Jobs/              Queued work
app/Models/            Eloquent models and store-scoped records
config/                Laravel, navigation, audit, and policy configuration
database/              Migrations, factories, seeders, and local SQLite file
resources/views/       Blade pages and shared UI components
resources/css/         Tailwind application styles
resources/js/          Browser behavior, including the store switcher
routes/                 Web routes and scheduled commands
tests/                  Feature and unit tests
```

Credentials are encrypted through Eloquent casts. Controllers obtain the active store from middleware, while database records and jobs retain their own `store_id` so data does not cross store boundaries.
