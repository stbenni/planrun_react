# PlanRun - документация API-слоя

Ручная карта PHP entrypoint'ов и action-routing, сверенная по `api/*.php` и `planrun-backend/api_v2.php`.

## Поток запроса

```
Клиент (React / Capacitor)
  -> api/api_wrapper.php?action=...
  -> planrun-backend/api_v2.php
  -> Controller -> Service -> Repository / Provider
```

## Точки входа `api/`

| Файл | Назначение |
|------|------------|
| `api/api_wrapper.php` | Прокси-обёртка для API: CORS, session_init и передача запроса в `planrun-backend/api_v2.php`. |
| `api/chat_sse.php` | SSE-точка входа для потокового ответа чата. |
| `api/complete_specialization_api.php` | Legacy-обёртка завершения специализации пользователя. |
| `api/cors.php` | Отправка CORS-заголовков и обработка preflight-запросов. |
| `api/health.php` | Минимальный health-check для API. |
| `api/login_api.php` | Legacy/login entrypoint для совместимости с ранними клиентами. |
| `api/logout_api.php` | Legacy/logout entrypoint для совместимости с ранними клиентами. |
| `api/oauth_callback.php` | OAuth callback внешних интеграций: Strava, Huawei Health и Polar. |
| `api/register_api.php` | Legacy/register entrypoint для сценариев регистрации. |
| `api/session_init.php` | Инициализация PHP-сессии и параметров cookie для API. |
| `api/strava_webhook.php` | Webhook Strava: подтверждение подписки и обработка событий активности. |
| `api/telegram_login_callback.php` | Callback Telegram Login Widget и привязки Telegram-аккаунта. |

## Карта action'ов

Всего найдено action'ов: **120**.

### AdminController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `admin_approve_coach` | POST | `AdminController::approveCoachApplication` | 820 | Action маршрутизируется в `AdminController::approveCoachApplication`. |
| `admin_coach_applications` | GET | `AdminController::getCoachApplications` | 816 | Action маршрутизируется в `AdminController::getCoachApplications`. |
| `admin_get_notification_templates` | GET | `AdminController::getNotificationTemplates` | 648 | Action маршрутизируется в `AdminController::getNotificationTemplates`. |
| `admin_get_settings` | GET | `AdminController::getSettings` | 640 | Action маршрутизируется в `AdminController::getSettings`. |
| `admin_get_user` | GET | `AdminController::getUser` | 632 | Action маршрутизируется в `AdminController::getUser`. |
| `admin_list_users` | GET | `AdminController::listUsers` | 628 | Action маршрутизируется в `AdminController::listUsers`. |
| `admin_reject_coach` | POST | `AdminController::rejectCoachApplication` | 824 | Action маршрутизируется в `AdminController::rejectCoachApplication`. |
| `admin_reset_notification_template` | POST | `AdminController::resetNotificationTemplate` | 656 | Action маршрутизируется в `AdminController::resetNotificationTemplate`. |
| `admin_update_notification_template` | POST | `AdminController::updateNotificationTemplate` | 652 | Action маршрутизируется в `AdminController::updateNotificationTemplate`. |
| `admin_update_settings` | POST | `AdminController::updateSettings` | 644 | Action маршрутизируется в `AdminController::updateSettings`. |
| `admin_update_user` | POST | `AdminController::updateUser` | 636 | Action маршрутизируется в `AdminController::updateUser`. |

### AuthController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `check_auth` | GET\|POST | `AuthController::checkAuth` | 623 | Action маршрутизируется в `AuthController::checkAuth`. |
| `confirm_password_reset` | GET\|POST | `AuthController::confirmPasswordReset` | 664 | Action маршрутизируется в `AuthController::confirmPasswordReset`. |
| `login` | POST | `AuthController::login` | 611 | Action маршрутизируется в `AuthController::login`. |
| `logout` | POST | `AuthController::logout` | 615 | Action маршрутизируется в `AuthController::logout`. |
| `refresh_token` | POST | `AuthController::refreshToken` | 619 | Action маршрутизируется в `AuthController::refreshToken`. |
| `request_password_reset` | GET\|POST | `AuthController::requestPasswordReset` | 660 | Action маршрутизируется в `AuthController::requestPasswordReset`. |

