# Applied migrations archive

Архив одноразовых миграционных скриптов, которые уже выполнены в production.

Перенесено сюда в v3.16, чтобы основной каталог `planrun-backend/scripts/`
содержал только активные tools и cron-скрипты, а не накопленную историю
миграций схемы.

## Если нужно откатить / повторить

```bash
php planrun-backend/scripts/_applied_migrations/migrate_<name>.php
```

Скрипты идемпотентны — повторный запуск безопасен (используют `CREATE TABLE
IF NOT EXISTS` и `ADD COLUMN IF NOT EXISTS`).

## Master migration

`scripts/migrate_all.php` остаётся в корне `scripts/` — это **активный**
master-скрипт который восстанавливает базовые auth/notification таблицы из
`planrun-backend/migrations/*.sql`.
