# PlanRun - документация проекта

Документация разделена на обзорные разделы и прикладные справочники по структуре кода.

## Навигация

| Раздел | Тип | Что внутри |
|--------|-----|------------|
| [01-FRONTEND.md](01-FRONTEND.md) | обзор | Архитектура React/Vite/Capacitor-клиента, экраны, stores, сервисы |
| [02-BACKEND.md](02-BACKEND.md) | обзор | Архитектура PHP-бэкенда, контроллеры, сервисы, AI-пайплайн, уведомления |
| [03-API.md](03-API.md) | справочник | Карта `api/*.php` и action-routing из `planrun-backend/api_v2.php` |
| [04-FILES-REFERENCE.md](04-FILES-REFERENCE.md) | справочник | Полный список исходных файлов с категорией, количеством строк и назначением |
| [05-CALL-GRAPH.md](05-CALL-GRAPH.md) | обзор | Ключевые цепочки вызовов: auth, план, чат, синхронизация, уведомления |
| [06-ON-CREATE-UPDATE-DOCS.md](06-ON-CREATE-UPDATE-DOCS.md) | процесс | Что обновлять вручную после изменений в коде |
| [07-AUTH-SECURITY.md](07-AUTH-SECURITY.md) | обзор | JWT, PIN, биометрия, ограничения и защитные меры |
| [08-AI-SERVING-STACK.md](08-AI-SERVING-STACK.md) | обзор | LM Studio, llama/PlanRun AI, локальный inference stack |
| [09-AI-MODULE-REFERENCE.md](09-AI-MODULE-REFERENCE.md) | справочник | Ручной разбор `planrun_ai/*`, skeleton path, validators и weekly adaptation |
| [10-FRONTEND-MODULE-REFERENCE.md](10-FRONTEND-MODULE-REFERENCE.md) | справочник | Подробный ручной разбор `src/services`, `src/hooks`, `src/utils` и экранных helper-модулей |
| [11-BACKEND-OPS-REFERENCE.md](11-BACKEND-OPS-REFERENCE.md) | справочник | Root PHP helper-файлы, config/bootstrap, providers, cron/worker scripts, миграции и ops-инфраструктура |
| [12-BACKEND-APPLICATION-REFERENCE.md](12-BACKEND-APPLICATION-REFERENCE.md) | справочник | Глубокий ручной разбор контроллеров, сервисов, репозиториев, side effects и application-инвариантов backend |
| [13-FRONTEND-COMPONENT-REFERENCE.md](13-FRONTEND-COMPONENT-REFERENCE.md) | справочник | Глубокий ручной разбор экранов, app shell, модалок, крупных UI-подсистем и фронтенд-инвариантов |

## Как пользоваться

1. Нужен быстрый обзор слоя: начинайте с `01-FRONTEND.md`, `02-BACKEND.md`, `05-CALL-GRAPH.md`.
2. Нужен точный файл или action: открывайте `03-API.md` и `04-FILES-REFERENCE.md`.
3. Нужны детали по функциям: смотрите `01-FRONTEND.md`, `02-BACKEND.md`, `09-AI-MODULE-REFERENCE.md`, `10-FRONTEND-MODULE-REFERENCE.md`, `11-BACKEND-OPS-REFERENCE.md`, `12-BACKEND-APPLICATION-REFERENCE.md`, `13-FRONTEND-COMPONENT-REFERENCE.md` и исходники соответствующего модуля.

## Структура проекта

```text
planrun/
├── src/                  # React/Vite фронтенд
│   ├── api/              # frontend-обёртки над action-based API
│   ├── components/       # UI-компоненты и составные виджеты
│   ├── hooks/            # кастомные React hooks
│   ├── screens/          # экраны и их служебные модули
│   ├── services/         # платформенные сервисы: token storage, PIN, biometrics, push
│   ├── stores/           # Zustand stores
│   ├── utils/            # утилиты
│   └── workers/          # web workers
├── api/                  # PHP entrypoints / wrappers
├── planrun-backend/      # контроллеры, сервисы, AI, провайдеры, репозитории, скрипты
├── docs/                 # обзорная и прикладная документация
└── scripts/              # служебные генераторы и утилиты проекта
```

## Правило обновления

- Архитектурные изменения отражайте в обзорных markdown-файлах.
- Если появился новый action, store, сервис или экран, обновите и обзорный документ, и соответствующий справочник.
