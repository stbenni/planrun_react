# Индекс проекта PlanRun для ИИ

**Назначение:** единая точка входа для ИИ: все функции, API, зависимости и ссылки на детальную документацию. Используй этот файл для навигации по проекту и понимания связей между модулями.

**Обновлено:** февраль 2026

---

## 1. Структура проекта (кратко)

| Путь | Назначение |
|------|------------|
| `vladimirov/src/` | Frontend: React, экраны, компоненты, API-клиент |
| `vladimirov/planrun-backend/` | Backend: PHP API v2, контроллеры, сервисы, репозитории |
| `vladimirov/api/` | Обёртки API (login, register, CORS), chat_sse.php |
| `vladimirov/docs/` | Документация проекта |

**Технологии:** Frontend — React 18, Vite, Zustand; Backend — PHP 8+, MySQL; авторизация — JWT + опционально сессии; чат с ИИ — Ollama (LLM); генерация планов — PlanRun AI (planrun_ai/).

### Текущее состояние кода (по факту)

- **Backend:** 10 контроллеров, 10 сервисов (+ ChatContextBuilder), 7 репозиториев, 4 валидатора, 5 исключений. API: `planrun-backend/api_v2.php`, вызов через `api_wrapper.php?action=...`.
- **Frontend:** 12 экранов (Dashboard, Calendar, Chat, Stats, Settings, Admin, Login, Register, Landing, UserProfile, ForgotPassword, ResetPassword), 35+ компонентов (Calendar: AddTrainingModal, DayModal, WeekCalendar, MonthlyCalendar, WorkoutCard, ResultModal; Dashboard; Stats; common), 3 stores (auth, plan, workout), ApiClient.js — единая точка вызова API.
- **Детали и актуальные цифры:** см. [CURRENT_STATE_2026.md](./CURRENT_STATE_2026.md).

---

## 2. API (api_v2.php) — полная таблица

Все запросы: `GET/POST .../api_wrapper.php?action=<action>` (или напрямую api_v2.php). Тело — JSON или form. Авторизация: Bearer JWT или сессия (cookies).

### 2.1 Публичные (без авторизации)

| action | Метод | Контроллер | Описание |
|--------|-------|------------|----------|
| `get_site_settings` | GET | — | Настройки сайта: site_name, registration_enabled, maintenance_mode и т.д. |

### 2.2 Аутентификация (AuthController)

| action | Метод | Описание |
|--------|-------|----------|
| `login` | POST | Вход (логин, пароль; опционально JWT). |
| `logout` | POST | Выход (сессия или инвалидация refresh_token). |
| `refresh_token` | POST | Обновление access token по refresh_token. |
| `check_auth` | GET | Проверка авторизации; возвращает user. |
| `get_csrf_token` | GET | Получить CSRF-токен (для операций изменения). |
| `request_password_reset` | GET/POST | Запрос сброса пароля (email). |
| `confirm_password_reset` | POST | Подтверждение сброса (token, new password). |

### 2.3 План тренировок (TrainingPlanController)

| action | Метод | Описание |
|--------|-------|----------|
| `load` | GET | Загрузить план (недели, дни). Параметр: user_id (опционально). |
| `check_plan_status` | GET | Статус плана (есть ли план, ошибка генерации). |
| `regenerate_plan` | POST | Запуск регенерации плана (устаревший?). |
| `regenerate_plan_with_progress` | POST | Регенерация плана с учётом прогресса. |
| `clear_plan_generation_message` | POST | Очистить сообщение о генерации. |

**Зависимости:** план хранится в `training_plan_weeks` + `training_plan_days`; генерация — через `planrun_ai/` (plan_generator, plan_saver).

### 2.4 День и тренировки (WorkoutController)

| action | Метод | Описание |
|--------|-------|----------|
| `get_day` | GET | Данные дня по дате: план на день, упражнения дня, результаты (workout_log). Параметр: date (Y-m-d). |
| `get_workout_timeline` | GET | Timeline тренировки (пульс, темп). Параметр: workout_id. |
| `save_result` | POST | Сохранить результат тренировки на дату (дистанция, время, темп, заметки, файл). |
| `get_result` | GET | Результат по дате. |
| `get_all_results` | GET | Все результаты пользователя. |
| `delete_workout` | POST | Удалить запись тренировки (workout_log). |
| `save` | POST | Сохранить план (legacy? полный plan JSON). |
| `reset` | POST | Сброс по дате. |

**Зависимости:** `get_day` возвращает `plan_days` (из training_plan_days), упражнения дня (training_day_exercises), результаты из workout_log.

### 2.5 Недели и дни плана (WeekController)

