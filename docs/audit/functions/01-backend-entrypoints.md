# Backend: entry-поинты, api/, telegram, scripts — справочник функций

## `api/api_wrapper.php` (68 строк)
Главная HTTP-обёртка REST API для React-фронтенда: проксирует все `?action=...` запросы в `planrun-backend/api_v2.php`, гарантируя JSON-ответ даже при фатальных ошибках. Поток выполнения:
1. `ob_start()` + установка анонимных обработчиков: exception handler, error handler (превращает warnings в ErrorException), shutdown handler — любой фатал отдаётся как JSON `{success:false,error}` через замыкание `$apiWrapperJsonError` (L11).
2. Подключает `cors.php` (CORS-заголовки + preflight) и `session_init.php` (настройка cookie/каталога сессий).
3. `session_start()` с fallback на `sys_get_temp_dir()` при недоступности `api/sessions`.
4. Для `request_password_reset`/`confirm_password_reset` рано освобождает сессию (`session_write_close`); для `request_password_reset` дополнительно поднимает `set_time_limit(60)` и заранее грузит Composer autoload (SMTP).
5. Определяет `API_WRAPPER_CORS_SENT` и передаёт управление `planrun-backend/api_v2.php`.
Именованных функций нет (только анонимные колбэки-обработчики ошибок).

## `api/chat_sse.php` (118 строк)
SSE-endpoint реального времени: держит соединение открытым и шлёт событие `chat_unread` со счётчиками непрочитанных сообщений по всем типам чатов (admin, ai, coach, direct). Поток выполнения:
1. CORS + сессия (`cors.php`, `session_init.php`), подключает `auth.php`, `user_functions.php`, `db_config.php`, `constants.php`, `repositories/ChatRepository.php`.
2. Только GET; проверка `isAuthenticated()` и `getCurrentUserId()` — иначе 401.
3. Через `getCurrentUser()` определяет, админ ли пользователь (`UserRoles::ADMIN`).
4. `session_write_close()` — освобождает блокировку сессии; открывает БД (`getDBConnection`).
5. SSE-заголовки (`text/event-stream`, `X-Accel-Buffering: no`), сброс output-буферов, `set_time_limit(0)`.
6. Бесконечный цикл с `sleep(2)`: каждые ~15 итераций пингует БД; читает `ChatRepository::getUnreadCounts($userId)` (для админа добавляет `getAdminUnreadCount()` в `total`/`by_type.admin_mode`); отправляет событие только при изменении JSON либо на пинге; выходит при обрыве соединения/ошибке БД.
Функций нет.

## `api/complete_specialization_api.php` (14 строк)
Тонкая обёртка для React: CORS (`cors.php`) + `session_init.php` + `session_start()`, определяет `API_CORS_SENT` и делегирует в `planrun-backend/complete_specialization_api.php`. Функций нет.

## `api/coros_workout_push.php` (139 строк)
Webhook COROS: приём push-уведомлений Workout Summary + проверка доступности сервиса (Service Status Check). Поток выполнения:
1. GET/HEAD → JSON `{ok:true, service:'planrun-coros-push'}` (health-check для COROS).
2. POST: при заданном `COROS_PUSH_SECRET` сверяет заголовок `X-PlanRun-Coros-Secret` (`hash_equals`), иначе 403.
3. Парсит JSON-тело; через `corosResolveExternalUserId()` извлекает внешний id пользователя COROS.
4. Ищет пользователя в таблице `integration_tokens` (provider='coros', external_athlete_id); если не найден — 200 `ignored`.
5. Отвечает 200 `accepted` и завершает FastCGI-запрос (`fastcgi_finish_request`).
6. Асинхронно: `CorosProvider::fetchWorkouts()` за последние 14 дней → `WorkoutService::importWorkouts()` (таблицы `workouts`, `workout_timeline`); ошибки в `Logger::warning`.
7. Best-effort silent push через `PushNotificationService::sendDataPush()` (`type: coros_sync`).

### `corosResolveExternalUserId(array $payload): ?string` — L124
Ищет внешний id пользователя COROS в payload по списку ключей из env `COROS_PUSH_EXTERNAL_ID_KEYS` (по умолчанию `openId,open_id,userId,user_id,sub`); рекурсивно спускается во вложенные `data`/`user`. Возвращает строку id или null. Побочных эффектов нет.

## `api/cors.php` (61 строка)
Общий CORS-модуль для всех `api/*.php`. Без функций. Поток:
1. Если заголовки уже отправлены — return.
2. Вычисляет, разрешён ли Origin: same-domain (с учётом www и поддоменов), локальная разработка (`localhost`, `127.0.0.1`, `192.168.*`), Capacitor-приложение (`capacitor://localhost`, `https://localhost`).
3. На preflight OPTIONS отдаёт `Access-Control-Allow-*` заголовки и завершает с 204.
4. На обычных запросах ставит `Access-Control-Allow-Origin/Credentials/Max-Age` и определяет константу `API_CORS_SENT`.

## `api/health.php` (7 строк)
Минимальный health-check: GET возвращает JSON `{ok:true, php:PHP_VERSION}` — проверка, что Nginx+PHP-FPM отдают `/api/*.php` без 502. Функций нет.

## `api/login_api.php` (47 строк)
Legacy-обёртка логина для React. Поток: CORS + сессия → `auth.php` → если уже `isAuthenticated()` — `{success:true}`; на POST вызывает `login($username, $password)` (из `planrun-backend/auth.php`, читает таблицу `users`, пишет в `$_SESSION`); при неудаче 401; не-POST → 405. Функций нет. Из фронтенда не используется (логин идёт через `api_wrapper.php?action=login` → AuthController).

## `api/logout_api.php` (31 строка)
Legacy-обёртка выхода: CORS + сессия → на POST вызывает `logout()` (из `auth.php`: чистит `$_SESSION`, удаляет cookie, `session_destroy`); для AJAX отдаёт JSON, иначе redirect на `/`; не-POST → 405. Функций нет. Из фронтенда не используется (выход через `?action=logout`).

## `api/oauth_callback.php` (136 строк)
Универсальный OAuth-callback интеграций (Huawei, Strava, Polar, Garmin, COROS, Suunto). Два режима аутентификации: web (сессия+CSRF) и mobile (подписанный HMAC state без сессии). Поток:
1. `session_init.php` + `session_start()`; читает `provider`, `code`, `state`, `error` из GET.
2. При `error` или нехватке параметров — redirect на `/settings?tab=integrations&error=...`.
3. Пытается декодировать `state` вида `payload.hmac` (HMAC-SHA256 c `JWT_SECRET_KEY`): валидный и не старше 10 минут — берёт `uid` и флаг `app` (mobile flow).
4. Fallback на сессию: проверка `$_SESSION['authenticated']` + CSRF (`csrf_token`/`integration_state`, очищаются после использования).
5. Подключает провайдеров (`providers/*.php`); маппинг providerId→класс; неизвестный — error redirect.
6. `Provider::exchangeCodeForTokens($code,$state)` — пишет токены в `integration_tokens`; для Strava дополнительно `ensureIntegrationHealthy()`, для Polar — `ensureWebhookSubscription()` (ошибки в `Logger::warning`).
7. Redirect: mobile — deep link `planrun://oauth-callback?...`, web — `/settings?tab=integrations&connected=...`; исключения — в error-redirect.
Функций нет.

