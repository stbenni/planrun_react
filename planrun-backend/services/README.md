# Сервисы (service layer)

Сервисы содержат бизнес-логику PlanRun. Контроллеры должны оставаться тонкими, а репозитории не должны решать доменные сценарии вместо сервисов.

## Основные группы сервисов

### Базовые домены

- `AuthService`, `JwtService`
- `TrainingPlanService`, `WorkoutService`, `StatsService`
- `WeekService`, `ExerciseService`
- `RegisterApiService`, `RegistrationService`, `EmailVerificationService`, `EmailService`
- `AvatarService`, `TelegramLoginService`

### AI и генерация плана

- `ChatService`, `ChatContextBuilder`, `DateResolver`, `TrainingStateBuilder`
- `PlanGenerationQueueService`, `PlanGenerationProcessorService`
- `WorkoutPlanRecalculationService`, `PlanSkeletonBuilder`

### Уведомления

- `PlanNotificationService`
- `NotificationSettingsService`
- `NotificationDispatcher`
- `PushNotificationService`
- `WebPushNotificationService`
- `EmailNotificationService`
- `NotificationTemplateService`

## Принципы слоя

1. Сервис владеет сценарием целиком: валидация, orchestration, транзакции, вызов репозиториев и провайдеров.
2. Сервис может использовать несколько репозиториев и внешних провайдеров.
3. Контроллер не должен знать внутреннюю механику очереди, AI или доставки уведомлений.
4. SQL и низкоуровневые запросы по возможности держим в репозиториях.

## Быстрая навигация

- архитектурный обзор: `/var/www/planrun/docs/02-BACKEND.md`
- глубокий manual reference controller/service/repository слоя: `/var/www/planrun/docs/12-BACKEND-APPLICATION-REFERENCE.md`
- ручной AI reference: `/var/www/planrun/docs/09-AI-MODULE-REFERENCE.md`
- root helpers / providers / ops scripts: `/var/www/planrun/docs/11-BACKEND-OPS-REFERENCE.md`
- ключевые цепочки вызовов: `/var/www/planrun/docs/05-CALL-GRAPH.md`
- список файлов слоя: `/var/www/planrun/docs/04-FILES-REFERENCE.md`