| action | Метод | Описание |
|--------|-------|----------|
| `add_week` | POST | Добавить неделю (week_number, start_date, total_volume). |
| `delete_week` | POST | Удалить неделю. Параметр: week (номер). |
| `add_training_day` | POST | Добавить день в план (week_id, day_of_week, type, description, date, is_key_workout). |
| `add_training_day_by_date` | POST | **Рекомендуемый способ:** добавить тренировку на дату (date, type, description?, is_key_workout?). Неделя создаётся автоматически. |
| `update_training_day` | POST | Обновить день плана по day_id (type, description?, is_key_workout?). Требует csrf_token. |
| `delete_training_day` | POST | Удалить день плана по day_id. Требует csrf_token. |

**Типы тренировок (type):** rest, easy, long, tempo, interval, fartlek, marathon, control, race, other, free, sbu.  
**Детали формата описания (ОФП/СБУ, бег):** см. [docs/ai-add-workouts-instruction.md](./ai-add-workouts-instruction.md).

### 2.6 Упражнения дня и библиотека (ExerciseController)

| action | Метод | Описание |
|--------|-------|----------|
| `add_day_exercise` | POST | Добавить упражнение к дню плана (plan_day_id, название, параметры). |
| `update_day_exercise` | POST | Обновить упражнение дня. |
| `delete_day_exercise` | POST | Удалить упражнение дня. |
| `reorder_day_exercises` | POST | Изменить порядок упражнений дня. |
| `list_exercise_library` | GET | Список упражнений библиотеки (ОФП/СБУ) для выбора в форме. |

**Зависимости:** упражнения дня привязаны к training_plan_days (plan_day_id). Библиотека — отдельная таблица/источник (категории ofp, sbu и т.д.).

### 2.7 Статистика (StatsController)

| action | Метод | Описание |
|--------|-------|----------|
| `stats` | GET | Сводная статистика пользователя. |
| `get_all_workouts_summary` | GET | Сводка по всем тренировкам. |
| `prepare_weekly_analysis` | GET | Подготовка еженедельного анализа. |

### 2.8 Адаптация (AdaptationController)

| action | Метод | Описание |
|--------|-------|----------|
| `run_weekly_adaptation` | GET | Запуск недельной адаптации плана. |

### 2.9 Профиль и пользователь (UserController)

| action | Метод | Описание |
|--------|-------|----------|
| `get_profile` | GET | Профиль текущего пользователя (все поля профиля, цели, настройки тренировок). |
| `update_profile` | POST | Обновить профиль (JSON body). |
| `delete_user` | POST | Удалить пользователя (требует прав/подтверждения). |
| `upload_avatar` | POST | Загрузка аватара. |
| `remove_avatar` | POST | Удалить аватар. |
| `get_avatar` | GET | Получить URL/данные аватара. |
| `update_privacy` | POST | Настройки приватности. |
| `notifications_dismissed` | GET | Список закрытых уведомлений. |
| `notifications_dismiss` | POST | Закрыть уведомление (notification_id). |
| `unlink_telegram` | POST | Отвязать Telegram. |

**Структура профиля и поля:** см. [docs/PROFILE_DOCUMENTATION.md](./PROFILE_DOCUMENTATION.md).

### 2.10 Чат (ChatController)

| action | Метод | Описание |
|--------|-------|----------|
| `chat_get_messages` | GET | Сообщения чата. Параметры: type (ai | admin), limit, offset. |
| `chat_send_message` | POST | Отправить сообщение ИИ (content). |
| `chat_send_message_stream` | POST | Отправить сообщение ИИ (streaming). |
| `chat_mark_read` | POST | Отметить диалог прочитанным (conversation_id). |
| `chat_mark_all_read` | POST | Отметить все прочитанными. |
| `chat_send_message_to_admin` | POST | Сообщение пользователя в поддержку. |
| `chat_admin_send_message` | POST | Админ: ответ пользователю (user_id, content). |
| `chat_admin_chat_users` | GET | Админ: список пользователей с чатами. |
| `chat_admin_get_messages` | GET | Админ: сообщения пользователя (user_id, limit, offset). |
| `chat_admin_unread_notifications` | GET | Админ: непрочитанные от пользователей. |
| `chat_admin_mark_all_read` | POST | Админ: отметить все прочитанными. |
| `chat_admin_broadcast` | POST | Админ: рассылка (content, опционально user_ids). |

**Контекст для ИИ-чата:** собирается в бэкенде (ChatContextBuilder и др.) из профиля, плана, последних тренировок. Настройка LLM: [docs/CHAT_SETUP.md](./CHAT_SETUP.md).

### 2.11 Админ (AdminController)