## `api/polar_webhook.php` (136 строк)
Webhook Polar AccessLink: PING (регистрация) и EXERCISE (новая тренировка). Поток:
1. Только POST (иначе 405); локальный лог-замыкание `$log` пишет в `planrun-backend/logs/polar_webhook.log`.
2. Событие PING — отвечает 200 `{}` без проверки подписи.
3. Для EXERCISE: `PolarProvider::loadWebhookSignatureSecret()`; проверка `Polar-Webhook-Signature` через `verifyWebhookSignature()` (HMAC-SHA256 raw body); плохая подпись — 403, нет секрета — 200 + лог skip.
4. Ищет пользователя в `integration_tokens` (provider='polar', external_athlete_id=user_id из payload); не найден — 200.
5. Отвечает 200 `{}`, `fastcgi_finish_request()`.
6. Асинхронно: `PolarProvider::fetchSingleExerciseByUrl()` → `WorkoutService::importWorkouts()` → best-effort `PushNotificationService::sendDataPush()` (`type: workout_sync`).
Функций нет.

## `api/register_api.php` (13 строк)
Тонкая обёртка регистрации для React: CORS + `session_init.php` + `session_start()` и делегирование в `planrun-backend/register_api.php`. Функций нет.

## `api/session_init.php` (36 строк)
Общая инициализация сессии для `api/*.php`. Без функций. Поток: если сессия не запущена — создаёт каталог `api/sessions` и ставит его как `session_save_path`; задаёт `gc_maxlifetime` и `cookie_lifetime` = 30 дней; cookie-политика по протоколу: HTTPS → `SameSite=None; Secure`, HTTP (localhost) → `Lax; Secure=0`; всегда `httponly`.

## `api/strava_webhook.php` (159 строк)
Webhook Strava: push о create/update/delete активностей. Поток:
1. GET — верификация подписки: сверяет `hub.verify_token` с env `STRAVA_WEBHOOK_VERIFY_TOKEN`, отвечает `{hub.challenge}` или 403.
2. POST: парсит событие; лог в `planrun-backend/logs/strava_webhook.log` (замыкание `$log`); не-activity — 200.
3. Ищет владельца в `integration_tokens` JOIN `users` по `external_athlete_id`; если пользователь удалён — чистит осиротевшие строки `integration_tokens` (DELETE) и отвечает 200.
4. `delete`: находит тренировку в `workouts` по `external_id='strava_<id>'`, удаляет связанные `workout_timeline` и саму запись `workouts`.
5. `create`/`update`: отвечает 200 `{}` сразу (требование Strava — 2 сек), `fastcgi_finish_request()`; затем `StravaProvider::ensureIntegrationHealthy()` + `fetchSingleActivity()` → `WorkoutService::importWorkouts()` → best-effort `PushNotificationService::sendDataPush()`.
Функций нет.

## `api/suunto_oauth_callback.php` (15 строк)
Выделенный OAuth-callback для Suunto (Suunto не принимает redirect_uri с query string): принудительно ставит `$_GET['provider']='suunto'` и подключает общий `oauth_callback.php`. Функций нет.

## `api/suunto_webhook.php` (180 строк)
Webhook Suunto (type=WORKOUT_CREATED). Поток:
1. GET/HEAD → JSON health-check; не-POST → 405.
2. Проверка подписи `X-HMAC-SHA256-Signature` через `suuntoVerifySignature()` (секрет `SUUNTO_WEBHOOK_SECRET`); при несовпадении логирует кандидатов base64/hex (`Logger::warning`); отклоняет 401 только при `SUUNTO_WEBHOOK_STRICT=1`, иначе продолжает.
3. Извлекает `type`, `username`, `workout{}`, `workoutKey`; логирует факт доставки.
4. Ищет пользователя в `integration_tokens` (provider='suunto', external_athlete_id=username); не найден — 200 `ignored`.
5. Отвечает 200 `accepted`, `fastcgi_finish_request()`; типы ≠ WORKOUT_CREATED игнорирует.
6. Импорт каскадом: `SuuntoProvider::fetchWorkoutFit()` (FIT: GPS+пульс+таймлайн) → fallback `mapSuuntoWorkout()` (сводка из тела) → fallback `fetchWorkoutByKey()`; затем `WorkoutService::importWorkouts()` и best-effort push (`type: suunto_sync`).

### `suuntoVerifySignature(string $body, string $secret, string $provided): bool` — L164
Constant-time сравнение HMAC-SHA256 от тела запроса с присланной подписью; принимает обе кодировки — base64 и hex (`hash_equals` по каждому кандидату). Побочных эффектов нет.

## `api/telegram_login_callback.php` (135 строк)
OAuth-callback Telegram Login (привязка Telegram-аккаунта). Поток:
1. Сессия (с fallback на tmp), env, `db_config.php`, `services/TelegramLoginService.php`.
2. Вычисляет web origin/redirect (`/settings?tab=integrations`); читает `code`, `state`, `error` из GET.
3. `TelegramLoginService::getFlowFromState($state)` → контекст flow (uid, code_verifier, флаг app); `exchangeCodeForTokens()` (PKCE) → `validateIdToken()` → `linkTelegramAccount()` (пишет `telegram_id` в `users`); `deleteFlow()`; `sendWelcomeMessageIfConfigured()` (HTTP к Telegram).
4. Успех: app-flow — deep link `planrun://oauth-callback?connected=telegram`; web — HTML-страница с postMessage в `window.opener`.
5. Ошибки: удаляет flow, логирует `Logger::warning`, отдаёт error через deep link или postMessage/redirect.

### `telegramLoginRenderWebResult(string $origin, string $redirectUrl, array $payload): void` — L32
Выводит HTML-страницу с инлайн-скриптом: postMessage payload в `window.opener` (тип `planrun:telegram-login`) + `window.close()`, иначе redirect на `redirectUrl`; `<noscript>` meta-refresh. Завершает выполнение (`exit`).

### `telegramLoginFinishApp(string $status, string $message = ''): void` — L61
Завершает mobile-flow: redirect на deep link `planrun://oauth-callback` с `connected=telegram` либо `error=<message>`. `exit`.

### `telegramLoginFinishWeb(string $origin, string $redirectBase, string $status, string $message = ''): void` — L69
Завершает web-flow: формирует payload статуса connected/error и делегирует в `telegramLoginRenderWebResult()`.