### ChatController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `chat_add_ai_message` | POST | `ChatController::addAIMessage` | 741 | Action маршрутизируется в `ChatController::addAIMessage`. |
| `chat_admin_broadcast` | POST | `ChatController::broadcastAdminMessage` | 729 | Action маршрутизируется в `ChatController::broadcastAdminMessage`. |
| `chat_admin_chat_users` | GET | `ChatController::getAdminChatUsers` | 721 | Action маршрутизируется в `ChatController::getAdminChatUsers`. |
| `chat_admin_get_messages` | GET | `ChatController::getAdminMessages` | 733 | Action маршрутизируется в `ChatController::getAdminMessages`. |
| `chat_admin_mark_all_read` | POST | `ChatController::markAdminAllRead` | 689 | Action маршрутизируется в `ChatController::markAdminAllRead`. |
| `chat_admin_mark_conversation_read` | POST | `ChatController::markAdminConversationRead` | 737 | Action маршрутизируется в `ChatController::markAdminConversationRead`. |
| `chat_admin_send_message` | POST | `ChatController::sendAdminMessage` | 717 | Action маршрутизируется в `ChatController::sendAdminMessage`. |
| `chat_admin_unread_notifications` | GET | `ChatController::getAdminUnreadNotifications` | 725 | Action маршрутизируется в `ChatController::getAdminUnreadNotifications`. |
| `chat_clear_ai` | POST | `ChatController::clearAiChat` | 681 | Action маршрутизируется в `ChatController::clearAiChat`. |
| `chat_clear_direct_dialog` | POST | `ChatController::clearDirectDialog` | 713 | Action маршрутизируется в `ChatController::clearDirectDialog`. |
| `chat_get_direct_dialogs` | GET | `ChatController::getDirectDialogs` | 701 | Action маршрутизируется в `ChatController::getDirectDialogs`. |
| `chat_get_direct_messages` | GET | `ChatController::getDirectMessages` | 705 | Action маршрутизируется в `ChatController::getDirectMessages`. |
| `chat_get_messages` | GET | `ChatController::getMessages` | 669 | Action маршрутизируется в `ChatController::getMessages`. |
| `chat_mark_all_read` | POST | `ChatController::markAllRead` | 685 | Action маршрутизируется в `ChatController::markAllRead`. |
| `chat_mark_read` | POST | `ChatController::markRead` | 693 | Action маршрутизируется в `ChatController::markRead`. |
| `chat_send_message` | POST | `ChatController::sendMessage` | 673 | Action маршрутизируется в `ChatController::sendMessage`. |
| `chat_send_message_stream` | POST | `ChatController::sendMessageStream` | 677 | Action маршрутизируется в `ChatController::sendMessageStream`. |
| `chat_send_message_to_admin` | POST | `ChatController::sendMessageToAdmin` | 697 | Action маршрутизируется в `ChatController::sendMessageToAdmin`. |
| `chat_send_message_to_user` | POST | `ChatController::sendMessageToUser` | 709 | Action маршрутизируется в `ChatController::sendMessageToUser`. |

