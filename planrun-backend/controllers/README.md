# Контроллеры API

Контроллеры в `planrun-backend/controllers/` принимают запрос из `api_v2.php`, валидируют доступ и делегируют бизнес-логику сервисам. Здесь не должна жить тяжёлая доменная логика или SQL.

## Текущий состав

- `BaseController.php` - общий JSON-response, доступ, параметры запроса, CSRF и обработка ошибок.
- `AuthController.php` - login/logout/refresh/check_auth/password reset.
- `UserController.php` - профиль, privacy, avatar, Telegram, web push subscription.
- `TrainingPlanController.php` - load/status/regenerate/recalculate/next plan/clear plan.
- `WorkoutController.php` - day view, result CRUD, delete/import/timeline/data version.
- `WeekController.php` - недели и дни плана.
- `ExerciseController.php` - упражнения дня и библиотека упражнений.
- `StatsController.php` - stats, workouts summary, race prediction, weekly analysis.
- `ChatController.php` - AI-chat, streaming, admin chat, direct dialogs.
- `IntegrationsController.php` - OAuth URL, sync, integration status/unlink.
- `PushController.php` - регистрация push-токенов устройств.
- `CoachController.php` - каталог тренеров, заявки, pricing, groups.
- `NoteController.php` - notes и plan notifications.
- `AdminController.php` - admin users, site settings, notification templates.
- `AdaptationController.php` - weekly adaptation.

## Правила для контроллера

1. Считать параметры и право доступа.
2. Вызвать сервис.
3. Вернуть нормализованный JSON.
4. Не дублировать логику из сервисов и репозиториев.

## Где смотреть карту action'ов

- обзор слоя: `/var/www/planrun/docs/02-BACKEND.md`
- глубокий manual reference controller/service/repository слоя: `/var/www/planrun/docs/12-BACKEND-APPLICATION-REFERENCE.md`
- карта action -> controller method: `/var/www/planrun/docs/03-API.md`
- список файлов слоя: `/var/www/planrun/docs/04-FILES-REFERENCE.md`