## `planrun-backend/api_v2.php` (1015 строк)
Главный роутер API: принимает `?action=...`, до switch обрабатывает публичные действия (avatar, chat media, site settings, assess_goal, профиль по slug), затем маршрутизирует ~150 actions на контроллеры через `planrunRouteControllerAction()`. Дублирует CORS-логику `cors.php` для случая прямого вызова (без `API_WRAPPER_CORS_SENT`); preflight OPTIONS → 204. Подключает `auth.php`, `db_config.php`, `Logger`, `error_handler` (регистрирует `ErrorHandler::register()`), `cache_config.php` и все контроллеры. Неизвестный action → 404; исключения → `Logger::exception` + 500.

### `planrunRouteControllerAction($db, $controllerClass, $controllerMethod, $requestMethod, $allowedMethods = null)` — L104
Универсальный диспетчер: проверяет HTTP-метод против allowlist (иначе `ErrorHandler::returnJsonError(405)`), создаёт контроллер с `$db`, вызывает метод и `exit`.

### action: get_avatar — L12
Публичная раздача файла аватара ДО любых JSON-заголовков: `AvatarService::serveRequestedAvatar($file, $variant)` (читает файлы аватаров с диска, отдаёт картинку); не найден — 404 JSON. Дублирующая ветка в switch (L601) — заглушка 405 (реальный код выше).

### action: get_chat_media — L26
Публичная раздача вложений чата (фото) через `ChatMediaService::serveRequested($file)`; не найден — 404 JSON.

### action: get_site_settings — L129
GET без авторизации: читает таблицу `site_settings` (если существует, через `SHOW TABLES`) поверх дефолтов (site_name, maintenance_mode, registration_enabled и др.) и возвращает JSON.

### action: assess_goal — L153
POST без авторизации (вызывается при регистрации): строит `training_state` через `TrainingStateBuilder::buildForUser()` и вызывает `assessGoalRealism()` из `planrun_ai/prompt_builder.php` (оценка реалистичности цели). Возвращает результат оценки.

### action: get_user_by_slug — L166
GET без обязательной авторизации: публичный профиль по `username_slug`. Читает `users`; вычисляет права (`can_view/can_edit/is_owner/is_coach`) по `privacy_level` (public/private/link+token) и тренерским связям (`isUserCoach`, `getUserCoachAccess` из `query_helpers.php`); для тренеров добавляет coach-поля и прайс из `coach_pricing`; при доступе — приватные поля по `privacy_show_*`; список тренеров из `user_coaches` JOIN `users`. Отдаёт `{user, access, coaches}`.

Дальше — маршруты switch (формат: `controller::method`, ограничение HTTP-метода если есть):

### action: load — L336
`TrainingPlanController::load` — загрузка тренировочного плана (недели/дни) пользователя.

### action: check_plan_status — L340
`TrainingPlanController::checkStatus` — статус генерации/активности плана.

### action: regenerate_plan — L344
`TrainingPlanController::regeneratePlan` — перегенерация плана.

### action: regenerate_plan_with_progress — L348
POST. `TrainingPlanController::regeneratePlanWithProgress` — перегенерация с прогрессом.

### action: recalculate_plan — L352
POST. `TrainingPlanController::recalculatePlan` — пересчёт плана.

### action: generate_next_plan — L356
POST. `TrainingPlanController::generateNextPlan` — генерация следующего плана после завершения текущего.

### action: submit_plan_readiness_check — L360
POST. `TrainingPlanController::submitReadinessCheck` — ответы на проверку готовности к плану.

### action: reactivate_plan — L364
POST. `TrainingPlanController::reactivatePlan` — реактивация плана.

### action: clear_plan — L368
POST. `TrainingPlanController::clearPlan` — очистка плана.

### action: clear_plan_generation_message — L372
`TrainingPlanController::clearPlanGenerationMessage` — сброс сообщения о генерации.

### action: get_recent_workout_analyses — L377
GET. `WorkoutController::getRecentWorkoutAnalyses` — последние AI-анализы тренировок.

### action: get_day — L381
`WorkoutController::getDay` — данные одного дня плана.

### action: get_days — L385
`WorkoutController::getDays` — данные нескольких дней.

### action: get_workout_timeline — L389
`WorkoutController::getWorkoutTimeline` — поточечный таймлайн тренировки (GPS/пульс).

### action: get_workout_share_map — L393
`WorkoutController::getWorkoutShareMap` — статическая карта маршрута для share.

### action: generate_workout_share_card — L397
`WorkoutController::generateWorkoutShareCard` — генерация share-карточки тренировки.

### action: store_workout_share_card — L401
POST. `WorkoutController::storeWorkoutShareCard` — сохранение share-карточки.

### action: save_result — L405
POST. `WorkoutController::saveResult` — сохранение результата тренировки (workout_log).

### action: upload_workout — L409
POST. `WorkoutController::uploadWorkout` — загрузка файла тренировки (GPX/TCX/FIT).

### action: get_result — L413
`WorkoutController::getResult` — результат тренировки за день.

### action: get_all_results — L417
`WorkoutController::getAllResults` — все результаты пользователя.

### action: data_version — L421
`WorkoutController::dataVersion` — версия данных (для инвалидации кеша на клиенте).

### action: delete_workout — L425
POST. `WorkoutController::deleteWorkout` — удаление тренировки.

### action: save — L429
POST. `WorkoutController::save` — сохранение тренировочных данных дня.

### action: reset — L433
POST. `WorkoutController::reset` — сброс данных дня.

### action: stats — L438
`StatsController::stats` — сводная статистика.

### action: get_all_workouts_summary — L442
`StatsController::getAllWorkoutsSummary` — сводка по всем тренировкам.

### action: get_all_workouts_list — L446
GET. `StatsController::getAllWorkoutsList` — постраничный список тренировок.

### action: prepare_weekly_analysis — L450
`StatsController::prepareWeeklyAnalysis` — данные недели для AI-анализа (использует `prepareWeeklyAnalysis()` из prepare_weekly_analysis.php).

### action: race_prediction — L454
GET. `StatsController::racePrediction` — прогноз результата забега.

### action: get_personal_records — L458
GET. `StatsController::personalRecords` — личные рекорды.

### action: training_load — L462
GET. `StatsController::trainingLoad` — тренировочная нагрузка (CTL/ATL и т.п.).

### action: add_day_exercise — L467
POST. `ExerciseController::addDayExercise` — добавить упражнение в день плана.

### action: update_day_exercise — L471
POST. `ExerciseController::updateDayExercise` — обновить упражнение.

### action: delete_day_exercise — L475
POST. `ExerciseController::deleteDayExercise` — удалить упражнение.

### action: reorder_day_exercises — L479
POST. `ExerciseController::reorderDayExercises` — изменить порядок упражнений.

### action: list_exercise_library — L483
`ExerciseController::listExerciseLibrary` — библиотека упражнений.

### action: mark_exercises_completed — L487
POST. `ExerciseController::markExercisesCompleted` — отметить выполнение упражнений.

### action: get_exercise_history — L491
`ExerciseController::getExerciseHistory` — история упражнения.

### action: get_executed_for_day — L495
`ExerciseController::getExecutedForDay` — выполненные упражнения за день.

