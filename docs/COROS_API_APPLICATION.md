# COROS API Application - PlanRun

## Статус заявки

- **Дата подачи:** _заполнить после отправки_
- **Статус:** _pending / approved / rejected_
- **API Reference Guide получен:** _нет_

---

## Данные заявки

### Основная информация

| Поле | Значение |
|------|----------|
| Company Name | PlanRun |
| Company URL | https://planrun.ru |
| Application Name | PlanRun |
| Application Description | AI running coach: personalized training plans, workout tracking, and progress analytics |
| Number of Total Active Users | 0-150 |
| Primary Region of Users | Russia / Eastern Europe |
| Personal or Public Use | Public Use |
| Commercial or Non-Commercial | Commercial |

### API Functions Needed

- [x] Activity/Workout Data Sync
- [x] Structured Workouts and Training Plans Sync
- [ ] GPX Route Import/Export
- [ ] Bluetooth Connectivity
- [ ] ANT+ Connectivity

### Технические эндпоинты

| Поле | URL |
|------|-----|
| Authorized Callback Domain | `https://planrun.ru` |
| Workout Data Receiving Endpoint | `https://planrun.ru/api/coros_workout_push.php` |
| Service Status Check URL | `https://planrun.ru/api/health.php` |
| Bluetooth/ANT+ Protocol Link | N/A |

### Intended Data Use Description

> PlanRun is an AI-powered running coach platform that generates personalized training plans based on user goals, fitness level, and race targets. We use COROS workout data (activity type, distance, pace, heart rate, GPS routes) to: (1) automatically track plan compliance and adjust upcoming training, (2) provide AI-driven weekly performance reviews, (3) calculate training load and recovery metrics, and (4) offer real-time coaching feedback through our AI chat assistant. All data is stored securely and used exclusively to improve the athlete's training experience. We also plan to sync structured workouts and training plans back to COROS devices.

### Дополнительно

| Поле | Значение |
|------|----------|
| Expected Launch Date | 2026-05-15 |
| Quote for Press Release | PlanRun's integration with COROS brings AI-powered coaching directly to COROS athletes, helping them train smarter with personalized plans and real-time feedback. |
| About Statement | PlanRun is an AI running coach that creates personalized training plans using local LLM technology. The platform supports automatic workout sync from major fitness platforms, provides weekly AI performance reviews, race predictions, and an interactive coaching chat assistant. |

---

## Техническая реализация (уже готово)

### Файлы

| Файл | Назначение |
|------|-----------|
| `api/health.php` | Health check endpoint (GET -> JSON status + DB check) |
| `api/coros_workout_push.php` | Webhook: прием push-уведомлений от COROS + service status (GET) |
| `api/oauth_callback.php` | OAuth callback (общий для всех провайдеров) |
| `planrun-backend/providers/CorosProvider.php` | OAuth 2.0, токены, импорт активностей |
| `planrun-backend/controllers/IntegrationsController.php` | API-контроллер интеграций |

### OAuth Flow

```
1. Пользователь нажимает "Подключить COROS" в настройках
2. GET /api?action=integration_oauth_url&provider=coros -> redirect URL
3. Пользователь авторизуется на COROS
4. Callback -> /api/oauth_callback.php?provider=coros&code=...&state=...
5. exchangeCodeForTokens() -> сохранение в integration_tokens
6. external_athlete_id извлекается из token response или JWT payload
```

### Webhook Flow (coros_workout_push.php)

```
1. COROS отправляет POST на /api/coros_workout_push.php
2. Опциональная проверка секрета (X-PlanRun-Coros-Secret)
3. Извлечение external user ID из payload
4. Поиск user_id по external_athlete_id в integration_tokens
5. Мгновенный 200 ответ (fastcgi_finish_request)
6. Фоновый импорт: fetchWorkouts() за последние 14 дней
7. Silent push на мобильное устройство пользователя
```

### Health Check (health.php)

```
GET /api/health.php

Response:
{
    "ok": true,
    "service": "planrun",
    "version": "1.9",
    "php": "8.x.x",
    "time": "2026-03-30T12:00:00+00:00",
    "db": "ok"
}
```

---

## Конфигурация (.env)

После одобрения заявки COROS пришлет API Reference Guide с конкретными URL.
Заполнить в `.env`:

```env
# COROS API credentials (из COROS Developer Portal)
COROS_CLIENT_ID=<from_coros>
COROS_CLIENT_SECRET=<from_coros>
COROS_REDIRECT_URI=https://planrun.ru/api/oauth_callback.php?provider=coros

# OAuth endpoints (из API Reference Guide)
COROS_OAUTH_AUTH_URL=<from_coros_docs>
COROS_OAUTH_TOKEN_URL=<from_coros_docs>
COROS_OAUTH_SCOPES=<from_coros_docs>
COROS_OAUTH_USE_PKCE=0

# API endpoints (из API Reference Guide)
COROS_API_BASE=<from_coros_docs>
COROS_ACTIVITY_FETCH_PATH=<from_coros_docs>
COROS_ACTIVITY_FETCH_METHOD=GET

# Webhook security (опционально)
COROS_PUSH_SECRET=<random_secret_32_chars>
```

---

## Скриншоты для отправки

Отправить на email COROS (partnerships@coros.com или указанный в форме) перед отметкой чекбокса:

1. **Dashboard** - виджеты статистики, тренировочная нагрузка
2. **Calendar** - недельный вид с тренировками
3. **Chat** - AI-тренер в действии
4. **Stats** - графики, хитмапа активности
5. **Settings > Integrations** - секция подключения трекеров

---

## Чеклист перед отправкой

- [ ] Health check работает: `curl https://planrun.ru/api/health.php`
- [ ] Webhook status работает: `curl https://planrun.ru/api/coros_workout_push.php`
- [ ] Скриншоты отправлены на email COROS
- [ ] Форма заполнена и отправлена
- [ ] Чекбокс "I've sent these" отмечен

---

## После одобрения

1. Получить API Reference Guide от COROS
2. Заполнить `COROS_*` переменные в `.env`
3. Проверить OAuth flow в staging
4. Скорректировать `mapCorosActivityToWorkout()` под реальный формат API
5. Настроить `COROS_PUSH_SECRET` если COROS поддерживает подпись
6. Протестировать webhook прием данных
7. Добавить cron для health check токенов (по аналогии со Strava)
