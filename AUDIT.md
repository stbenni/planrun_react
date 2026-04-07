# Аудит проекта PlanRun

**Дата:** 2026-04-04
**Версия:** полный аудит backend + frontend + API

---

## 1. Удалённый legacy-код

### Файлы (14 шт.)

| Файл | Причина удаления |
|------|-----------------|
| `scripts/check_chat_debug.php` | Debug-скрипт, не используется в production |
| `scripts/check_login.php` | Debug-скрипт |
| `scripts/check_password_reset.php` | Debug-скрипт |
| `scripts/check_push.php` | Debug-скрипт |
| `scripts/check_strava.php` | Debug-скрипт |
| `scripts/seed_coaches.php` | Seed-скрипт, данные уже в БД |
| `scripts/seed_coaches_avatars.php` | Seed-скрипт, данные уже в БД |
| `planrun_ai/generate_plan_async.php` | Дубль `PlanGenerationProcessorService`, заменён worker'ом |
| `tests/test_chat_tools.php` | Ad-hoc тест (не PHPUnit) |
| `tests/test_chat_fixes.php` | Ad-hoc тест |
| `tests/test_workout_analysis_prompt.php` | Ad-hoc тест |
| `tests/test_deep_ai_integration.php` | Ad-hoc тест |
| `tests/test_llm_tools_integration.php` | Ad-hoc тест |
| `tests/test_llm_write_followup.php` | Ad-hoc тест |

### Мёртвый код

| Файл | Что удалено |
|------|------------|
| `planrun_ai/plan_generator.php` | Функция `parsePlanRunAIResponse()` — нигде не вызывалась |

### Оставлено (с причиной)

| Файл | Почему не удалён |
|------|-----------------|
| `planrun_ai/plan_generator.php` | Активный fallback при `USE_SKELETON_GENERATOR=0`, используется в Unit-тестах и eval |

---

## 2. Исправленные проблемы

### 2.1 Пустые catch-блоки в ChatService (MEDIUM)

**Проблема:** `sendChatPush()` и `notifyAdminsAboutUserMessage()` молча проглатывали ошибки — невозможно диагностировать проблемы с push-уведомлениями.

**Исправление:** Добавлен `error_log()` в оба catch-блока.

**Файл:** `planrun-backend/services/ChatService.php`, строки 826, 851

### 2.2 COROS webhook без авторизации (MEDIUM)

**Проблема:** Если `COROS_PUSH_SECRET` не задан в `.env`, webhook принимал запросы от любого источника без проверки.

**Исправление:** Secret теперь обязателен. Если не настроен — возвращается 403.

**Файл:** `api/coros_workout_push.php`, строки 40-48

### 2.3 Worker daemon кеширует старый код (CRITICAL — архитектурный)

**Проблема:** `planrun-plan-generation-worker.service` — PHP daemon, который загружает весь код один раз при старте. После обновления файлов (напр. `plan_saver.php`) worker продолжал работать со старой версией кода. Рестарт PHP-FPM и сброс OpCache не помогали.

**Корневая причина:** PHP не перечитывает `require_once` в daemon-режиме.

**Решение:** После деплоя файлов, затрагивающих pipeline генерации плана, **обязательно** перезапускать worker:
```bash
sudo systemctl restart planrun-plan-generation-worker.service
```

**Рекомендация:** Добавить restart worker'а в deploy-скрипт.

---

## 3. Результаты полного аудита

### 3.1 Backend (PHP)

| Категория | Результат |
|-----------|----------|
| TODO/FIXME/HACK | 0 найдено |
| Битые require_once | 0 найдено |
| SQL injection | 0 рисков — везде prepared statements |
| Мёртвый код | 0 (после очистки) |
| Неиспользуемые imports | 0 |
| Stub-реализации | 0 |
| Пустые catch-блоки | 12 → 10 (2 исправлены, оставшиеся 10 — non-critical: ChatToolRegistry, WorkoutController AI narrative, weekly_ai_review) |
| Кэш-инвалидация | Покрыта на уровне сервисов (UserProfileService, TrainingPlanService, plan_saver, WorkoutService) |