### action: get_executed_dates — L499
`ExerciseController::getExecutedDates` — даты с выполненными упражнениями.

### action: add_week — L504
POST. `WeekController::addWeek` — добавить неделю в план.

### action: delete_week — L508
POST. `WeekController::deleteWeek` — удалить неделю.

### action: add_training_day — L512
POST. `WeekController::addTrainingDay` — добавить тренировочный день.

### action: add_training_day_by_date — L516
POST. `WeekController::addTrainingDayByDate` — добавить день по дате (создаёт неделю при необходимости).

### action: update_training_day — L520
POST. `WeekController::updateTrainingDay` — обновить день.

### action: delete_training_day — L524
POST. `WeekController::deleteTrainingDay` — удалить день.

### action: copy_day — L528
POST. `WeekController::copyDay` — копировать день.

### action: copy_week — L532
POST. `WeekController::copyWeek` — копировать неделю.

### action: get_profile — L541
GET (проверка inline, без `planrunRouteControllerAction`). `UserController::getProfile` — профиль текущего пользователя.

### action: update_profile — L549
POST (inline-проверка). `UserController::updateProfile` — обновление профиля.

### action: get_notification_settings — L557
GET (inline). `UserController::getNotificationSettings` — настройки уведомлений.

### action: get_notification_delivery_log — L565
GET (inline). `UserController::getNotificationDeliveryLog` — лог доставки уведомлений.

### action: update_notification_settings — L573
POST (inline). `UserController::updateNotificationSettings` — сохранение настроек уведомлений.

### action: delete_user — L581
POST (inline). `UserController::deleteUser` — удаление аккаунта.

### action: upload_avatar — L589
POST (inline). `UserController::uploadAvatar` — загрузка аватара.

### action: remove_avatar — L597
POST. `UserController::removeAvatar` — удаление аватара.

### action: update_privacy — L606
POST. `UserController::updatePrivacy` — настройки приватности.

### action: notifications_dismissed — L610
GET. `UserController::getNotificationsDismissed` — список скрытых уведомлений.

### action: notifications_dismiss — L614
POST. `UserController::dismissNotification` — скрыть уведомление.

### action: register_push_token — L618
POST. `PushController::registerToken` — регистрация FCM push-токена.

### action: unregister_push_token — L622
POST. `PushController::unregisterToken` — удаление push-токена.

### action: register_web_push_subscription — L626
POST. `UserController::registerWebPushSubscription` — регистрация Web Push подписки.

### action: unregister_web_push_subscription — L630
POST. `UserController::unregisterWebPushSubscription` — удаление Web Push подписки.

### action: send_test_notification — L634
POST. `UserController::sendTestNotification` — тестовое уведомление.

### action: telegram_login_url — L638
GET. `UserController::getTelegramLoginUrl` — URL для Telegram Login (OAuth).

### action: generate_telegram_link_code — L642
POST. `UserController::generateTelegramLinkCode` — код привязки Telegram-бота.

### action: unlink_telegram — L646
POST. `UserController::unlinkTelegram` — отвязка Telegram.

### action: integration_oauth_url — L651
GET. `IntegrationsController::getOAuthUrl` — OAuth URL провайдера интеграции.

### action: integrations_status — L655
GET. `IntegrationsController::getStatus` — статус подключённых интеграций.

### action: sync_workouts — L659
POST. `IntegrationsController::syncWorkouts` — ручная синхронизация тренировок.

### action: unlink_integration — L663
POST. `IntegrationsController::unlink` — отключение интеграции.

### action: set_suunto_mirror — L667
POST. `IntegrationsController::setSuuntoMirror` — настройка Suunto mirror.

### action: health_connect_import — L672
POST. `IntegrationsController::importHealthConnect` — приём тренировок, прочитанных нативно на Android (Health Connect).

### action: strava_token_error — L676
GET. `IntegrationsController::getStravaTokenError` — последняя ошибка Strava-токена.

### action: get_csrf_token — L681
Inline (без контроллера): генерирует/возвращает `$_SESSION['csrf_token']` (random_bytes 32).

### action: login — L694
POST. `AuthController::login` — вход (сессия + JWT для нативных клиентов).

### action: telegram_miniapp_auth — L698
POST. `AuthController::telegramMiniAppAuth` — авторизация из Telegram Mini App (initData).

### action: logout — L702
POST. `AuthController::logout` — выход.

### action: refresh_token — L706
POST. `AuthController::refreshToken` — обновление JWT.

### action: check_auth — L710
`AuthController::checkAuth` — проверка авторизации, возвращает данные пользователя (avatar_path и др.).

### action: admin_list_users — L715
GET. `AdminController::listUsers` — список пользователей (админ).

### action: admin_get_user — L719
GET. `AdminController::getUser` — данные пользователя (админ).

### action: admin_update_user — L723
POST. `AdminController::updateUser` — обновление пользователя (админ).

### action: admin_get_settings — L727
GET. `AdminController::getSettings` — настройки сайта (админ).

### action: admin_update_settings — L731
POST. `AdminController::updateSettings` — сохранение настроек сайта.

### action: admin_get_notification_templates — L735
GET. `AdminController::getNotificationTemplates` — шаблоны уведомлений.

### action: admin_update_notification_template — L739
POST. `AdminController::updateNotificationTemplate` — обновление шаблона.

### action: admin_reset_notification_template — L743
POST. `AdminController::resetNotificationTemplate` — сброс шаблона к дефолту.

### action: admin_ai_plan_metrics — L748
GET. `AdminController::getAiPlanMetrics` — метрики AI-генерации планов (observability).

### action: admin_ai_plan_events — L752
GET. `AdminController::getAiPlanRecentEvents` — последние события AI-генерации.

### action: request_password_reset — L756
`AuthController::requestPasswordReset` — запрос сброса пароля (отправка email через SMTP).

### action: confirm_password_reset — L760
`AuthController::confirmPasswordReset` — подтверждение сброса пароля.

### action: get_latest_proactive_message — L765
GET. `ChatController::getLatestProactiveMessage` — последнее проактивное сообщение AI.

### action: chat_get_messages — L769
GET. `ChatController::getMessages` — сообщения чата.

### action: chat_send_message — L773
POST. `ChatController::sendMessage` — отправка сообщения (AI-чат).

### action: chat_upload_media — L777
POST. `ChatController::uploadChatMedia` — загрузка вложения (фото) в чат.

### action: chat_send_message_stream — L781
POST. `ChatController::sendMessageStream` — отправка с потоковым (SSE) ответом AI.

### action: chat_clear_ai — L785
POST. `ChatController::clearAiChat` — очистка AI-чата.

### action: chat_clear_admin — L789
POST. `ChatController::clearAdminChat` — очистка admin-чата.

### action: chat_mark_all_read — L793
POST. `ChatController::markAllRead` — отметить все прочитанными.

### action: chat_admin_mark_all_read — L797
POST. `ChatController::markAdminAllRead` — отметить admin-сообщения прочитанными.

