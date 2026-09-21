# Преглед на преповтарящ се код (app/) — 2026-09-19

## Големи структурни дублирания

### 1. 24 класа `Run*Report` със специализирани result contracts

Идентичният wrapper за 21 report-а, които връщат `ScanResult`, вече е извлечен в `app/Application/Reports/RunScanReport.php`.

Остават 24 класа със специализирани result contracts (например `AddressChangeResult`, `InventoryAgingResult`, `ShippingMarginResult`). Те имат различни метрики и полета, използвани от изгледите, затова не могат безопасно да бъдат прехвърлени механично към `ScanResult`.

Следваща стъпка: да се групират по действително еднакъв contract и да се извлече обща основа само за всяка съвместима група.