### CoachController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `accept_coach_request` | POST | `CoachController::acceptCoachRequest` | 758 | Action маршрутизируется в `CoachController::acceptCoachRequest`. |
| `apply_coach` | POST | `CoachController::applyCoach` | 774 | Action маршрутизируется в `CoachController::applyCoach`. |
| `coach_athletes` | GET | `CoachController::getCoachAthletes` | 778 | Action маршрутизируется в `CoachController::getCoachAthletes`. |
| `coach_requests` | GET | `CoachController::getCoachRequests` | 754 | Action маршрутизируется в `CoachController::getCoachRequests`. |
| `delete_coach_group` | POST | `CoachController::deleteCoachGroup` | 799 | Action маршрутизируется в `CoachController::deleteCoachGroup`. |
| `get_athlete_groups` | GET | `CoachController::getAthleteGroups` | 811 | Action маршрутизируется в `CoachController::getAthleteGroups`. |
| `get_coach_groups` | GET | `CoachController::getCoachGroups` | 791 | Action маршрутизируется в `CoachController::getCoachGroups`. |
| `get_coach_pricing` | GET | `CoachController::getCoachPricing` | 782 | Action маршрутизируется в `CoachController::getCoachPricing`. |
| `get_group_members` | GET | `CoachController::getGroupMembers` | 803 | Action маршрутизируется в `CoachController::getGroupMembers`. |
| `get_my_coaches` | GET | `CoachController::getMyCoaches` | 766 | Action маршрутизируется в `CoachController::getMyCoaches`. |
| `list_coaches` | GET\|POST | `CoachController::listCoaches` | 746 | Action маршрутизируется в `CoachController::listCoaches`. |
| `reject_coach_request` | POST | `CoachController::rejectCoachRequest` | 762 | Action маршрутизируется в `CoachController::rejectCoachRequest`. |
| `remove_coach` | POST | `CoachController::removeCoach` | 770 | Action маршрутизируется в `CoachController::removeCoach`. |
| `request_coach` | POST | `CoachController::requestCoach` | 750 | Action маршрутизируется в `CoachController::requestCoach`. |
| `save_coach_group` | POST | `CoachController::saveCoachGroup` | 795 | Action маршрутизируется в `CoachController::saveCoachGroup`. |
| `update_coach_pricing` | POST | `CoachController::updateCoachPricing` | 786 | Action маршрутизируется в `CoachController::updateCoachPricing`. |
| `update_group_members` | POST | `CoachController::updateGroupMembers` | 807 | Action маршрутизируется в `CoachController::updateGroupMembers`. |

### ExerciseController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `add_day_exercise` | POST | `ExerciseController::addDayExercise` | 407 | Action маршрутизируется в `ExerciseController::addDayExercise`. |
| `delete_day_exercise` | POST | `ExerciseController::deleteDayExercise` | 415 | Action маршрутизируется в `ExerciseController::deleteDayExercise`. |
| `list_exercise_library` | GET\|POST | `ExerciseController::listExerciseLibrary` | 423 | Action маршрутизируется в `ExerciseController::listExerciseLibrary`. |
| `reorder_day_exercises` | POST | `ExerciseController::reorderDayExercises` | 419 | Action маршрутизируется в `ExerciseController::reorderDayExercises`. |
| `update_day_exercise` | POST | `ExerciseController::updateDayExercise` | 411 | Action маршрутизируется в `ExerciseController::updateDayExercise`. |

### Inline/public

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `assess_goal` | POST | `api_v2.php` | 141 | Публичный маршрут, обрабатываемый прямо в `api_v2.php`. |
| `get_avatar` | GET | `api_v2.php` | 12 | Публичный маршрут, обрабатываемый прямо в `api_v2.php`. |
| `get_site_settings` | GET | `api_v2.php` | 117 | Публичный маршрут, обрабатываемый прямо в `api_v2.php`. |
| `get_user_by_slug` | GET | `api_v2.php` | 154 | Публичный маршрут, обрабатываемый прямо в `api_v2.php`. |

### IntegrationsController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `integration_oauth_url` | GET | `IntegrationsController::getOAuthUrl` | 577 | Action маршрутизируется в `IntegrationsController::getOAuthUrl`. |
| `integrations_status` | GET | `IntegrationsController::getStatus` | 581 | Action маршрутизируется в `IntegrationsController::getStatus`. |
| `strava_token_error` | GET | `IntegrationsController::getStravaTokenError` | 593 | Action маршрутизируется в `IntegrationsController::getStravaTokenError`. |
| `sync_workouts` | POST | `IntegrationsController::syncWorkouts` | 585 | Action маршрутизируется в `IntegrationsController::syncWorkouts`. |
| `unlink_integration` | POST | `IntegrationsController::unlink` | 589 | Action маршрутизируется в `IntegrationsController::unlink`. |