### action: chat_mark_read — L801
POST. `ChatController::markRead` — отметить сообщение прочитанным.

### action: chat_send_message_to_admin — L805
POST. `ChatController::sendMessageToAdmin` — сообщение администратору.

### action: chat_get_direct_dialogs — L809
GET. `ChatController::getDirectDialogs` — список личных диалогов.

### action: chat_get_direct_messages — L813
GET. `ChatController::getDirectMessages` — сообщения личного диалога.

### action: chat_send_message_to_user — L817
POST. `ChatController::sendMessageToUser` — личное сообщение пользователю (coach↔athlete).

### action: chat_clear_direct_dialog — L821
POST. `ChatController::clearDirectDialog` — очистка личного диалога.

### action: chat_admin_send_message — L825
POST. `ChatController::sendAdminMessage` — ответ админа пользователю.

### action: chat_admin_chat_users — L829
GET. `ChatController::getAdminChatUsers` — пользователи с admin-диалогами.

### action: chat_admin_unread_notifications — L833
GET. `ChatController::getAdminUnreadNotifications` — непрочитанные для админа.

### action: chat_admin_broadcast — L837
POST. `ChatController::broadcastAdminMessage` — рассылка всем пользователям.

### action: chat_admin_get_messages — L841
GET. `ChatController::getAdminMessages` — сообщения admin-диалога с пользователем.

### action: chat_admin_mark_conversation_read — L845
POST. `ChatController::markAdminConversationRead` — отметить диалог прочитанным.

### action: chat_add_ai_message — L849
POST. `ChatController::addAIMessage` — добавить сообщение от имени AI.

### action: list_coaches — L854
`CoachController::listCoaches` — каталог тренеров.

### action: request_coach — L858
POST. `CoachController::requestCoach` — заявка атлета тренеру.

### action: coach_requests — L862
GET. `CoachController::getCoachRequests` — входящие заявки тренера.

### action: accept_coach_request — L866
POST. `CoachController::acceptCoachRequest` — принять заявку.

### action: reject_coach_request — L870
POST. `CoachController::rejectCoachRequest` — отклонить заявку.

### action: get_my_coaches — L874
GET. `CoachController::getMyCoaches` — тренеры текущего пользователя.

### action: remove_coach — L878
POST. `CoachController::removeCoach` — удалить связь с тренером.

### action: apply_coach — L882
POST. `CoachController::applyCoach` — заявка пользователя на роль тренера.

### action: coach_athletes — L886
GET. `CoachController::getCoachAthletes` — атлеты тренера.

### action: get_coach_pricing — L890
GET. `CoachController::getCoachPricing` — прайс тренера (`coach_pricing`).

### action: update_coach_pricing — L894
POST. `CoachController::updateCoachPricing` — сохранение прайса.

### action: get_my_coach_profile — L898
GET. `CoachController::getMyCoachProfile` — coach-профиль текущего тренера.

### action: update_coach_profile — L902
POST. `CoachController::updateCoachProfile` — обновление coach-профиля.

### action: get_coach_groups — L907
GET. `CoachController::getCoachGroups` — группы атлетов тренера.

### action: save_coach_group — L911
POST. `CoachController::saveCoachGroup` — создание/обновление группы.

### action: delete_coach_group — L915
POST. `CoachController::deleteCoachGroup` — удаление группы.

### action: get_group_members — L919
GET. `CoachController::getGroupMembers` — участники группы.

### action: update_group_members — L923
POST. `CoachController::updateGroupMembers` — изменение состава группы.

### action: get_athlete_groups — L927
GET. `CoachController::getAthleteGroups` — группы атлета.

### action: list_workout_templates — L932
GET. `CoachController::listWorkoutTemplates` — шаблоны тренировок тренера.

### action: save_workout_template — L936
POST. `CoachController::saveWorkoutTemplate` — сохранение шаблона.

### action: delete_workout_template — L940
POST. `CoachController::deleteWorkoutTemplate` — удаление шаблона.

### action: bulk_assign_training — L944
POST. `CoachController::bulkAssignTraining` — массовое назначение тренировки атлетам/группе.

### action: coach_events — L948
GET. `CoachController::getCoachEvents` — лента событий тренера.

### action: get_athlete_details — L952
GET. `CoachController::getAthleteDetails` — детали атлета для тренера.

### action: admin_coach_applications — L957
GET. `AdminController::getCoachApplications` — заявки на роль тренера (админ).

### action: admin_approve_coach — L961
POST. `AdminController::approveCoachApplication` — одобрить заявку тренера.

### action: admin_reject_coach — L965
POST. `AdminController::rejectCoachApplication` — отклонить заявку тренера.

### action: get_day_notes — L970
GET. `NoteController::getDayNotes` — заметки дня.

### action: save_day_note — L974
POST. `NoteController::saveDayNote` — сохранение заметки дня.

### action: delete_day_note — L978
POST. `NoteController::deleteDayNote` — удаление заметки дня.

### action: get_week_notes — L982
GET. `NoteController::getWeekNotes` — заметки недели.

### action: save_week_note — L986
POST. `NoteController::saveWeekNote` — сохранение заметки недели.

### action: delete_week_note — L990
POST. `NoteController::deleteWeekNote` — удаление заметки недели.

### action: get_note_counts — L994
GET. `NoteController::getNoteCounts` — счётчики заметок.

### action: get_plan_notifications — L998
GET. `NoteController::getPlanNotifications` — уведомления об изменениях плана.

### action: mark_plan_notification_read — L1002
POST. `NoteController::markPlanNotificationRead` — отметить уведомление плана прочитанным.

## `planrun-backend/auth.php` (63 строки)
Базовая сессионная аутентификация (все функции в `function_exists`-гардах). Стартует сессию при подключении (кроме CLI). `getCurrentUserId()`/`getCurrentUser()` вынесены в user_functions.php.

### `isAuthenticated()` — L13
Проверяет `$_SESSION['authenticated'] === true` и наличие `$_SESSION['user_id']`. Без побочных эффектов.

### `login($username, $password)` — L21
Ищет пользователя в `users` по username ИЛИ email (trim обоих полей), проверяет пароль `password_verify`; при успехе пишет в сессию `authenticated`, `user_id`, `username`, `login_time`. Возвращает bool. Использует `getDBConnection()`.

### `logout()` — L45
Очищает `$_SESSION`, удаляет session-cookie (setcookie в прошлое), вызывает `session_destroy()`.

### `requireAuth()` — L57
Legacy-гард для старого PHP-фронтенда: при неавторизованности redirect на `login.php` + exit. В текущем коде не вызывается (контроллеры используют метод `BaseController::requireAuth`).

## `planrun-backend/cache_config.php` (374 строки)
Слой кеширования: конфиг из .env (`CACHE_TYPE` redis/memcached/file/none, по умолчанию file), константы хостов/портов/TTL (`CACHE_DEFAULT_TTL` 3600), фабрика `getCache()` и 4 реализации + статический фасад `Cache`.

