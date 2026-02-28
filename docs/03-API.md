# PlanRun — документация API-слоя

Описание PHP-точек входа в папке `api/`.

---

## Общая схема

```
Клиент (React/Capacitor)
    ↓
/api/api_wrapper.php?action=...
    ↓
planrun-backend/api_v2.php (роутинг по action)
    ↓
Контроллер → Сервис → Репозиторий → БД
```

---

## Файлы api/

### `api/api_wrapper.php`
**Назначение:** Прокси для всех API-запросов. Подключает CORS, сессию, передаёт управление в `api_v2.php`.

| Действие | Описание |
|----------|----------|
| Подключение | `cors.php`, `session_init.php` |
| Сессия | `session.cookie_samesite=None`, `secure`, `httponly` для cross-origin |
| Сброс пароля | `request_password_reset`, `confirm_password_reset` — раннее `session_write_close()` |
| Autoload | Для `request_password_reset` заранее подключает Composer (SMTP) |
| Делегирование | `require api_v2.php` с `API_WRAPPER_CORS_SENT=true` |

---

### `api/cors.php`
**Назначение:** CORS-заголовки для API.

| Логика | Описание |
|--------|----------|
| Разрешённые origin | Same domain, localhost, 127.0.0.1, 192.168.*, capacitor://localhost |
| OPTIONS | Preflight: 204, Allow-Methods, Allow-Headers |
| Обычные запросы | Access-Control-Allow-Origin, Allow-Credentials |
| Константа | `API_CORS_SENT` после отправки заголовков |

---

### `api/session_init.php`
**Назначение:** Инициализация сессии PHP (если не запущена).

---

### `api/login_api.php`
**Назначение:** Отдельная точка входа для логина (legacy или альтернативная). Может проксировать к api_v2.

---

### `api/logout_api.php`
**Назначение:** Точка входа для выхода (legacy).

---

### `api/register_api.php`
**Назначение:** Регистрация: отправка кода верификации, минимальная регистрация.

---

### `api/oauth_callback.php`
**Назначение:** OAuth callback для интеграций (Huawei, Strava, Polar).

| Параметр | Описание |
|----------|----------|
| `provider` | huawei, strava, polar |
| `code` | Authorization code от провайдера |
| `state` | CSRF/integration_state |
| `error` | Ошибка от провайдера |

**Поток:**
1. Проверка авторизации (сессия)
2. Проверка state
3. Выбор провайдера
4. `$provider->exchangeCodeForTokens($code, $state)`
5. Редирект на `/settings?tab=integrations&connected=...` или `&error=...`

---

### `api/strava_webhook.php`
**Назначение:** Webhook Strava для push-уведомлений о тренировках.

| Метод | Описание |
|-------|----------|
| GET | Верификация подписки: `hub.mode=subscribe`, `hub.challenge`, `hub.verify_token` → возврат `hub.challenge` |
| POST | События: `object_type=activity`, `object_id`, `owner_id`, `aspect_type` (create/update/delete) |

**Логика POST:**
- Поиск user_id по `external_athlete_id` в `integration_tokens`
- Импорт/обновление/удаление активности через StravaProvider

---

### `api/chat_sse.php`
**Назначение:** Server-Sent Events для чата (если используется SSE вместо streaming через api_wrapper).

---

### `api/health.php`
**Назначение:** Health check endpoint (проверка доступности API).

---

### `api/complete_specialization_api.php`
**Назначение:** Завершение специализации пользователя (онбординг).

---

## Список action'ов (api_v2.php)

Полный список см. в `openapi.yaml` и `planrun-backend/api_v2.php`.

**Группы:**
- **Auth:** login, logout, refresh_token, check_auth, request_password_reset, confirm_password_reset
- **Plan:** load, check_plan_status, regenerate_plan, recalculate_plan, generate_next_plan, reactivate_plan, clear_plan_generation_message
- **Workout:** get_day, save_result, get_result, get_all_results, upload_workout, delete_workout, get_workout_timeline, save, reset
- **Week:** add_week, delete_week, add_training_day, add_training_day_by_date, update_training_day, delete_training_day
- **Exercise:** add_day_exercise, update_day_exercise, delete_day_exercise, reorder_day_exercises, list_exercise_library
- **Stats:** stats, get_all_workouts_summary, get_all_workouts_list, prepare_weekly_analysis
- **User:** get_profile, update_profile, delete_user, upload_avatar, remove_avatar, update_privacy, notifications_dismissed, notifications_dismiss, unlink_telegram
- **Integrations:** integration_oauth_url, integrations_status, sync_workouts, unlink_integration, strava_token_error
- **Chat:** chat_get_messages, chat_send_message, chat_send_message_stream, chat_clear_ai, chat_mark_read, chat_mark_all_read, chat_send_message_to_admin, chat_get_direct_dialogs, chat_get_direct_messages, chat_send_message_to_user, chat_admin_*
- **Admin:** admin_list_users, admin_get_user, admin_update_user, admin_get_settings, admin_update_settings
- **Adaptation:** run_weekly_adaptation