### 3.2 Feature Flags

| Переменная | Текущее значение | Назначение |
|------------|-----------------|------------|
| `USE_PLANRUN_AI` | `1` | Вкл/выкл AI генерацию планов |
| `USE_SKELETON_GENERATOR` | `1` | Rule-based skeleton (1) vs full LLM (0) |
| `CHAT_USE_PLANRUN_AI` | `1` | AI в чате |
| `COROS_OAUTH_USE_PKCE` | — | PKCE в Coros OAuth |
| `COROS_PUSH_SECRET` | настроен | Теперь обязателен для webhook |

### 3.3 Frontend (React)

| Категория | Результат |
|-----------|----------|
| TODO/FIXME/HACK | 0 |
| Неиспользуемые imports | 0 значимых |
| Закомментированный код | 1 (`RegisterScreen.jsx:1406` — debug-строка) |
| Пустые .catch() | ~25 мест — все с `_` паттерном, намеренные для non-critical операций |
| Хардкод URL | `ApiClient.js:171` — fallback `https://planrun.ru/api` (для Capacitor native) |
| Кэш-инвалидация | В целом покрыта, stores используют `triggerRefresh()` и ручное обновление |

### 3.4 API endpoints

| Категория | Результат |
|-----------|----------|
| Авторизация | Все endpoints защищены (JWT / session / webhook HMAC) |
| Input validation | Покрыта — контроллеры валидируют через `getJsonBody()` / `getParam()` |
| SQL injection | 0 — prepared statements |
| Формат ответов | Контроллеры: `{success, data}`. Старые wrappers: `{success, message}` |
| Неиспользуемые endpoints | 0 найдено |

---

## 4. Известные технические долги (не критичные)

### LOW — можно чинить по мере необходимости

1. **Русские строки в фронтенде** — нет i18n, все строки захардкожены. Ок пока проект моноязычный.
2. **Старые API wrappers** (`login_api.php`, `logout_api.php`, `register_api.php`) — формат ответа отличается от контроллеров (`message` vs `data`). Фронтенд это корректно обрабатывает.
3. **10 пустых catch-блоков** в бэкенде — все для non-critical операций (AI narrative, VDOT history, tool extras). Намеренно не блокируют основной flow.
4. **Magic numbers** — таймауты и лимиты определены как константы в коде, но не вынесены в конфиг.
5. **`RegisterScreen.jsx:1406`** — закомментированная debug-строка `// handleChange('easy_pace_sec', '');`.

---

## 5. Архитектурные рекомендации

### Deploy-процесс
- **Обязательно:** после деплоя PHP-файлов, затрагивающих генерацию плана, перезапускать `planrun-plan-generation-worker.service`
- **Рекомендуется:** перезапускать `php8.3-fpm` для сброса OpCache

### Мониторинг
- Добавленные `error_log()` в ChatService позволят отслеживать проблемы push-уведомлений в `/var/log/php*` и `/var/log/nginx/error.log`

### Безопасность
- COROS webhook теперь требует `COROS_PUSH_SECRET` — убедиться, что он настроен в `.env`
- Strava webhook валидирует owner через БД (implicit auth) — достаточно для текущей архитектуры
- Polar webhook использует HMAC-SHA256 — надёжно

---

## 6. Общая оценка

Проект в **хорошем состоянии**:
- 0 SQL injection рисков
- 0 битых зависимостей
- 0 мёртвого кода (после очистки)
- Кэш-инвалидация покрыта на уровне сервисов
- Авторизация последовательна во всех endpoints
- Feature flags документированы и управляемы

Основной архитектурный риск — **daemon worker**, который требует ручного рестарта после деплоя.