### `getCache()` — L36
Фабрика-синглтон (static): по `CACHE_TYPE` создаёт RedisCache/MemcachedCache/FileCache/NullCache и возвращает один экземпляр на запрос.

### class CacheInterface — L61
Абстрактный базовый класс кеша; объявляет абстрактные методы `get($key)` (L62), `set($key,$value,$ttl)` (L63), `delete($key)` (L64), `clear()` (L65).

### class RedisCache — L71
Кеш на phpredis с serialize/unserialize значений.

#### `__construct()` — L74
Требует расширение redis (иначе Exception); подключается к REDIS_HOST:REDIS_PORT, auth по REDIS_PASSWORD, `select(REDIS_DATABASE)`. HTTP/TCP-соединение с Redis.

#### `get($key)` — L89
`GET` + unserialize; null если ключа нет.

#### `set($key, $value, $ttl = null)` — L94
`SETEX` c TTL (дефолт CACHE_DEFAULT_TTL), serialize значения.

#### `delete($key)` — L99
`DEL` ключа.

#### `clear()` — L103
`FLUSHDB` — очистка всей базы Redis.

### class MemcachedCache — L111
Кеш на расширении memcached.

#### `__construct()` — L114
Требует расширение memcached; `addServer(MEMCACHED_HOST, MEMCACHED_PORT)`.

#### `get($key)` — L123
`get`; false нормализует в null.

#### `set($key, $value, $ttl = null)` — L128
`set` с TTL.

#### `delete($key)` — L133
`delete` ключа.

#### `clear()` — L137
`flush` всего memcached.

### class FileCache — L145
Файловый кеш в `planrun-backend/cache/` (CACHE_DIR), шардирование по первым 2 символам md5 ключа; формат — serialize массива `{key, value, expires}`.

#### `__construct()` — L149
Создаёт каталог кеша через `ensureDirectory`, ставит флаг `available`.

#### `ensureDirectory($dir)` — L154 (private)
mkdir 0775 + chmod 02775 (setgid); возвращает доступность на запись. Пишет на ФС.

#### `getFilePath($key)` — L171 (private)
Путь файла: `CACHE_DIR/<md5[0:2]>/<md5>.cache`.

#### `get($key)` — L176
Читает файл, `unserialize(['allowed_classes'=>false])`, проверяет `expires`; просроченные/битые файлы удаляет (unlink). null если нет/просрочен.

#### `set($key, $value, $ttl = null)` — L207
Пишет serialize-данные с `expires=time()+ttl`, chmod 0664.

#### `delete($key)` — L233
unlink файла ключа.

#### `clear()` — L242
Рекурсивный обход CACHE_DIR (RecursiveIteratorIterator), удаляет все `*.cache`.

### class NullCache — L269
Заглушка «без кеша»: `get` (L270) → null, `set` (L271) → true, `delete` (L272) → true, `clear` (L273) → true.

### class Cache — L279
Статический фасад над `getCache()`.

#### `remember($key, $callback, $ttl = 3600)` — L288 (static)
Get-or-compute: возвращает кешированное либо вычисляет колбэком и сохраняет. В батче (и репо) вызовов не найдено.

#### `get($key)` — L305 (static)
Прокси к `getCache()->get()`.

#### `set($key, $value, $ttl = 3600)` — L312 (static)
Прокси к `getCache()->set()`.

#### `delete($key)` — L319 (static)
Прокси к `getCache()->delete()`.

#### `invalidate($pattern)` — L326 (static)
Для FileCache: обходит все `.cache` файлы, десериализует, сравнивает сохранённый `key` с паттерном через `fnmatch`, удаляет совпавшие. Для других бэкендов — просто `delete($pattern)` (паттерны НЕ работают).

#### `clear()` — L371 (static)
Полная очистка кеша.

## `planrun-backend/calendar_access.php` (221 строка)
Проверка доступа к чужому календарю (персональные URL вида `/@slug`, как ВКонтакте) + генераторы URL. Частично legacy (ссылки на `login.php`, `workout_details.php`).

### `getCalendarAccess()` — L16
Определяет, чей календарь просматривается (GET `view`/`slug`/`id` из .htaccess-реврайтов) и права: читает `users` (privacy_level, public_token); для private — доступ только владельцу/тренеру (`isUserCoach`), для link — нужен token; вычисляет can_edit/can_view через `getUserCoachAccess` (query_helpers.php). Возвращает `{user_id, can_edit, can_view, is_owner, is_coach}` либо `{error}`. Используется `BaseController`.

### `getCalendarUser($userId)` — L112
Читает из `users` базовые поля (включая telegram_id, telegram_link_code) по id. Просто SELECT.

### `getUserCalendarUrl($userId)` — L125
Возвращает `/@username_slug`; при отсутствии slug генерирует его из username (lowercase, замена не-[a-z0-9_], проверка уникальности с инкрементом) и ПИШЕТ в `users.username_slug`. Дублирует логику `generateUsernameSlug()` из user_functions.php. Вне файла не вызывается — кандидат в мёртвый код.

### `getWorkoutDetailsUrl($workoutId, $userId = null)` — L180
Строит «красивый» URL `@slug/YYYY-MM-DD/workoutId`; читает `workouts` (start_time, user_id) и `users` через `getCalendarUser()`; fallback — legacy `workout_details.php?id=`. Используется WorkoutService и StatsService.

## `planrun-backend/complete_specialization_api.php` (411 строк)
Entry-поинт второго этапа регистрации (онбординг-специализация): обновляет профиль пользователя и создаёт/ставит в очередь тренировочный план. Поток:
1. JSON-заголовок; собственный CORS-блок если не `API_CORS_SENT` (дубль cors.php).
2. Только POST; `planrunEnsureSessionStarted()`; БД.
3. Авторизация: сессия, fallback — JWT через `AuthService::validateJwtToken()` (восстанавливает сессию из JWT).
4. Парсит и валидирует ~35 полей онбординга (goal_type, race_*, gender, training_mode ai/coach/self, experience_level, preferred_days, easy_pace, last_race_* и т.д.) — почти полная копия валидации из register_api.php; условные обязательные поля по goal_type/training_mode.
5. Динамический `UPDATE users SET ...` по карте `$userData` (bind_param через call_user_func_array), затем `clearUserCache()`.
6. Если нет плана в `user_training_plans` — INSERT (для ai — is_active=0, активирует job генерации; self/coach — сразу активен).
7. Для ai — `PlanGenerationQueueService::enqueue($userId,'generate')`; self/coach — только сообщение. Отдаёт JSON `{success, plan_message, onboarding_completed}`.
Замечание: проверки `$targetMarathonDate` (L222, L227) обращаются к необъявленной переменной — всегда empty.

### `planrunEnsureSessionStarted()` — L35
Гарантирует активную сессию: `@session_start()`, при неудаче — fallback на `sys_get_temp_dir()`. Дубликат одноимённой функции в register_api.php (обе под `function_exists`).

## `planrun-backend/db_config.php` (50 строк)
Конфигурация подключения к MySQL: константы DB_* из .env (через `config/env_loader.php`), защита от двойного подключения файла.