| action | Метод | Описание |
|--------|-------|----------|
| `admin_list_users` | GET | Список пользователей (page, per_page, search). |
| `admin_get_user` | GET | Один пользователь (user_id). |
| `admin_update_user` | POST | Обновить пользователя (роль, email и т.д.). |
| `admin_get_settings` | GET | Настройки сайта (админ). |
| `admin_update_settings` | POST | Сохранить настройки. |

---

## 3. Frontend: основные вызовы API (ApiClient.js)

Методы клиента вызывают соответствующие action из раздела 2. Примеры соответствия:

| Метод ApiClient | action | Примечание |
|-----------------|--------|------------|
| getPlan(userId) | load | План тренировок. |
| savePlan(planData) | save | Сохранение плана (JSON). |
| getDay(date) | get_day | День: план, упражнения, результаты. |
| saveResult(date, result) | save_result | Результат тренировки. |
| getResult(date) | get_result | Результат по дате. |
| getAllResults() | get_all_results | Все результаты. |
| reset(date) | reset | Сброс. |
| getStats() | stats | Статистика. |
| getAllWorkoutsSummary() | get_all_workouts_summary | Сводка тренировок. |
| getWorkoutTimeline(workoutId) | get_workout_timeline | Timeline тренировки. |
| runAdaptation() | run_weekly_adaptation | Адаптация. |
| regeneratePlan() | regenerate_plan_with_progress | Регенерация плана. |
| checkPlanStatus(userId) | check_plan_status | Статус плана. |
| deleteWeek(weekNumber) | delete_week | Удалить неделю. |
| addWeek(weekData) | add_week | Добавить неделю. |
| addTrainingDayByDate(data) | add_training_day_by_date | Добавить тренировку на дату. |
| updateTrainingDay(dayId, data) | update_training_day | Обновить день (csrf). |
| deleteTrainingDay(dayId) | delete_training_day | Удалить день (csrf). |
| chatGetMessages(type, limit, offset) | chat_get_messages | Сообщения чата. |
| chatSendMessage(content) | chat_send_message | Сообщение ИИ. |
| chatSendMessageStream(content, onChunk) | chat_send_message_stream | Стриминг ИИ. |
| getSiteSettings() | get_site_settings | Публичные настройки. |
| login / logout / refreshToken / checkAuth | AuthController actions | JWT/сессия. |

Профиль: фронт вызывает `api.request('get_profile', {}, 'GET')` и `api.request('update_profile', data, 'POST')` (напр. SettingsScreen). Упражнения дня и библиотека: `api.request('list_exercise_library', {}, 'GET')` (AddTrainingModal); добавление/редактирование упражнений дня — через соответствующие action в ExerciseController.

---

## 4. Зависимости между сущностями

- **План:** загрузка `load` → возвращает недели и дни. Кеш: `Cache::delete("training_plan_{$userId}")` при изменении плана/недель/дней.
- **Неделя:** `training_plan_weeks` (id, user_id, week_number, start_date). При `add_training_day_by_date` неделя по дате ищется или создаётся автоматически.
- **День плана:** `training_plan_days` (id, week_id, day_of_week, type, description, date, is_key_workout). Уникальность: может быть несколько записей на одну дату (несколько тренировок в день).
- **Упражнения дня:** `training_day_exercises` привязаны к plan_day_id (training_plan_days.id). CRUD: add_day_exercise, update_day_exercise, delete_day_exercise, reorder_day_exercises.
- **Результаты:** `workout_log` — факт выполнения (дата, дистанция, время, темп, заметки, привязка к плановому дню при наличии).
- **Профиль:** используется для генерации плана (planrun_ai), для контекста чата (ChatContextBuilder), для отображения в настройках.
- **Чат с ИИ:** контекст строится из профиля, плана, последних тренировок; ответы генерирует LLM (Ollama). Админ-чат — отдельные диалоги пользователь ↔ админ.

---

## 5. Документация по темам (куда смотреть)

| Тема | Документ | Содержание |
|------|----------|------------|
| **Внесение тренировок (для ИИ)** | [ai-add-workouts-instruction.md](./ai-add-workouts-instruction.md) | add_training_day_by_date, update_training_day, delete_training_day; форматы description (бег, ОФП, СБУ); типы type. |
| **Профиль пользователя** | [PROFILE_DOCUMENTATION.md](./PROFILE_DOCUMENTATION.md) | Поля профиля, цели, настройки тренировок, валидация. |
| **Календарь и добавление тренировок (UX)** | [calendar-add-workouts-plan.md](./calendar-add-workouts-plan.md) | Сценарии добавления, текущее состояние UI, API по дате. |
| **Чат с ИИ** | [CHAT_SETUP.md](./CHAT_SETUP.md) | Ollama, модели, .env, SSE, админ-сообщения. |
| **Архитектура** | [architecture/ARCHITECTURE_ANALYSIS_2026.md](./architecture/ARCHITECTURE_ANALYSIS_2026.md) | Анализ стека, рекомендации. |
| **Текущее состояние** | [CURRENT_STATE_2026.md](./CURRENT_STATE_2026.md) | Завершённые задачи, статистика, технологии. |
| **Настройка окружения** | [setup/QUICK_START.md](./setup/QUICK_START.md), [setup/SETUP.md](./setup/SETUP.md) | Установка, запуск. |
| **Решение проблем** | [troubleshooting/TROUBLESHOOTING.md](./troubleshooting/TROUBLESHOOTING.md) | Типичные ошибки и решения. |
| **Генерация планов (бэкенд)** | [planrun-backend/planrun_ai/README.md](../planrun-backend/planrun_ai/README.md) | plan_generator, plan_saver, prompt_builder, PlanRun AI API. |
| **Админка** | [ADMIN_PANEL.md](./ADMIN_PANEL.md) | Функции админ-панели. |

