# PlanRun — полная документация проекта

Документация по каждому файлу и функции в проекте PlanRun.

---

## Навигация

| Раздел | Описание |
|--------|----------|
| [01-FRONTEND.md](01-FRONTEND.md) | React-фронтенд: экраны, компоненты, stores, API-клиент, сервисы, хуки, утилиты |
| [02-BACKEND.md](02-BACKEND.md) | PHP-бэкенд: контроллеры, сервисы, planrun_ai, провайдеры, репозитории |
| [03-API.md](03-API.md) | API-слой: точки входа, CORS, сессии, OAuth, webhook'и |
| [04-FILES-REFERENCE.md](04-FILES-REFERENCE.md) | Справочник по каждому файлу проекта |
| [05-CALL-GRAPH.md](05-CALL-GRAPH.md) | Граф вызовов: где функция используется и с чем взаимодействует |
| [06-ON-CREATE-UPDATE-DOCS.md](06-ON-CREATE-UPDATE-DOCS.md) | При создании нового — что обновлять в документации |
| [HUAWEI_HEALTH_INTEGRATION.md](HUAWEI_HEALTH_INTEGRATION.md) | Интеграция Huawei Health Kit |

**Правила Cursor (alwaysApply):**
- `.cursor/rules/architecture-flow.mdc` — граф вызовов, цепочки данных
- `.cursor/rules/impact-matrix.mdc` — матрица влияния: при изменении X проверить Y (100% покрытие)
- `.cursor/rules/on-create-update-docs.mdc` — при создании нового (компонент, API, store, утилита) — дописать в impact-matrix, architecture-flow, docs/

---

## Структура проекта

```
vladimirov/
├── src/                    # React-фронтенд
│   ├── api/                # ApiClient
│   ├── components/         # UI-компоненты
│   ├── hooks/              # React-хуки
│   ├── screens/            # Экраны (страницы)
│   ├── services/           # Сервисы (Chat, Biometric, Pin)
│   ├── stores/             # Zustand stores
│   ├── styles/             # Глобальные стили
│   ├── utils/              # Утилиты
│   └── workers/            # Web Workers
├── api/                    # PHP-точки входа
├── planrun-backend/        # PHP-логика
│   ├── controllers/        # Контроллеры API
│   ├── services/           # Бизнес-логика
│   ├── planrun_ai/         # Генерация планов, RAG
│   ├── providers/          # Интеграции (Strava, Huawei, Garmin)
│   └── repositories/       # Доступ к БД
└── docs/                   # Документация
```

---

## Стек технологий

- **Фронт:** React 18, React Router 6, Vite 5, Zustand
- **Бэкенд:** PHP 8+, MySQL
- **Мобильное:** Capacitor (Android)
- **AI:** LM Studio, PlanRun AI (локальная LLM)

---

## Генерация справочника

Скрипт `scripts/generate-docs.js` извлекает функции и компоненты из исходников и сохраняет в `docs/api-reference.json`. Запуск: `node scripts/generate-docs.js`.