### NoteController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `delete_day_note` | POST | `NoteController::deleteDayNote` | 837 | Action маршрутизируется в `NoteController::deleteDayNote`. |
| `delete_week_note` | POST | `NoteController::deleteWeekNote` | 849 | Action маршрутизируется в `NoteController::deleteWeekNote`. |
| `get_day_notes` | GET | `NoteController::getDayNotes` | 829 | Action маршрутизируется в `NoteController::getDayNotes`. |
| `get_note_counts` | GET | `NoteController::getNoteCounts` | 853 | Action маршрутизируется в `NoteController::getNoteCounts`. |
| `get_plan_notifications` | GET | `NoteController::getPlanNotifications` | 857 | Action маршрутизируется в `NoteController::getPlanNotifications`. |
| `get_week_notes` | GET | `NoteController::getWeekNotes` | 841 | Action маршрутизируется в `NoteController::getWeekNotes`. |
| `mark_plan_notification_read` | POST | `NoteController::markPlanNotificationRead` | 861 | Action маршрутизируется в `NoteController::markPlanNotificationRead`. |
| `save_day_note` | POST | `NoteController::saveDayNote` | 833 | Action маршрутизируется в `NoteController::saveDayNote`. |
| `save_week_note` | POST | `NoteController::saveWeekNote` | 845 | Action маршрутизируется в `NoteController::saveWeekNote`. |

### PushController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `register_push_token` | POST | `PushController::registerToken` | 544 | Action маршрутизируется в `PushController::registerToken`. |
| `unregister_push_token` | POST | `PushController::unregisterToken` | 548 | Action маршрутизируется в `PushController::unregisterToken`. |

### StatsController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `get_all_workouts_list` | GET | `StatsController::getAllWorkoutsList` | 394 | Action маршрутизируется в `StatsController::getAllWorkoutsList`. |
| `get_all_workouts_summary` | GET\|POST | `StatsController::getAllWorkoutsSummary` | 390 | Action маршрутизируется в `StatsController::getAllWorkoutsSummary`. |
| `prepare_weekly_analysis` | GET\|POST | `StatsController::prepareWeeklyAnalysis` | 398 | Action маршрутизируется в `StatsController::prepareWeeklyAnalysis`. |
| `race_prediction` | GET | `StatsController::racePrediction` | 402 | Action маршрутизируется в `StatsController::racePrediction`. |
| `stats` | GET\|POST | `StatsController::stats` | 386 | Action маршрутизируется в `StatsController::stats`. |

### TrainingPlanController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `check_plan_status` | GET\|POST | `TrainingPlanController::checkStatus` | 312 | Action маршрутизируется в `TrainingPlanController::checkStatus`. |
| `clear_plan` | POST | `TrainingPlanController::clearPlan` | 336 | Action маршрутизируется в `TrainingPlanController::clearPlan`. |
| `clear_plan_generation_message` | GET\|POST | `TrainingPlanController::clearPlanGenerationMessage` | 340 | Action маршрутизируется в `TrainingPlanController::clearPlanGenerationMessage`. |
| `generate_next_plan` | POST | `TrainingPlanController::generateNextPlan` | 328 | Action маршрутизируется в `TrainingPlanController::generateNextPlan`. |
| `load` | GET\|POST | `TrainingPlanController::load` | 308 | Action маршрутизируется в `TrainingPlanController::load`. |
| `reactivate_plan` | POST | `TrainingPlanController::reactivatePlan` | 332 | Action маршрутизируется в `TrainingPlanController::reactivatePlan`. |
| `recalculate_plan` | POST | `TrainingPlanController::recalculatePlan` | 324 | Action маршрутизируется в `TrainingPlanController::recalculatePlan`. |
| `regenerate_plan` | GET\|POST | `TrainingPlanController::regeneratePlan` | 316 | Action маршрутизируется в `TrainingPlanController::regeneratePlan`. |
| `regenerate_plan_with_progress` | POST | `TrainingPlanController::regeneratePlanWithProgress` | 320 | Action маршрутизируется в `TrainingPlanController::regeneratePlanWithProgress`. |