### `getDBConnection()` — L29
Синглтон-подключение mysqli (static): создаёт соединение DB_HOST/DB_USER/DB_PASS/DB_NAME, ставит charset; при ошибке логирует в error_log и возвращает null.

## `planrun-backend/load_training_plan.php` (168 строк)
Загрузка тренировочного плана из БД (упрощённая модель без фаз) с кешированием. При прямом запуске файла (не require) выполняет загрузку для текущего пользователя — legacy-режим.

### `loadTrainingPlanForUser($userId, $useCache = true)` — L21
Читает `training_plan_weeks` (ORDER BY week_number) и по каждой неделе `training_plan_days`; раскладывает дни по mon..sun (массивы записей `{type,text,id,key}`); для беговых типов суммирует дистанцию из `training_day_exercises` (category='run'); итоговый объём недели = max(вычисленный, хранимый `total_volume`). Кеширует результат на 15 минут (`Cache::set("training_plan_{userId}")`, пустые планы не кеширует), лог `Logger::debug`. Возвращает `{weeks_data: [...]}`.

## `planrun-backend/prepare_weekly_analysis.php` (696 строк)
Подготовка структурированного JSON для AI-анализа: недельный срез (план vs факт) и полный план со всеми тренировками. Поддерживает запуск из CLI: `php prepare_weekly_analysis.php user_id [week]` (блок на L680).

### `prepareWeeklyAnalysis($userId, $weekNumber = null)` — L18
Собирает данные одной недели: пользователь из `users`, неделя из `training_plan_weeks` (если week не указан — `getCurrentWeekNumber`), план дней из `training_plan_days`, факт из `workout_log` (ручные, JOIN `activity_types`) + `workouts` (автоимпорт, маппинг дат на дни недели); объединяет план/факт по дням, считает базовый compliance и недельную статистику (объём, время, средний пульс, completion_rate). Бросает Exception если нет недели/пользователя. Вызывается StatsController и scripts/weekly_ai_review.php.

### `getCurrentWeekNumber($userId, $db)` — L285
Находит в `training_plan_weeks` неделю, в которую попадает сегодняшняя дата (`BETWEEN start_date AND start_date+6`); если нет — возвращает 1.

### `prepareFullPlanAnalysis($userId)` — L310
Полный срез для адаптации курса: все поля пользователя через `getUserData()`, нормализация preferred_days (старый массив/новый `{run,ofp}` формат), ВСЕ недели+дни+`training_day_exercises`, обёртка в виртуальную «фазу», дата старта плана; все тренировки из `workout_log` (is_completed=1, LIMIT 2000) и `workouts` (LIMIT 2000); раздельная статистика по всем тренировкам и по тренировкам с даты старта плана. Вне файла не вызывается — кандидат в мёртвый код.

## `planrun-backend/query_helpers.php` (218 строк)
Централизованные SQL-хелперы (анти-дублирование запросов).

### `getTotalTrainingDays($db, $userId)` — L16
COUNT дней `training_plan_days` JOIN `training_plan_weeks` без типа 'rest'. Вне файла не вызывается — кандидат в мёртвый код.

### `getCompletedDaysKeys($db, $userId)` — L39
Из `workout_log` (is_completed=1) строит набор ключей `'date-week-day' => true`. Используется ChatContextBuilder и StatsService.

### `getUserCoachAccess($db, $targetUserId, $currentUserId)` — L65
Читает `user_coaches` (user_id+coach_id) и возвращает `{can_edit, can_view}` либо null если связи нет. Используется api_v2 (get_user_by_slug), calendar_access и др.

### `isUserCoach($db, $targetUserId, $currentUserId)` — L94
Булева проверка наличия записи в `user_coaches`.

### `parsePreferredDays($json)` — L114
Декодирует JSON preferred_days и нормализует к `{run: [], ofp: []}` (поддержка старого плоского массива). Вне файла не вызывается — кандидат в мёртвый код.

### `formatPreferredDays($preferredDays)` — L147
Форматирует `{run,ofp}` в русскую строку «Бег: Пн, Ср; ОФП: Вт». Вне файла не вызывается — кандидат в мёртвый код.

### `getWeekDates($db, $userId, $weekNumber)` — L189
Читает start_date недели из `training_plan_weeks`, возвращает `{start: DateTime, end: DateTime(+6 дней)}` или null. Вне файла не вызывается — кандидат в мёртвый код.

## `planrun-backend/register_api.php` (455 строк)
Entry-поинт регистрации (вызывается через api/register_api.php). Обрабатывает 4 операции:
1. GET `?action=validate_field` — валидация поля через `RegisterApiService::validateField()`.
2. POST `action=send_verification_code` — отправка email-кода через `RegisterApiService::sendVerificationCode()` (rate limit по IP; 429 при лимите).
3. POST `register_minimal=1` — минимальная регистрация (email+пароль, username автогенерируется) через `RegistrationService::registerMinimal()` + автологин.
4. POST полная регистрация: валидация ~35 полей (зеркало complete_specialization_api.php: goal_type/training_mode-условные обязательные поля), затем `RegistrationService::registerFull()` (пишет `users`, создаёт план) + автологин в сессию.
Собственный CORS-блок если не `API_CORS_SENT`. Все ответы через `planrunRespondJson()`. Замечание: L352/357 проверяют необъявленную `$targetMarathonDate`.

### `planrunEnsureSessionStarted()` — L37
Гарантирует активную сессию с fallback на sys_temp_dir. Дубликат функции из complete_specialization_api.php.

### `planrunGetRegisterApiService()` — L55
Фабрика: `getDBConnection()` → `new RegisterApiService($db)`; null при недоступной БД.

### `planrunRespondJson($payload, $statusCode = 200)` — L65
Ставит HTTP-код, печатает JSON (JSON_UNESCAPED_UNICODE) и `exit`.

### `planrunAutoLoginRegisteredUser(array $result, $fallbackUsername = '')` — L73
Автологин после регистрации: пишет в `$_SESSION` authenticated/user_id/username/login_time из результата регистрации.

## `planrun-backend/training_utils.php` (96 строк)
Утилиты привязки тренировок к плану и форматирования.

### `findTrainingDay($workoutDate, $userId = null)` — L10
По дате находит неделю плана (через `loadTrainingPlanForUser()`, сравнение start_date..+6 дней) и возвращает `{week_number, day_name, training_date}` или null. Используется ChatContextBuilder и StatsService.

### `linkWorkoutToCalendar($db, $workoutId, $workout, $userId = null)` — L54
Определяет привязку автоматической тренировки к календарю через `findTrainingDay()` (по дате start_time); записей в `workout_log` НЕ создаёт (workout_log только для ручных). Вне файла не вызывается — кандидат в мёртвый код.

### `formatDuration($minutes)` — L84
Форматирует минуты в `H:MM:SS` либо `M:SS`. Вне файла глобальная версия не вызывается (есть отдельные `formatDurationHMS` в plan_normalizer и приватный `formatDurationValue` в WorkoutShareCardService) — кандидат в мёртвый код.