---

## 6. Важные файлы кода (для быстрого поиска)

| Что искать | Где |
|------------|-----|
| Маршрутизация API | `planrun-backend/api_v2.php` |
| Добавление/обновление/удаление дня по дате | `planrun-backend/services/WeekService.php`, `repositories/WeekRepository.php`, `controllers/WeekController.php` |
| Данные дня (план + результаты) | `planrun-backend/controllers/WorkoutController.php`, `services/WorkoutService.php` |
| Валидация типов тренировок и дней | `planrun-backend/validators/WeekValidator.php` |
| Форма добавления/редактирования тренировки, ОФП/СБУ | `src/components/Calendar/AddTrainingModal.jsx` |
| Календарь (неделя/месяц), DayModal | `src/components/Calendar/WeekCalendar.jsx`, `DayModal.jsx`; экран: `src/screens/CalendarScreen.jsx` |
| API-клиент | `src/api/ApiClient.js` |
| Контекст чата для ИИ | `planrun-backend/services/ChatContextBuilder.php` |
| Сохранение плана в БД (генерация) | `planrun-backend/planrun_ai/plan_saver.php` |

---

## 7. Вся документация (список файлов)

| Файл | Назначение |
|------|------------|
| **docs/AI_PROJECT_INDEX.md** | Этот файл — индекс для ИИ. |
| docs/ai-add-workouts-instruction.md | Внесение тренировок: API, типы, формат description. |
| docs/PROFILE_DOCUMENTATION.md | Поля профиля, цели, настройки тренировок. |
| docs/calendar-add-workouts-plan.md | Сценарии добавления тренировок, UX. |
| docs/CHAT_SETUP.md | Чат с ИИ: Ollama, .env, SSE, админ-чат. |
| docs/CURRENT_STATE_2026.md | Текущее состояние проекта, стек, статистика. |
| docs/README.md | Оглавление документации. |
| docs/architecture/ARCHITECTURE_ANALYSIS_2026.md | Анализ архитектуры, рекомендации. |
| docs/ADMIN_PANEL.md | Админ-панель. |
| docs/DESIGN_SYSTEM.md | Дизайн-система, стили. |
| docs/setup/QUICK_START.md, SETUP.md | Установка и настройка. |
| docs/troubleshooting/TROUBLESHOOTING.md | Решение проблем. |
| docs/migration/*.md | Миграции, примеры. |
| planrun-backend/planrun_ai/README.md | Генерация планов (PlanRun AI). |
| planrun-backend/docs/JWT_USAGE.md | Использование JWT. |

Остальные файлы в docs/ (FORM_ANALYSIS, REGISTRATION_SPEC, CSS_STANDARD и т.д.) — тематические; при необходимости смотри по названию. Архив: docs/archive/ — устаревшие документы.

---

## 8. Краткий чек-лист для ИИ

1. **Добавить тренировку на дату:** `add_training_day_by_date` с `date` (Y-m-d), `type`, при необходимости `description`, `is_key_workout`. Формат description — см. ai-add-workouts-instruction.md.
2. **Изменить тренировку:** `update_training_day` с `day_id`, `type`, при необходимости `description`; нужен `csrf_token` (get_csrf_token).
3. **Удалить тренировку из плана:** `delete_training_day` с `day_id` и `csrf_token`.
4. **Получить день (план + результаты):** `get_day` с `date`.
5. **Профиль пользователя:** `get_profile` — для контекста генерации плана или чата.
6. **Список упражнений библиотеки:** `list_exercise_library` — для подсказок по названиям ОФП/СБУ.
7. **Даты везде в формате Y-m-d.** После изменений плана кеш инвалидируется на бэкенде; фронт при следующем запросе получает актуальные данные.

8. Для деталей по любому action смотри раздел 2 этого файла и при необходимости указанный в разделе 5 тематический документ.