### UserController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `generate_telegram_link_code` | POST | `UserController::generateTelegramLinkCode` | 568 | Action маршрутизируется в `UserController::generateTelegramLinkCode`. |
| `notifications_dismiss` | POST | `UserController::dismissNotification` | 540 | Action маршрутизируется в `UserController::dismissNotification`. |
| `notifications_dismissed` | GET | `UserController::getNotificationsDismissed` | 536 | Action маршрутизируется в `UserController::getNotificationsDismissed`. |
| `register_web_push_subscription` | POST | `UserController::registerWebPushSubscription` | 552 | Action маршрутизируется в `UserController::registerWebPushSubscription`. |
| `remove_avatar` | POST | `UserController::removeAvatar` | 523 | Action маршрутизируется в `UserController::removeAvatar`. |
| `send_test_notification` | POST | `UserController::sendTestNotification` | 560 | Action маршрутизируется в `UserController::sendTestNotification`. |
| `telegram_login_url` | GET | `UserController::getTelegramLoginUrl` | 564 | Action маршрутизируется в `UserController::getTelegramLoginUrl`. |
| `unlink_telegram` | POST | `UserController::unlinkTelegram` | 572 | Action маршрутизируется в `UserController::unlinkTelegram`. |
| `unregister_web_push_subscription` | POST | `UserController::unregisterWebPushSubscription` | 556 | Action маршрутизируется в `UserController::unregisterWebPushSubscription`. |
| `update_privacy` | POST | `UserController::updatePrivacy` | 532 | Action маршрутизируется в `UserController::updatePrivacy`. |

### WeekController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `add_training_day` | POST | `WeekController::addTrainingDay` | 436 | Action маршрутизируется в `WeekController::addTrainingDay`. |
| `add_training_day_by_date` | POST | `WeekController::addTrainingDayByDate` | 440 | Action маршрутизируется в `WeekController::addTrainingDayByDate`. |
| `add_week` | POST | `WeekController::addWeek` | 428 | Action маршрутизируется в `WeekController::addWeek`. |
| `copy_day` | POST | `WeekController::copyDay` | 452 | Action маршрутизируется в `WeekController::copyDay`. |
| `copy_week` | POST | `WeekController::copyWeek` | 456 | Action маршрутизируется в `WeekController::copyWeek`. |
| `delete_training_day` | POST | `WeekController::deleteTrainingDay` | 448 | Action маршрутизируется в `WeekController::deleteTrainingDay`. |
| `delete_week` | POST | `WeekController::deleteWeek` | 432 | Action маршрутизируется в `WeekController::deleteWeek`. |
| `update_training_day` | POST | `WeekController::updateTrainingDay` | 444 | Action маршрутизируется в `WeekController::updateTrainingDay`. |

### WorkoutController

| Action | Методы | Обработчик | Строка | Примечание |
|--------|--------|------------|------:|------------|
| `data_version` | GET\|POST | `WorkoutController::dataVersion` | 369 | Action маршрутизируется в `WorkoutController::dataVersion`. |
| `delete_workout` | POST | `WorkoutController::deleteWorkout` | 373 | Action маршрутизируется в `WorkoutController::deleteWorkout`. |
| `get_all_results` | GET\|POST | `WorkoutController::getAllResults` | 365 | Action маршрутизируется в `WorkoutController::getAllResults`. |
| `get_day` | GET\|POST | `WorkoutController::getDay` | 345 | Action маршрутизируется в `WorkoutController::getDay`. |
| `get_result` | GET\|POST | `WorkoutController::getResult` | 361 | Action маршрутизируется в `WorkoutController::getResult`. |
| `get_workout_timeline` | GET\|POST | `WorkoutController::getWorkoutTimeline` | 349 | Action маршрутизируется в `WorkoutController::getWorkoutTimeline`. |
| `reset` | POST | `WorkoutController::reset` | 381 | Action маршрутизируется в `WorkoutController::reset`. |
| `save` | POST | `WorkoutController::save` | 377 | Action маршрутизируется в `WorkoutController::save`. |
| `save_result` | POST | `WorkoutController::saveResult` | 353 | Action маршрутизируется в `WorkoutController::saveResult`. |
| `upload_workout` | POST | `WorkoutController::uploadWorkout` | 357 | Action маршрутизируется в `WorkoutController::uploadWorkout`. |