## `planrun-backend/user_functions.php` (240 строк)
Функции работы с пользователями (профиль, кеш, slug).

### `getUserData($userId, $fields = null, $useCache = true)` — L20
SELECT заданных полей из `users` (дефолт — широкий список ~50 полей) с кешем `user_data_{id}[_md5(fields)]` на 30 минут (`Cache::set`); ошибки в `Logger::error`. Основной способ чтения профиля по всему бэкенду.

### `getCurrentUser()` — L85
Текущий пользователь по `$_SESSION['user_id']` с кешем в `$_SESSION['user_cache']` (сбрасывает кеш без role); грузит свежие данные `getUserData(..., useCache=false)`.

### `clearUserCache($userId = null)` — L122
Чистит сессионный кеш и `Cache::invalidate("user_data_{id}*")` (файловый паттерн). Вызывается после любых апдейтов профиля.

### `getCurrentUserId()` — L139
`$_SESSION['user_id'] ?? null`.

### `getUserByTelegramId($telegramId)` — L146
SELECT из `users` по telegram_id (фиксированный список полей). В репо вызовов не найдено (бот живёт отдельно) — кандидат в мёртвый код.

### `getUserActivePlan($userId)` — L168
SELECT из `user_training_plans` WHERE is_active=TRUE. Вне файла не вызывается — кандидат в мёртвый код.

### `getUserTimezone($userId)` — L182
SELECT timezone из `users`; дефолт 'Europe/Moscow'. Используется чат-сервисами и PostWorkoutFollowupService.

### `generateUsernameSlug($username, $excludeUserId = null)` — L200
Генерирует уникальный slug (lowercase, `[^a-z0-9_]`→`_`, дедупликация суффиксом `_N` с циклом проверок в `users`). Используется UserProfileService; логика продублирована в `getUserCalendarUrl()` (calendar_access.php).

## `planrun-backend/workout_types.php` (81 строка)
Справочник типов активности.

### `getActivityTypes($useCache = true)` — L22
SELECT id,name,icon,color из `activity_types` (is_active=TRUE, сортировка), кеш 'activity_types' на 1 час. Вне файла не вызывается — кандидат в мёртвый код.

### `getActivityType($id)` — L60
SELECT * из `activity_types` по id. Используется PostWorkoutFollowupService.

## `planrun-backend/telegram/webhook-proxy.php` (101 строка)
Прокси Telegram-webhook для нескольких ботов (деплоится на VPS в EU/US, т.к. api.telegram.org недоступен напрямую). Поток:
1. Определяет бота по пути URI: `/webhook-proxy/{planrun|hday|gpu-alert|tsd}`; `/webhook-proxy.php` → planrun; неизвестный — 404.
2. Backend-URL каждого бота из env `WEBHOOK_BACKEND_*` (через `proxy_env`) либо захардкоженные дефолты (planrun.ru, alter-vision.ru).
3. Читает raw body (пустое — 400), отвечает Telegram 200 сразу, `fastcgi_finish_request()`.
4. cURL POST тела на backend; для planrun добавляет заголовок `X-Webhook-Proxy-Secret` (env `WEBHOOK_PROXY_SECRET`); не-200 от backend — error_log.

### `proxy_env(string $key, string $default = ''): string` — L13
Читает переменную из getenv либо парсит локальный `.env` рядом с файлом (строки key=value, пропуск комментариев). Локальная замена общего env_loader (файл автономен на VPS).

## `planrun-backend/telegram/set-miniapp-menu-button.php` (79 строк)
CLI-скрипт одноразовой настройки: ставит кнопку меню бота как Telegram Mini App (`setChatMenuButton`, type=web_app → URL из `TELEGRAM_MINIAPP_URL`). Токен из `TELEGRAM_BOT_TOKEN` (.env) либо из `planrun-bot/bot/config.php`; поддержка `TELEGRAM_PROXY` (включая socks5); выводит результат в stdout/stderr, exit-код 0/1. Функций нет.

## `planrun-backend/telegram/set-all-webhooks.php` (63 строки)
CLI-скрипт: регистрирует webhook (`setWebhook`, drop_pending_updates) для всех 4 ботов на `https://tg.planrun.ru:8443/webhook-proxy/<bot>`. Токены planrun/tsd захардкожены в файле (!), hday/gpu-alert подгружаются из соседнего проекта altervision; поддержка `TELEGRAM_PROXY`. Функций нет.

## `scripts/bump-apk-version.js` (81 строка)
Node-скрипт инкремента версии Android APK (часть npm-скрипта `apk`): вычисляет максимум versionCode из build.gradle, public/version.json и файлов `downloads/planrun-X.Y.apk`, инкрементирует, перезаписывает `android/app/build.gradle` (versionCode/versionName) и `public/version.json` (манифест обновления: download_url, force_update из env `PLANRUN_FORCE_UPDATE`). Флаг `--dry-run`.

### `versionNameToCode(versionName)` — L14
'X.Y' → число X*10+Y (0 если формат не совпал).

### `codeToVersionName(versionCode)` — L20
Обратное преобразование: code → 'X.Y'.

### `readGradleVersionCode()` — L24
Парсит versionCode/versionName из build.gradle регэкспами, возвращает максимум обоих.

### `readPublicVersionCode()` — L35
Читает version_code из public/version.json (0 при ошибке).

### `readDownloadsVersionCode()` — L45
Сканирует каталог downloads/, извлекает версии из имён `planrun-X.Y.apk`, возвращает максимум.

## `scripts/generate-docs.js` (114 строк)
Node-скрипт генерации `docs/api-reference.json`: обходит src/, api/, planrun-backend/ и регэкспами извлекает имена функций/компонентов/классов. В package.json не подключён, выходной файл в репо отсутствует — разовый/неиспользуемый инструмент.

### `extractJsFunctions(content, filePath)` — L18
Регэксп-извлечение функций (`function name`/`const name = ... =>`) и React-компонентов (имена с заглавной) из JS/JSX.

### `extractPhpFunctions(content)` — L45
Регэксп-извлечение `function name(` и `class Name` из PHP.

### `extractFromFile(filePath)` — L61
Читает файл, по расширению выбирает extractor, возвращает `{path, items}` или null.

### `walkDir(dir, files = [])` — L76
Рекурсивный обход каталога с EXCLUDE (node_modules, vendor, dist, .git, build), собирает .js/.jsx/.php.

### `main()` — L90
Собирает файлы из src/api/planrun-backend, группирует в `{frontend, api, backend}` и пишет `docs/api-reference.json`.

## `scripts/publish-apk.js` (36 строк)
Node-скрипт публикации собранного APK (часть npm-скрипта `apk`): проверяет наличие `public/version.json` и release-APK, копирует `android/app/build/outputs/apk/release/app-release.apk` в `test.apk`, `public/planrun.apk` и архив `downloads/planrun-<version>.apk`. Функций нет.
