# Текущее состояние проекта PlanRun (Февраль 2026)

**Дата обновления:** февраль 2026

## Структура и статистика (актуально по коду)

### Backend (planrun-backend/)

| Слой | Количество | Файлы |
|------|------------|--------|
| Контроллеры | 10 | TrainingPlan, Workout, Stats, Exercise, Week, Adaptation, User, Auth, Admin, Chat (+ BaseController) |
| Сервисы | 10 + 1 | Те же домены + ChatContextBuilder, JwtService, EmailService |
| Репозитории | 7 | TrainingPlan, Workout, Stats, Exercise, Week, Chat, Notification (+ BaseRepository) |
| Валидаторы | 4 | Week, Workout, Exercise, TrainingPlan (+ BaseValidator) |
| Исключения | 5 | AppException, ValidationException, UnauthorizedException, ForbiddenException, NotFoundException |

**Точка входа API:** `api_v2.php` — все action маршрутируются на контроллеры. Вызов с фронта идёт через `api_wrapper.php?action=...`.

### Frontend (src/)

| Категория | Количество | Примечание |
|-----------|------------|------------|
| Экраны (screens/) | 12 | Dashboard, Calendar, Chat, Stats, Settings, Admin, Login, Register, Landing, UserProfile, ForgotPassword, ResetPassword |
| Компоненты (components/) | 35+ JSX | Calendar (AddTrainingModal, DayModal, WeekCalendar, MonthlyCalendar, WorkoutCard, ResultModal, RouteMap…), Dashboard, Stats, common (Modal, BottomNav, TopHeader…) |
| Стили | 42 CSS | В т.ч. styles/variables.css, dark-mode.css, screens-auth.css |
| Stores (Zustand) | 3 | useAuthStore, usePlanStore, useWorkoutStore |
| Сервисы | 2 | BiometricService, ChatSSE |
| API | 1 | ApiClient.js (все вызовы к api_wrapper.php) |

### Технологии

- **Backend:** PHP 8+, MySQL, Composer, PHPUnit
- **Frontend:** React 18.2.0, Vite 5.4.2, React Router 6.20, Zustand 5.0.10
- **Мобильные:** Capacitor 8.x, @aparajita/capacitor-biometric-auth
- **Аутентификация:** JWT (access + refresh), опционально сессии
- **Чат с ИИ:** Ollama (LLM), контекст — ChatContextBuilder
- **Генерация планов:** planrun_ai/ (PlanRun AI API, plan_saver)

---

## Реализованный функционал

### План и календарь
- Загрузка плана (`load`), проверка статуса (`check_plan_status`), регенерация с прогрессом (`regenerate_plan_with_progress`).
- Добавление тренировки на дату (`add_training_day_by_date`), обновление (`update_training_day`), удаление (`delete_training_day`). Неделя по дате создаётся автоматически.
- Календарь: недельный (WeekCalendar) и месячный (MonthlyCalendar) вид; DayModal — план дня, результаты, кнопки «Добавить/Изменить/Удалить» тренировку.
- AddTrainingModal: категории Бег/ОФП/СБУ, конструкторы (лёгкий бег, интервалы, фартлек), библиотека ОФП/СБУ с возможностью задать дистанцию (м) для каждого упражнения.

### Тренировки и результаты
- get_day(date) — план на день, упражнения дня, записи из workout_log.
- save_result, get_result, get_all_results, delete_workout, reset.
- ResultModal — ввод результата вручную или загрузка файла (GPX/TCX).

### Профиль и пользователь
- get_profile, update_profile; аватар (upload_avatar, remove_avatar, get_avatar), update_privacy, уведомления (notifications_dismissed, notifications_dismiss), unlink_telegram.
- Регистрация, сброс пароля (request_password_reset, confirm_password_reset).

### Чат
- Чат с ИИ: chat_get_messages, chat_send_message, chat_send_message_stream (стриминг). Контекст из профиля/плана/тренировок (ChatContextBuilder).
- Чат с админом: chat_send_message_to_admin; админ — chat_admin_* (список пользователей, сообщения, рассылка, mark read).

### Админка
- admin_list_users, admin_get_user, admin_update_user, admin_get_settings, admin_update_settings, delete_user.

### Упражнения
- list_exercise_library (ОФП/СБУ для формы), add_day_exercise, update_day_exercise, delete_day_exercise, reorder_day_exercises.

### Статистика и адаптация
- stats, get_all_workouts_summary, prepare_weekly_analysis; run_weekly_adaptation.

---

## Документация и правила для ИИ

- **Индекс для ИИ:** [docs/AI_PROJECT_INDEX.md](./AI_PROJECT_INDEX.md) — полная таблица API, зависимости, ссылки на тематические доки.
- **Внесение тренировок:** [docs/ai-add-workouts-instruction.md](./ai-add-workouts-instruction.md) — add_training_day_by_date, update_training_day, delete_training_day, форматы description, типы type.
- **Профиль:** [docs/PROFILE_DOCUMENTATION.md](./PROFILE_DOCUMENTATION.md).
- **Чат:** [docs/CHAT_SETUP.md](./CHAT_SETUP.md).
- **Правила Cursor:** `.cursor/rules/` — project-context, php-backend, react-frontend, api-and-docs.

---

## Архитектура (кратко)

### Backend
```
planrun-backend/
├── api_v2.php           # Маршрутизация action → контроллер
├── controllers/         # HTTP, вызов сервисов
├── services/            # Бизнес-логика, ChatContextBuilder
├── repositories/        # Работа с БД
├── validators/          # Валидация входных данных
├── exceptions/          # Исключения для API
├── planrun_ai/          # Генерация планов, plan_saver
└── config/, scripts/, tests/
```

### Frontend
```
src/
├── api/ApiClient.js     # Все запросы к API
├── screens/             # Экраны приложения
├── components/          # Calendar, Dashboard, Stats, common
├── stores/              # Zustand: auth, plan, workout
├── services/            # BiometricService, ChatSSE
├── styles/              # variables, dark-mode, общие стили
└── hooks/, utils/, assets/
```

---

## Итог

Проект в рабочем состоянии: календарь с добавлением/редактированием/удалением тренировок (в т.ч. ОФП/СБУ с произвольной дистанцией), результаты, профиль, чат с ИИ и админ-чат, админка, статистика. Документация приведена в соответствие с кодом; для ИИ используется единый индекс в docs/AI_PROJECT_INDEX.md и правила в .cursor/rules/.
