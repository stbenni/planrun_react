# Frontend core: api, stores, services, workers — справочник функций

Все «action» в API-модулях — это параметр `?action=...` эндпоинта `/api/api_wrapper.php` (прокси к `api_v2.php`), если явно не указан другой URL.

## `src/api/adminApi.js` (60 строк)
Тонкие обёртки админских эндпоинтов: каждая функция принимает `client` (ApiClient) и вызывает `client.request(action, ...)`.

### `getAdminUsers(client, params)` — L1
Список пользователей для админки. GET `admin_list_users`; опционально передаёт `page`, `per_page`, `search` (собирает через URLSearchParams).

### `getAdminUser(client, userId)` — L9
Один пользователь по ID. GET `admin_get_user` с `user_id`.

### `updateAdminUser(client, payload)` — L13
Обновление пользователя (роль, email; payload должен содержать csrf_token). POST `admin_update_user`.

### `deleteUser(client, payload)` — L17
Удаление пользователя (payload: user_id + csrf_token). POST `delete_user`.

### `getAdminSettings(client)` — L21
Настройки сайта для админки. GET `admin_get_settings`.

### `updateAdminSettings(client, payload)` — L25
Сохранение настроек сайта. POST `admin_update_settings`.

### `getAdminNotificationTemplates(client)` — L29
Шаблоны уведомлений. GET `admin_get_notification_templates`.

### `updateAdminNotificationTemplate(client, payload)` — L33
Сохранить override шаблона уведомления. POST `admin_update_notification_template`.

### `resetAdminNotificationTemplate(client, payload)` — L37
Сбросить override шаблона. POST `admin_reset_notification_template`.

### `getSiteSettings(client)` — L41
Публичные настройки сайта (maintenance_mode, registration_enabled и т.д.), без авторизации. GET `get_site_settings`.

### `getAiPlanMetrics(client, params)` — L46
Агрегаты по событиям AI-генерации планов (observability, admin only). GET `admin_ai_plan_metrics`; опционально `hours`.

### `getAiPlanRecentEvents(client, params)` — L52
Последние события генерации плана. GET `admin_ai_plan_events`; опциональные фильтры `limit`, `user_id`, `cohort`, `status`, `since`.

## `src/api/ApiClient.js` (1736 строк)
Универсальный API-клиент (web + Capacitor native). Хранит/обновляет JWT-токены, выполняет fetch к `/api/api_wrapper.php`, обрабатывает 401/403/429, ретраи после refresh. Подавляющее большинство публичных методов — тонкие делегаты в модули `authApi`/`planApi`/`workoutApi`/`statsApi`/`adminApi`/`chatApi`/`coachApi`.

### `getExpFromToken(token)` — L155
Модульный хелпер (не экспортируется): декодирует payload JWT (base64url → JSON) и возвращает `exp` (unix-секунды) или null.

### `ApiClient.constructor(baseUrl?)` — L168
Вычисляет `baseUrl`: явный аргумент → `VITE_API_BASE_URL` → для native-origin (`capacitor://`/`file://`) fallback `https://planrun.ru/api` → для web `/api`. Инициализирует token/refreshToken/onTokenExpired/isRefreshing.

### `ApiClient::getOrCreateDeviceId()` — L197
Делегат в `TokenStorageService.getOrCreateDeviceId()` — стабильный UUID устройства.

### `ApiClient::setToken(token, refreshToken?)` — L201
Записывает токены в память и хранилища: native — localStorage + `TokenStorageService.saveTokens` (await, Preferences), при null — очистка; web — localStorage; React Native — AsyncStorage (legacy-ветка).

### `ApiClient::getTokens()` — L266
Синхронно возвращает текущие in-memory `{ accessToken, refreshToken }` (для синхронизации в PinAuthService после unlock).

### `ApiClient::getToken()` — L270
Возвращает access token: память → (native) TokenStorageService → localStorage → BiometricService (web+Capacitor edge) → AsyncStorage. Если токен истекает в течение 60 сек (PROACTIVE_REFRESH_MS) — проактивно вызывает `refreshAccessToken()`.

### `ApiClient::getRefreshToken()` — L343
Возвращает refresh token из памяти, TokenStorageService (native, с зеркалированием в localStorage), localStorage или AsyncStorage.

### `ApiClient::refreshAccessToken()` — L407
Обновляет access token: POST `refresh_token` (body: refresh_token, device_id). Дедуплицирует параллельные вызовы через `refreshPromise`. При истёкшем/отозванном refresh — `setToken(null)` и вызов `onTokenExpired`. После успеха синхронизирует токены в BiometricService (если биометрия включена). Сетевые ошибки помечает `isNetworkError` и не разлогинивает.

### `ApiClient::request(action, params, method, extraUrlParams)` — L519
Базовый метод запросов: action в URL `api_wrapper.php?action=...`, GET-параметры в URL, POST — JSON-body, Bearer-токен в заголовке. Таймауты: 15с обычный, 5с `check_auth`, 120с `sync_workouts`. На 401 — refresh + повтор запроса; 403/429 → ApiError (FORBIDDEN/RATE_LIMITED с retry_after); диагностика не-JSON ответов (REDIRECT_ERROR/HTML_RESPONSE/PARSE_ERROR); `data.error`/`success:false` → ApiError (кроме `check_plan_status`). Возвращает `data.data || data`.

### `ApiClient::requestBlob(action, params, method, extraUrlParams)` — L772
То же, но для бинарных ответов (карты/шер-карточки): возвращает `{ blob, contentType, provider }` (заголовок `x-planrun-map-provider`) или `{ empty: true }` на 204. 401 → refresh + повтор; JSON-ответ трактуется как ошибка. Таймаут 30с для `generate_workout_share_card`.

### `ApiClient::login(username, password, useJwt)` — L899
Делегат `authApi.login`: POST `login` (сессия или JWT).

### `ApiClient::loginWithJwt(username, password)` — L908
Делегат `authApi.loginWithJwt`: POST `login` c `use_jwt: true`.

### `ApiClient::telegramMiniAppAuth(initData, timezone)` — L917
Делегат `authApi.telegramMiniAppAuth`: POST `telegram_miniapp_auth`.

### `ApiClient::logout()` — L924
Делегат `authApi.logout`: POST `logout` + очистка токенов.

### `ApiClient::requestResetPassword(email)` — L933
Делегат `authApi.requestResetPassword`: POST `request_password_reset`.

### `ApiClient::confirmResetPassword(token, newPassword)` — L942
Делегат `authApi.confirmResetPassword`: POST `confirm_password_reset`.

### `ApiClient::sendVerificationCode(email)` — L949
Делегат `authApi.sendVerificationCode`: POST `/api/register_api.php` (action send_verification_code).

### `ApiClient::registerMinimal({username,email,password,verification_code,timezone})` — L956
Делегат `authApi.registerMinimal`: POST `/api/register_api.php`, минимальная регистрация + автологин.

### `ApiClient::register(userData)` — L963
Делегат `authApi.register`: POST `/api/register_api.php` (полная форма).

### `ApiClient::assessGoal(formData)` — L967
Оценка реалистичности цели при онбординге. POST `assess_goal`.

### `ApiClient::completeSpecialization(payload)` — L974
Делегат `authApi.completeSpecialization`: POST `/api/complete_specialization_api.php`.

### `ApiClient::validateField(field, value)` — L981
Делегат `authApi.validateField`: GET `/api/register_api.php?action=validate_field`.

### `ApiClient::getCurrentUser(opts)` — L989
GET `check_auth`; нормализует ответ в объект пользователя (user_id, username, role, onboarding_completed, training_mode, цель/гонка и т.д.) или null. При `opts.throwOnNetworkError` пробрасывает NETWORK_ERROR/TIMEOUT, остальные ошибки гасит (возврат null).

### `ApiClient::_viewParams(viewContext)` — L1059
Внутренний хелпер: контекст просмотра чужого профиля `{ view:'user', slug, token? }` для GET/POST-параметров.

### `ApiClient::getUserBySlug(slug, token)` — L1066
Делегат `workoutApi.getUserBySlug`: GET `get_user_by_slug`.

### `ApiClient::getPlan(userId, viewContext)` — L1070
Делегат `planApi.getPlan`: GET `load`.

### `ApiClient::savePlan(planData)` — L1074
Делегат `planApi.savePlan`: POST `save` (план как JSON-строка).

### `ApiClient::getDay(date, viewContext)` — L1080
Делегат `workoutApi.getDay`: GET `get_day`.

### `ApiClient::getDays(dates, viewContext)` — L1084
Делегат `workoutApi.getDays`: GET `get_days` (batch-префетч недели).

### `ApiClient::saveResult(data, viewContext)` — L1092
Делегат `workoutApi.saveResult`: POST `save_result` (с CSRF).

### `ApiClient::getResult(date, viewContext)` — L1096
Делегат `workoutApi.getResult`: GET `get_result`.

### `ApiClient::uploadWorkout(file, opts)` — L1105
Делегат `workoutApi.uploadWorkout`: multipart POST `upload_workout` (GPX/TCX).

### `ApiClient::getAllResults(viewContext)` — L1109
Делегат `workoutApi.getAllResults`: GET `get_all_results`.

### `ApiClient::reset(date)` — L1113
Делегат `workoutApi.resetWorkout`: POST `reset` (снять отметку выполнения).

### `ApiClient::getStats(viewContext)` — L1119
Делегат `statsApi.getStats`: GET `stats`.

### `ApiClient::getAllWorkoutsSummary(viewContext)` — L1123
Делегат `statsApi.getAllWorkoutsSummary`: GET `get_all_workouts_summary`.

### `ApiClient::getAllWorkoutsList(viewContext, limit)` — L1132
Делегат `statsApi.getAllWorkoutsList`: GET `get_all_workouts_list` (каждая тренировка отдельно, limit по умолчанию 500).

### `ApiClient::getExecutedDates(weeks)` — L1140
GET `get_executed_dates` — карта дат → категории executed_exercises (подсветка ОФП/СБУ в календаре).

### `ApiClient::getLatestProactiveMessage(type, hours)` — L1148
GET `get_latest_proactive_message` — последний проактивный AI-инсайт (daily_briefing и т.п.) для hero-карточки дашборда.

### `ApiClient::getRecentWorkoutAnalyses(limit)` — L1156
GET `get_recent_workout_analyses` — последние AI-разборы тренировок (Coach Insights Feed).

### `ApiClient::getPersonalRecords()` — L1163
GET `get_personal_records` — личные рекорды 5K/10K/half/marathon за 52 недели.

### `ApiClient::getRacePrediction(viewContext)` — L1167
Делегат `statsApi.getRacePrediction`: GET `race_prediction`.

### `ApiClient::getTrainingLoad(viewContext, days)` — L1171
Делегат `statsApi.getTrainingLoad`: GET `training_load` (по умолчанию 90 дней).

### `ApiClient::getIntegrationOAuthUrl(provider, extra)` — L1177
Делегат `statsApi.getIntegrationOAuthUrl`: GET `integration_oauth_url`.

### `ApiClient::syncWorkouts(provider)` — L1181
Делегат `statsApi.syncWorkouts`: POST `sync_workouts` (с CSRF; таймаут 120с в request).

### `ApiClient::getIntegrationsStatus()` — L1185
Делегат `statsApi.getIntegrationsStatus`: GET `integrations_status`.

### `ApiClient::unlinkIntegration(provider)` — L1189
Делегат `statsApi.unlinkIntegration`: POST `unlink_integration` (с CSRF).

### `ApiClient::setSuuntoMirror(enabled)` — L1193
Делегат `statsApi.setSuuntoMirror`: POST `set_suunto_mirror` (с CSRF).

### `ApiClient::importHealthConnectWorkouts(workouts)` — L1197
Делегат `statsApi.importHealthConnectWorkouts`: POST `health_connect_import` (с CSRF).

### `ApiClient::getStravaTokenError()` — L1201
Делегат `statsApi.getStravaTokenError`: GET `strava_token_error`.

### `ApiClient::getWorkoutTimeline(workoutId)` — L1210
Делегат `statsApi.getWorkoutTimeline`: GET `get_workout_timeline` (пульс/темп по времени).

### `ApiClient::getWorkoutShareMap(workoutId, options)` — L1214
Делегат `statsApi.getWorkoutShareMap`: GET `get_workout_share_map` через requestBlob (PNG карты маршрута).

### `ApiClient::getWorkoutShareCard(workoutId, options)` — L1218
Делегат `statsApi.getWorkoutShareCard`: GET `generate_workout_share_card` через requestBlob.

### `ApiClient::storeWorkoutShareCard(workoutId, payload)` — L1222
Делегат `statsApi.storeWorkoutShareCard`: POST `store_workout_share_card` (с CSRF).

### `ApiClient::regeneratePlan()` — L1226
Делегат `planApi.regeneratePlan`: POST `regenerate_plan_with_progress`.

### `ApiClient::recalculatePlan(reason)` — L1230
Делегат `planApi.recalculatePlan`: POST `recalculate_plan`.

### `ApiClient::generateNextPlan(goals)` — L1234
Делегат `planApi.generateNextPlan`: POST `generate_next_plan`.

### `ApiClient::submitPlanReadinessCheck(payload)` — L1238
Делегат `planApi.submitPlanReadinessCheck`: POST `submit_plan_readiness_check` (check-in самочувствия перед генерацией).

### `ApiClient::checkPlanStatus(userId)` — L1245
Делегат `planApi.checkPlanStatus`: GET `check_plan_status` (ошибка допускается в теле ответа).

### `ApiClient::getDataVersion()` — L1253
GET `data_version` — лёгкий polling версии данных `{ version, workout_version, plan_version }`.

### `ApiClient::clearPlan()` — L1261
Делегат `planApi.clearPlan`: POST `clear_plan` (результаты сохраняются).

### `ApiClient::deleteWeek(weekNumber)` — L1267
Делегат `workoutApi.deleteWeek`: POST `delete_week`.

### `ApiClient::addWeek(weekData)` — L1271
Делегат `workoutApi.addWeek`: POST `add_week`.

### `ApiClient::addTrainingDayByDate(data, viewContext)` — L1279
Делегат `workoutApi.addTrainingDayByDate`: POST `add_training_day_by_date` (календарная модель).

### `ApiClient::deleteWorkout(workoutId, isManual, viewContext)` — L1288
Делегат `workoutApi.deleteWorkout`: POST `delete_workout` (с CSRF).

### `ApiClient::deleteTrainingDay(dayId, viewContext)` — L1296
Делегат `workoutApi.deleteTrainingDay`: POST `delete_training_day` (с CSRF).

### `ApiClient::copyDay(sourceDate, targetDate, viewContext)` — L1300
Делегат `workoutApi.copyDay`: POST `copy_day` (с CSRF).

### `ApiClient::copyWeek(sourceWeekId, targetStartDate, viewContext)` — L1304
Делегат `workoutApi.copyWeek`: POST `copy_week` (с CSRF).

### `ApiClient::getDayNotes(date, viewContext)` — L1310
Делегат `workoutApi.getDayNotes`: GET `get_day_notes`.

### `ApiClient::saveDayNote(date, content, noteId, viewContext)` — L1314
Делегат `workoutApi.saveDayNote`: POST `save_day_note` (с CSRF).

### `ApiClient::deleteDayNote(noteId, viewContext)` — L1318
Делегат `workoutApi.deleteDayNote`: POST `delete_day_note` (с CSRF).

### `ApiClient::getWeekNotes(weekStart, viewContext)` — L1322
Делегат `workoutApi.getWeekNotes`: GET `get_week_notes`.

### `ApiClient::saveWeekNote(weekStart, content, noteId, viewContext)` — L1326
Делегат `workoutApi.saveWeekNote`: POST `save_week_note` (с CSRF).

### `ApiClient::deleteWeekNote(noteId, viewContext)` — L1330
Делегат `workoutApi.deleteWeekNote`: POST `delete_week_note` (с CSRF).

### `ApiClient::getNoteCounts(startDate, endDate, viewContext)` — L1334
Делегат `workoutApi.getNoteCounts`: GET `get_note_counts`.

### `ApiClient::getPlanNotifications(options)` — L1340
Делегат `workoutApi.getPlanNotifications`: GET `get_plan_notifications` (include_read/limit в URL).

### `ApiClient::markPlanNotificationRead(notificationId)` — L1344
Делегат `workoutApi.markPlanNotificationRead`: POST `mark_plan_notification_read`.

### `ApiClient::markAllPlanNotificationsRead()` — L1348
Делегат `workoutApi.markAllPlanNotificationsRead`: POST `mark_plan_notification_read` с `{ all: true }`.

### `ApiClient::updateTrainingDay(dayId, data, viewContext)` — L1357
Делегат `workoutApi.updateTrainingDay`: POST `update_training_day` (с CSRF).

### `ApiClient::getAdminUsers(params)` — L1364
Делегат `adminApi.getAdminUsers`: GET `admin_list_users`.

### `ApiClient::getAdminUser(userId)` — L1369
Делегат `adminApi.getAdminUser`: GET `admin_get_user`.

### `ApiClient::updateAdminUser(payload)` — L1374
Делегат `adminApi.updateAdminUser`: POST `admin_update_user`.

### `ApiClient::deleteUser(payload)` — L1379
Делегат `adminApi.deleteUser`: POST `delete_user`.

### `ApiClient::getAdminSettings()` — L1384
Делегат `adminApi.getAdminSettings`: GET `admin_get_settings`.

### `ApiClient::updateAdminSettings(payload)` — L1389
Делегат `adminApi.updateAdminSettings`: POST `admin_update_settings`.

### `ApiClient::getAdminNotificationTemplates()` — L1394
Делегат `adminApi.getAdminNotificationTemplates`: GET `admin_get_notification_templates`.

### `ApiClient::updateAdminNotificationTemplate(payload)` — L1399
Делегат `adminApi.updateAdminNotificationTemplate`: POST `admin_update_notification_template`.

### `ApiClient::resetAdminNotificationTemplate(payload)` — L1404
Делегат `adminApi.resetAdminNotificationTemplate`: POST `admin_reset_notification_template`.

### `ApiClient::getAiPlanMetrics(params)` — L1410
Делегат `adminApi.getAiPlanMetrics`: GET `admin_ai_plan_metrics`.

### `ApiClient::getAiPlanRecentEvents(params)` — L1415
Делегат `adminApi.getAiPlanRecentEvents`: GET `admin_ai_plan_events`.

### `ApiClient::getSiteSettings()` — L1423
Делегат `adminApi.getSiteSettings`: GET `get_site_settings` (без авторизации).

### `ApiClient::chatGetMessages(type, limit, offset)` — L1435
Делегат `chatApi.chatGetMessages`: GET `chat_get_messages` ('ai' | 'admin').

### `ApiClient::chatSendMessage(content, attachment)` — L1443
Делегат `chatApi.chatSendMessage`: POST `chat_send_message` (без стриминга).

### `ApiClient::chatSendMessageStream(content, onChunk, opts)` — L1453
Делегат `chatApi.chatSendMessageStream`: стримовый POST `chat_send_message_stream` (NDJSON-чанки, колбэки onFirstChunk/onPlanUpdated/onToolExecuting и т.д.).

### `ApiClient::chatSendMessageToAdmin(content, attachment)` — L1461
Делегат `chatApi.chatSendMessageToAdmin`: POST `chat_send_message_to_admin`.

### `ApiClient::uploadChatMedia(file)` — L1465
Делегат `chatApi.uploadChatMedia`: multipart POST `chat_upload_media`.

### `ApiClient::chatGetDirectDialogs()` — L1472
Делегат `chatApi.chatGetDirectDialogs`: GET `chat_get_direct_dialogs`.

### `ApiClient::chatGetDirectMessages(targetUserId, limit, offset)` — L1482
Делегат `chatApi.chatGetDirectMessages`: GET `chat_get_direct_messages`.

### `ApiClient::chatSendMessageToUser(targetUserId, content, attachment)` — L1491
Делегат `chatApi.chatSendMessageToUser`: POST `chat_send_message_to_user`.

### `ApiClient::chatClearDirectDialog(targetUserId)` — L1499
Делегат `chatApi.chatClearDirectDialog`: POST `chat_clear_direct_dialog`.

### `ApiClient::chatMarkRead(conversationId)` — L1507
Делегат `chatApi.chatMarkRead`: POST `chat_mark_read`.

### `ApiClient::chatClearAi()` — L1514
Делегат `chatApi.chatClearAi`: POST `chat_clear_ai`.

### `ApiClient::chatClearAdmin()` — L1521
Делегат `chatApi.chatClearAdmin`: POST `chat_clear_admin`.

### `ApiClient::chatMarkAllRead()` — L1528
Делегат `chatApi.chatMarkAllRead`: POST `chat_mark_all_read`.

### `ApiClient::chatAdminMarkAllRead()` — L1535
Делегат `chatApi.chatAdminMarkAllRead`: POST `chat_admin_mark_all_read`.

### `ApiClient::chatAdminSendMessage(userId, content)` — L1544
Делегат `chatApi.chatAdminSendMessage`: POST `chat_admin_send_message`.

### `ApiClient::getAdminChatUsers()` — L1551
Делегат `chatApi.getAdminChatUsers`: GET `chat_admin_chat_users`.

### `ApiClient::chatAdminGetMessages(userId, limit, offset)` — L1561
Делегат `chatApi.chatAdminGetMessages`: GET `chat_admin_get_messages`.

### `ApiClient::chatAdminMarkConversationRead(userId)` — L1569
Делегат `chatApi.chatAdminMarkConversationRead`: POST `chat_admin_mark_conversation_read`.

### `ApiClient::chatAddAIMessage(userId, content)` — L1578
Делегат `chatApi.chatAddAIMessage`: POST `chat_add_ai_message` (досыл сообщения от имени AI, admin only).

### `ApiClient::chatAdminGetUnreadNotifications(limit)` — L1586
Делегат `chatApi.chatAdminGetUnreadNotifications`: GET `chat_admin_unread_notifications`.

### `ApiClient::chatAdminBroadcast(content, userIds)` — L1595
Делегат `chatApi.chatAdminBroadcast`: POST `chat_admin_broadcast` (рассылка всем или выбранным).

### `ApiClient::getNotificationsDismissed()` — L1602
Делегат `chatApi.getNotificationsDismissed`: GET `notifications_dismissed`.

### `ApiClient::dismissNotification(notificationId)` — L1610
Делегат `chatApi.dismissNotification`: POST `notifications_dismiss`.

### `ApiClient::listCoaches(params)` — L1616
Делегат `coachApi.listCoaches`: GET `list_coaches`.

### `ApiClient::requestCoach(coachId, message)` — L1620
Делегат `coachApi.requestCoach`: POST `request_coach`.

### `ApiClient::getCoachRequests(params)` — L1624
Делегат `coachApi.getCoachRequests`: GET `coach_requests`.

### `ApiClient::acceptCoachRequest(requestId)` — L1628
Делегат `coachApi.acceptCoachRequest`: POST `accept_coach_request`.

### `ApiClient::rejectCoachRequest(requestId)` — L1632
Делегат `coachApi.rejectCoachRequest`: POST `reject_coach_request`.

### `ApiClient::getMyCoaches()` — L1636
Делегат `coachApi.getMyCoaches`: GET `get_my_coaches`.

### `ApiClient::removeCoach({coachId, athleteId})` — L1640
Делегат `coachApi.removeCoach`: POST `remove_coach`.

### `ApiClient::applyCoach(data)` — L1644
Делегат `coachApi.applyCoach`: POST `apply_coach` (заявка стать тренером).

### `ApiClient::getCoachAthletes()` — L1648
Делегат `coachApi.getCoachAthletes`: GET `coach_athletes`.

### `ApiClient::getAthleteDetails(athleteId, weekStart)` — L1652
Делегат `coachApi.getAthleteDetails`: GET `get_athlete_details`.

### `ApiClient::getCoachPricing(coachId)` — L1656
Делегат `coachApi.getCoachPricing`: GET `get_coach_pricing`.

### `ApiClient::updateCoachPricing(pricing, pricesOnRequest)` — L1660
Делегат `coachApi.updateCoachPricing`: POST `update_coach_pricing`.

### `ApiClient::getMyCoachProfile()` — L1664
Делегат `coachApi.getMyCoachProfile`: GET `get_my_coach_profile`.

### `ApiClient::updateCoachProfile(data)` — L1668
Делегат `coachApi.updateCoachProfile`: POST `update_coach_profile`.

### `ApiClient::getCoachGroups()` — L1673
Делегат `coachApi.getCoachGroups`: GET `get_coach_groups`.

### `ApiClient::saveCoachGroup(data)` — L1677
Делегат `coachApi.saveCoachGroup`: POST `save_coach_group`.

### `ApiClient::deleteCoachGroup(groupId)` — L1681
Делегат `coachApi.deleteCoachGroup`: POST `delete_coach_group`.

### `ApiClient::getGroupMembers(groupId)` — L1685
Делегат `coachApi.getGroupMembers`: GET `get_group_members`.

### `ApiClient::updateGroupMembers(groupId, userIds)` — L1689
Делегат `coachApi.updateGroupMembers`: POST `update_group_members`.

### `ApiClient::getAthleteGroups(userId)` — L1693
Делегат `coachApi.getAthleteGroups`: GET `get_athlete_groups`.

### `ApiClient::getCoachApplications(params)` — L1697
Делегат `coachApi.getCoachApplications`: GET `admin_coach_applications`.

### `ApiClient::approveCoachApplication(applicationId)` — L1701
Делегат `coachApi.approveCoachApplication`: POST `admin_approve_coach`.

### `ApiClient::rejectCoachApplication(applicationId)` — L1705
Делегат `coachApi.rejectCoachApplication`: POST `admin_reject_coach`.

### `ApiClient::listWorkoutTemplates()` — L1710
Делегат `coachApi.listWorkoutTemplates`: GET `list_workout_templates`.

### `ApiClient::listExerciseLibrary()` — L1714
Делегат `coachApi.listExerciseLibrary`: GET `list_exercise_library`.

### `ApiClient::saveWorkoutTemplate(data)` — L1718
Делегат `coachApi.saveWorkoutTemplate`: POST `save_workout_template` (с CSRF).

### `ApiClient::deleteWorkoutTemplate(templateId)` — L1722
Делегат `coachApi.deleteWorkoutTemplate`: POST `delete_workout_template` (с CSRF).

### `ApiClient::bulkAssignTraining(payload)` — L1726
Делегат `coachApi.bulkAssignTraining`: POST `bulk_assign_training` (с CSRF).

### `ApiClient::getCoachEvents(hoursBack)` — L1730
Делегат `coachApi.getCoachEvents`: GET `coach_events` (по умолчанию 48 ч).

## `src/api/apiError.js` (47 строк)
Класс ошибки API и хелперы построения ошибок с retry_after.

### `class ApiError` / `constructor({code, message, attempts_left, status, retry_after})` — L1
Расширение Error с полями code, attempts_left, status, retry_after — единый формат ошибок API во всём фронте.

### `extractRetryAfter(response, data, fallbackMessage)` — L13
Извлекает секунды до повтора: заголовок `Retry-After` → `data.retry_after` → парсинг текста сообщения «через N сек». Возвращает число или undefined.

### `buildApiError({response, data, code, message, attempts_left})` — L33
Фабрика ApiError: берёт сообщение из data.error/data.message, status из response, retry_after через extractRetryAfter.

## `src/api/authApi.js` (525 строк)
Логин/логаут/регистрация/сброс пароля. Работает напрямую через fetch (не через `client.request`), т.к. часть запросов выполняется без токена.

### `fetchWithTimeout(url, options, timeoutMs)` — L9
Внутренний fetch с AbortController-таймаутом (по умолчанию 15с).

### `fetchJson(url, options, timeoutMs)` — L23
Внутренний: fetchWithTimeout + парсинг JSON (на ошибке парсинга — `{}`); возвращает `{ response, data }`.

### `applySessionTokens(client, accessToken, refreshToken)` — L29
Внутренний: вызывает `client.setToken`, но ждёт максимум 2.5с (Promise.race) — персист в native storage не должен блокировать навигацию.

### `getAuthWrapperUrl(baseUrl, action)` — L48
Внутренний: строит URL `api_wrapper.php?action=...`.

### `login(client, username, password, useJwt)` — L53
Сессионный вход: POST `login` (credentials: include). На native или при useJwt — делегирует loginWithJwt. При успехе чистит JWT из localStorage (на web сессия через cookie) и получает пользователя через `client.getCurrentUser()`. Ошибка → ApiError LOGIN_FAILED (с retry_after).

### `loginWithJwt(client, username, password)` — L109
JWT-вход: POST `login` с `use_jwt: true` и device_id (получение device_id ограничено 3с). При успехе сохраняет токены через applySessionTokens, возвращает `{ user, access_token, refresh_token }`.

### `telegramMiniAppAuth(client, initData, timezone)` — L171
Вход/авторегистрация по подписанному Telegram initData: POST `telegram_miniapp_auth` (init_data, device_id, timezone). Сохраняет токены; возвращает user + `is_new`.

### `logout(client)` — L226
POST `logout` (с refresh_token в body или cookie-сессией; таймаут 5с, ошибки гасятся). В finally всегда `client.setToken(null, null)` — очистка хранилищ.

### `requestResetPassword(client, email)` — L247
POST `request_password_reset`. Возвращает `{ success, sent, message, email }`; 429 → RATE_LIMITED.

### `confirmResetPassword(client, token, newPassword)` — L272
POST `confirm_password_reset` с токеном из письма и новым паролем.

### `sendVerificationCode(client, email)` — L295
POST `/api/register_api.php` body `{ action: 'send_verification_code', email }` — код подтверждения на email перед регистрацией.

### `registerMinimal(client, {email, password, verification_code, timezone})` — L315
Минимальная регистрация: POST `/api/register_api.php` (`register_minimal: true`, на native — `use_jwt` + device_id; username генерируется на бэке). После успеха: токены из ответа → applySessionTokens; иначе на native — loginWithJwt по email; на web — getCurrentUser. Возвращает user + plan_message.

### `register(client, userData)` — L420
Полная регистрация: POST `/api/register_api.php` (credentials: include). После успеха getCurrentUser.

### `completeSpecialization(client, payload)` — L456
Второй этап онбординга: POST `/api/complete_specialization_api.php` (web — cookie, native — Bearer). Возвращает `{ plan_message, onboarding_completed }`.

### `validateField(client, field, value)` — L498
GET `/api/register_api.php?action=validate_field&field=&value=` — live-валидация поля регистрации; при сбое возвращает `{ valid: false }`.

## `src/api/chatApi.js` (241 строка)
Чат: AI, админ, direct-диалоги, уведомления. Большинство — обёртки `client.request`.

### `chatGetMessages(client, type, limit, offset)` — L4
GET `chat_get_messages` (type: 'ai' | 'admin'; в request для этого action ставится cache: no-store).

### `uploadChatMedia(client, file)` — L11
Загрузка фото-вложения: GET `get_csrf_token` → multipart POST `api_wrapper.php?action=chat_upload_media` (Bearer; credentials omit на native). Возвращает дескриптор attachment `{ kind, file, w, h }`.

### `chatSendMessage(client, content, attachment)` — L35
POST `chat_send_message` — сообщение AI без стриминга (content триммится, attachment опционален).

### `chatSendMessageStream(client, content, onChunk, opts)` — L41
Стриминг ответа AI: POST `chat_send_message_stream` (fetch + ReadableStream, NDJSON-строки). Колбэки: onChunk/onFirstChunk (текст), onToolExecuting, onPlanUpdated, onPlanRecalculating, onPlanGeneratingNext (сигнальные поля). Таймаут 180с + внешний AbortSignal. Возвращает полный собранный текст.

### `chatSendMessageToAdmin(client, content, attachment)` — L155
POST `chat_send_message_to_admin` — сообщение пользователя администрации.

### `chatGetDirectDialogs(client)` — L161
GET `chat_get_direct_dialogs` — список собеседников; нормализует в массив `res.users`.

### `chatGetDirectMessages(client, targetUserId, limit, offset)` — L166
GET `chat_get_direct_messages` — переписка с конкретным пользователем (no-store в request).

### `chatSendMessageToUser(client, targetUserId, content, attachment)` — L170
POST `chat_send_message_to_user` — direct-сообщение от своего имени.

### `chatClearDirectDialog(client, targetUserId)` — L176
POST `chat_clear_direct_dialog` — очистить direct-диалог.

### `chatMarkRead(client, conversationId)` — L180
POST `chat_mark_read` — отметить сообщения беседы прочитанными.

### `chatClearAi(client)` — L184
POST `chat_clear_ai` — очистить чат с AI.

### `chatClearAdmin(client)` — L188
POST `chat_clear_admin` — очистить чат с администрацией (user-side).

### `chatMarkAllRead(client)` — L192
POST `chat_mark_all_read` — все чаты прочитаны.

### `chatAdminMarkAllRead(client)` — L196
POST `chat_admin_mark_all_read` — админ: все входящие прочитаны.

### `chatAdminSendMessage(client, userId, content)` — L200
POST `chat_admin_send_message` — админ пишет пользователю.

### `getAdminChatUsers(client)` — L204
GET `chat_admin_chat_users` — список пользователей, писавших в admin-чат (нормализует `res.users`).

### `chatAdminGetMessages(client, userId, limit, offset)` — L209
GET `chat_admin_get_messages` — сообщения пользователя в admin-чате.

### `chatAdminMarkConversationRead(client, userId)` — L213
POST `chat_admin_mark_conversation_read` — отметить диалог с пользователем прочитанным.

### `chatAddAIMessage(client, userId, content)` — L217
POST `chat_add_ai_message` — досыл сообщения от имени AI конкретному пользователю (admin only).

### `chatAdminGetUnreadNotifications(client, limit)` — L221
GET `chat_admin_unread_notifications` — непрочитанные сообщения от пользователей (нормализует `res.messages`).

### `chatAdminBroadcast(client, content, userIds)` — L226
POST `chat_admin_broadcast` — рассылка; user_ids опционально (иначе всем).

### `getNotificationsDismissed(client)` — L234
GET `notifications_dismissed` — список закрытых уведомлений (синхронизация между устройствами; нормализует `res.dismissed`).

### `dismissNotification(client, notificationId)` — L239
POST `notifications_dismiss` — закрыть уведомление по строковому id (например "chat_123").

## `src/api/coachApi.js` (133 строки)
Тренерский функционал: поиск тренеров, заявки, атлеты, группы, шаблоны, bulk-assign.

### `listCoaches(client, params)` — L1
GET `list_coaches` — каталог тренеров.

### `requestCoach(client, coachId, message)` — L5
POST `request_coach` — заявка атлета тренеру.

### `getCoachRequests(client, params)` — L9
GET `coach_requests` — входящие заявки (фильтр status и т.д.).

### `acceptCoachRequest(client, requestId)` — L13
POST `accept_coach_request`.

### `rejectCoachRequest(client, requestId)` — L17
POST `reject_coach_request`.

### `getMyCoaches(client)` — L21
GET `get_my_coaches` — тренеры текущего атлета.

### `removeCoach(client, {coachId, athleteId})` — L25
POST `remove_coach` — разрыв связи (с любой стороны: coach_id или athlete_id).

### `applyCoach(client, data)` — L32
POST `apply_coach` — заявка пользователя стать тренером.

### `getCoachAthletes(client)` — L36
GET `coach_athletes` — атлеты тренера со сводкой недели.

### `getAthleteDetails(client, athleteId, weekStart)` — L40
GET `get_athlete_details` — детали атлета, опционально неделя.

### `getCoachPricing(client, coachId)` — L46
GET `get_coach_pricing` — тарифы тренера (свои или по coach_id).

### `updateCoachPricing(client, pricing, pricesOnRequest)` — L51
POST `update_coach_pricing` (`prices_on_request` как 1/0).

### `getMyCoachProfile(client)` — L55
GET `get_my_coach_profile` — собственный тренерский профиль.

### `updateCoachProfile(client, data)` — L59
POST `update_coach_profile`.

### `getCoachGroups(client)` — L63
GET `get_coach_groups` — группы атлетов.

### `saveCoachGroup(client, data)` — L67
POST `save_coach_group` — создать/обновить группу.

### `deleteCoachGroup(client, groupId)` — L71
POST `delete_coach_group`.

### `getGroupMembers(client, groupId)` — L75
GET `get_group_members`.

### `updateGroupMembers(client, groupId, userIds)` — L79
POST `update_group_members` — заменить состав группы.

### `getAthleteGroups(client, userId)` — L83
GET `get_athlete_groups` — группы конкретного атлета.

### `getCoachApplications(client, params)` — L87
GET `admin_coach_applications` — заявки на тренерство (админка).

### `approveCoachApplication(client, applicationId)` — L91
POST `admin_approve_coach`.

### `rejectCoachApplication(client, applicationId)` — L95
POST `admin_reject_coach`.

### `getCsrf(client)` — L99
Внутренний (не экспортируется): GET `get_csrf_token`, возвращает csrf_token или null.

### `listWorkoutTemplates(client)` — L104
GET `list_workout_templates` — шаблоны тренировок тренера.

### `listExerciseLibrary(client)` — L108
GET `list_exercise_library` — библиотека упражнений.

### `getCoachEvents(client, hoursBack)` — L112
GET `coach_events` — лента событий атлетов за N часов (default 48).

### `saveWorkoutTemplate(client, data)` — L116
POST `save_workout_template` с предварительным getCsrf.

### `deleteWorkoutTemplate(client, templateId)` — L121
POST `delete_workout_template` с CSRF.

### `bulkAssignTraining(client, payload)` — L130
POST `bulk_assign_training` с CSRF — массовое назначение шаблона атлетам на дату; возвращает `{ ok, conflicts?, assigned?, overwritten?, errors? }`.

## `src/api/getAuthClient.js` (19 строк)
Доступ к авторизованному ApiClient вне React-дерева.

### `getAuthClient()` — L6
Возвращает `useAuthStore.getState().api`, а при его отсутствии — лениво созданный модульный fallback `new ApiClient()` (синглтон в переменной fallbackAuthClient). Export default.

## `src/api/planApi.js` (38 строк)
Эндпоинты плана тренировок.

### `getPlan(client, userId, viewContext)` — L1
GET `load` — загрузка плана (опционально user_id или view-параметры чужого профиля).

### `savePlan(client, planData)` — L7
POST `save` — план сериализуется в строку `plan: JSON.stringify(...)`.

### `regeneratePlan(client)` — L11
POST `regenerate_plan_with_progress` — регенерация с прогрессом (ставит job в очередь).

### `recalculatePlan(client, reason)` — L15
POST `recalculate_plan` — пересчёт плана (опционально reason).

### `generateNextPlan(client, goals)` — L21
POST `generate_next_plan` — следующий цикл плана (опционально goals).

### `submitPlanReadinessCheck(client, payload)` — L27
POST `submit_plan_readiness_check` — ответы check-in самочувствия (check_id + ответы).

### `checkPlanStatus(client, userId)` — L31
GET `check_plan_status` — `{ has_plan, generating, queued, job_type, error... }`; для этого action ошибка в теле не бросает исключение.

### `clearPlan(client)` — L36
POST `clear_plan` — удаление AI-плана (результаты сохраняются).

## `src/api/statsApi.js` (84 строки)
Статистика, интеграции (Strava/Huawei/Suunto/Health Connect), share-карточки.

### `getStats(client, viewContext)` — L1
GET `stats` — сводная статистика.

### `getAllWorkoutsSummary(client, viewContext)` — L6
GET `get_all_workouts_summary` — сводка тренировок по дням.

### `getAllWorkoutsList(client, viewContext, limit)` — L11
GET `get_all_workouts_list` — плоский список тренировок (limit default 500).

### `getRacePrediction(client, viewContext)` — L17
GET `race_prediction` — прогноз результата гонки.

### `getIntegrationOAuthUrl(client, provider, extra)` — L22
GET `integration_oauth_url` — URL OAuth-авторизации провайдера.

### `syncWorkouts(client, provider)` — L26
GET `get_csrf_token` → POST `sync_workouts` — запуск синхронизации провайдера (долгий запрос, таймаут 120с в ApiClient.request).

### `getIntegrationsStatus(client)` — L32
GET `integrations_status` — статус подключённых интеграций.

### `unlinkIntegration(client, provider)` — L36
GET `get_csrf_token` → POST `unlink_integration` — отключение провайдера.

### `setSuuntoMirror(client, enabled)` — L42
GET `get_csrf_token` → POST `set_suunto_mirror` — вкл/выкл зеркалирование плана в Suunto.

### `getStravaTokenError(client)` — L48
GET `strava_token_error` — есть ли проблема с токеном Strava.

### `importHealthConnectWorkouts(client, workouts)` — L52
GET `get_csrf_token` → POST `health_connect_import` — отправка массива тренировок из Health Connect на бэкенд.

### `getWorkoutTimeline(client, workoutId)` — L58
GET `get_workout_timeline` — пульс/темп по времени.

### `getWorkoutShareMap(client, workoutId, options)` — L62
`client.requestBlob` GET `get_workout_share_map` — PNG карты маршрута (бинарный ответ).

### `getWorkoutShareCard(client, workoutId, options)` — L66
`client.requestBlob` GET `generate_workout_share_card` — PNG share-карточки.

### `storeWorkoutShareCard(client, workoutId, payload)` — L70
GET `get_csrf_token` → POST `store_workout_share_card` — сохранить сгенерированную карточку на сервере.

### `getTrainingLoad(client, viewContext, days)` — L80
GET `training_load` — тренировочная нагрузка (ATL/CTL и т.п.) за N дней (default 90).

## `src/api/workoutApi.js` (186 строк)
Тренировки, дни плана, заметки, уведомления плана. Мутации защищены CSRF (через getCsrfToken).

### `getCsrfToken(client, message)` — L4
Внутренний: GET `get_csrf_token`; при отсутствии токена бросает ApiError CSRF_MISSING.

### `getUserBySlug(client, slug, token)` — L13
GET `get_user_by_slug` — публичный профиль по slug (срезает префикс `@`; token для приватных профилей).

### `getDay(client, date, viewContext)` — L19
GET `get_day` — детали дня (план + результаты).

### `getDays(client, dates, viewContext)` — L26
GET `get_days` — batch-детали нескольких дней (dates через запятую; префетч недели).

### `saveResult(client, data, viewContext)` — L32
POST `save_result` с CSRF — сохранить результат тренировки; `activity_type_id` дефолтится в 1; view-параметры идут в URL.

### `getResult(client, date, viewContext)` — L41
GET `get_result` — результат за дату.

### `uploadWorkout(client, file, opts)` — L47
Multipart POST `api_wrapper.php?action=upload_workout` (file + date + csrf_token, Bearer; credentials omit на native) — импорт GPX/TCX.

### `getAllResults(client, viewContext)` — L71
GET `get_all_results` — все результаты пользователя.

### `resetWorkout(client, date)` — L76
POST `reset` — снять отметку выполнения за дату.

### `deleteWeek(client, weekNumber)` — L80
POST `delete_week` — удалить неделю плана.

### `addWeek(client, weekData)` — L84
POST `add_week` — добавить неделю плана.

### `addTrainingDayByDate(client, data, viewContext)` — L88
POST `add_training_day_by_date` — добавить тренировку на дату (календарная модель).

### `deleteWorkout(client, workoutId, isManual, viewContext)` — L93
POST `delete_workout` с CSRF — удалить выполненную тренировку (workout или manual log).

### `deleteTrainingDay(client, dayId, viewContext)` — L99
POST `delete_training_day` с CSRF — удалить день из плана (training_plan_days.id).

### `copyDay(client, sourceDate, targetDate, viewContext)` — L105
POST `copy_day` с CSRF — копировать тренировку дня на другую дату.

### `copyWeek(client, sourceWeekId, targetStartDate, viewContext)` — L111
POST `copy_week` с CSRF — копировать неделю на другую стартовую дату.

### `getDayNotes(client, date, viewContext)` — L117
GET `get_day_notes` — заметки дня.

### `saveDayNote(client, date, content, noteId, viewContext)` — L123
POST `save_day_note` с CSRF — создать/обновить заметку дня (note_id для апдейта).

### `deleteDayNote(client, noteId, viewContext)` — L131
POST `delete_day_note` с CSRF.

### `getWeekNotes(client, weekStart, viewContext)` — L137
GET `get_week_notes` — заметки недели.

### `saveWeekNote(client, weekStart, content, noteId, viewContext)` — L143
POST `save_week_note` с CSRF.

### `deleteWeekNote(client, noteId, viewContext)` — L151
POST `delete_week_note` с CSRF.

### `getNoteCounts(client, startDate, endDate, viewContext)` — L157
GET `get_note_counts` — количество заметок по датам диапазона (бейджи в календаре).

### `getPlanNotifications(client, {includeRead, limit})` — L163
GET `get_plan_notifications`; include_read/limit передаются как extra URL-параметры.

### `markPlanNotificationRead(client, notificationId)` — L168
POST `mark_plan_notification_read` — отметить уведомление плана прочитанным.

### `markAllPlanNotificationsRead(client)` — L172
POST `mark_plan_notification_read` с `{ all: true }` — все уведомления прочитаны.

### `updateTrainingDay(client, dayId, data, viewContext)` — L176
POST `update_training_day` с CSRF — обновить тип/описание/is_key_workout дня плана.

## `src/plugins/healthConnect.js` (9 строк)
Регистрация Capacitor-плагина Health Connect (Android, реализация в HealthConnectPlugin.kt).

### `HealthConnect` (const) — L7
`registerPlugin('HealthConnect')` — нативный мост: методы isAvailable/hasPermissions/requestAuthorization/readWorkouts (вызывать только через healthConnectSync.js под гвардом isNativeCapacitor). Export default.

## `src/plugins/mediaSaver.js` (13 строк)
Регистрация Capacitor-плагина сохранения изображений в Галерею (Android, MediaSaverPlugin.kt).

### `MediaSaver` (const) — L11
`registerPlugin('MediaSaver')` — метод `saveImage({ data: base64, fileName }) -> { saved, uri }`; только под isNativePlatform-гвардом. Export default.

## `src/services/BiometricService.js` (256 строк)
Биометрическая аутентификация (@aparajita/capacitor-biometric-auth). Токены хранятся через TokenStorageService, флаг включения — в Preferences. Экспортируется синглтон `new BiometricService()`.

### `BiometricService::constructor()` — L13
Инициализирует кэш-поля isAvailable/biometricType (null).

### `BiometricService::_isNative()` — L18
Гвард: window существует и Capacitor native.

### `BiometricService::checkAvailability()` — L26
`BiometricAuth.checkBiometry()` — возвращает `{ available, type, error, code }`; кэширует в полях экземпляра; вне native — `available: false`.

### `BiometricService::authenticate(reason)` — L69
Показ системного биометрического диалога `BiometricAuth.authenticate` (androidTitle 'planRUN', strength weak, без device-credential fallback). Возвращает `{ success, error?, code? }`, не бросает.

### `BiometricService::saveTokens(accessToken, refreshToken)` — L112
Ставит флаг `biometric_enabled=true` в Preferences (на web — в localStorage вместе с токенами), затем фоном дублирует токены в TokenStorageService.saveTokens.

### `BiometricService::getTokens()` — L146
Читает токены: native — TokenStorageService первым (переживает очистку localStorage при kill), fallback localStorage; web — localStorage.

### `BiometricService::isBiometricEnabled()` — L177
Флаг `biometric_enabled` из Preferences (native) или localStorage (web).

### `BiometricService::clearTokens()` — L198
Удаляет флаг biometric_enabled и токены (Preferences + TokenStorageService.clearTokens; на web — localStorage).

### `BiometricService::authenticateAndGetTokens(reason)` — L226
Полный цикл: isBiometricEnabled → authenticate → getTokens. Таймаут 30с (Promise.race). `success: true, tokens: null` — токены потеряны, вызывающий идёт в credential recovery.

## `src/services/ChatSSE.js` (148 строк)
Singleton SSE-клиент непрочитанных сообщений чатов: одно EventSource-соединение на приложение, подписчики через Set, экспоненциальный reconnect (3с → 30с). На native URL пробивается на прод (`VITE_API_BASE_URL`/planrun.ru).

### `isNativeApp()` — L24
Внутренний: `Capacitor.isNativePlatform()` с try/catch.

### `getSSEUrl()` — L32
Внутренний: URL `<base>/chat_sse.php` (native — NATIVE_API_BASE, web — window.location.origin + /api).

### `parsePayload(data)` — L41
Внутренний: безопасный JSON.parse события в `{ total, by_type }`.

### `notifyListeners(data)` — L52
Внутренний: сохраняет unreadData и рассылает всем подписчикам.

### `scheduleReconnect()` — L57
Внутренний: отложенный reconnect с экспоненциальным backoff (только пока есть подписчики).

### `connect()` — L67
Открывает EventSource(`chat_sse.php`, withCredentials) и слушает событие `chat_unread`; onerror → close + scheduleReconnect; повторный вызов при открытом соединении — no-op.

### `disconnect()` — L94
Закрывает соединение, отменяет reconnect-таймер, сбрасывает backoff.

### `subscribe(callback)` — L106
Добавляет подписчика, сразу вызывает его с текущим unreadData; первый подписчик инициирует connect().

### `unsubscribe(callback)` — L113
Удаляет подписчика; последний — disconnect().

### `getUnreadData()` — L120
Текущее `{ total, by_type }` (синхронно, для начального state хуков).

### `setUnreadData(data)` — L124
Локальное обновление счётчиков (например, после mark-all-read) с нотификацией подписчиков; нормализует payload.

### `getUnreadTotal()` — L131
Возвращает `unreadData.total`. Потребителей в репо не найдено.

### `getUnreadByType(type)` — L135
Возвращает `unreadData.by_type[type] ?? 0`. Потребителей в репо не найдено.

### `ChatSSE` (const, экспорт) — L139
Публичный объект-API: connect/disconnect/subscribe/unsubscribe/getUnreadData/getUnreadTotal/getUnreadByType/setUnreadData.

## `src/services/ChatStreamWorker.js` (62 строки)
Обёртка запуска AI-стрима в Web Worker (fetch и парсинг NDJSON вне главного потока). Потребителей в репо не найдено — чат использует `api.chatSendMessageStream` напрямую.

### `runChatStreamInWorker(api, content, onChunk, opts)` — L13
Берёт токен через `api.getToken()`, создаёт Worker из `../workers/chatStream.worker.js`, шлёт ему `{ type:'start', url (chat_send_message_stream), body, token }`. Транслирует сообщения воркера: chunk → onChunk, plan_updated → opts.onPlanUpdated, done → resolve(fullContent), error → reject. Таймаут 180с с terminate().

## `src/services/CredentialBackupService.js` (306 строк)
Резервное хранение логина/пароля для восстановления входа, когда JWT потеряны (KeyStore сброшен, refresh истёк): SecureStorage (биометрический сценарий) + Preferences с AES-GCM/PBKDF2-шифрованием на PIN. Только Capacitor native. Экспортируется синглтон.

### `deriveKey(salt, pin)` — L21
Внутренний: PBKDF2-ключ с текущим числом итераций (120000).

### `deriveKeyWithIterations(salt, pin, iterations)` — L25
Внутренний: WebCrypto importKey + deriveKey → AES-GCM 256 (поддержка legacy 1000 итераций).

### `generateRandomBytes(length)` — L43
Внутренний: crypto.getRandomValues.

### `base64Encode(buffer)` — L47 / `base64Decode(str)` — L51
Внутренние: бинарь ↔ base64.

### `encodePayload(combined, iterations)` — L55
Внутренний: формат `v2:<iterations>:<base64>`.

### `decodePayload(value)` — L59
Внутренний: парсит v2-формат; без префикса — legacy (1000 итераций).

### `CredentialBackupService::isAvailable()` — L78
native Capacitor + наличие window.crypto.subtle.

### `CredentialBackupService::hasCredentials()` — L82
Алиас `hasCredentialsFor('any')`.

### `CredentialBackupService::hasCredentialsFor(mode)` — L86
Есть ли сохранённые credentials для режима 'pin' (Preferences `auth_cred_backup_enabled`/`_data`) или 'biometric' (флаг biometric_enabled + запись в SecureStorage).

### `CredentialBackupService::isBiometricRecoveryEnabled()` — L112
Флаг `biometric_enabled` в Preferences.

### `CredentialBackupService::_getSecureStorage()` — L121
Ленивая загрузка @aparajita/capacitor-secure-storage с keyPrefix `planrun_`; null при сбое/не-native.

### `CredentialBackupService::saveCredentialsSecure(username, password)` — L136
Пишет `{ username, password }` JSON в SecureStorage (ключ auth_cred_backup_secure). Вызывается при каждом native-входе.

### `CredentialBackupService::recoverAndLoginBiometric(api)` — L153
Восстановление сессии: читает credentials из SecureStorage (если биометрическое recovery включено) и вызывает `api.login(username, password, true)` (JWT). После успеха обновляет SecureStorage.

### `CredentialBackupService::saveCredentials(pin, username, password)` — L183
Шифрует credentials AES-GCM (ключ из 4-значного PIN, PBKDF2 120k, salt 16 + iv 12) и кладёт в Preferences (`auth_cred_backup_enabled`/`_data`, формат v2).

### `CredentialBackupService::recoverAndLogin(pin, api)` — L219
Восстановление по PIN: расшифровывает Preferences-данные (OperationError → «Неверный PIN»); fallback — нешифрованные credentials из SecureStorage. Затем `api.login(..., true)`; после успеха пересохраняет оба бэкапа.

### `CredentialBackupService::clearCredentials()` — L288
Удаляет Preferences-ключи и запись SecureStorage (при logout).

## `src/services/healthConnectSync.js` (123 строки)
Высокоуровневая синхронизация Health Connect: гварды платформы, разрешения, чтение тренировок через нативный плагин и отправка в бэкенд. Последняя точка синка — Preferences `hc_last_sync_iso`, локальный флаг отключения — `hc_disabled`.

### `isHealthConnectDisabled()` — L10
Прочитан ли флаг `hc_disabled === '1'` из Preferences (права HC программно не отзываются — отключение только локальное).

### `disconnectHealthConnect()` — L20
Ставит `hc_disabled = '1'` в Preferences.

### `isHealthConnectAvailable()` — L32
`HealthConnect.isAvailable()` под native-гвардом; вне native `{ available: false, status: 'unsupported' }`.

### `hasHealthConnectPermissions()` — L42
`HealthConnect.hasPermissions()` → `{ granted, routeGranted }`.

### `requestHealthConnectPermissions()` — L52
`HealthConnect.requestAuthorization()` — открывает системный экран разрешений Health Connect.

### `getSince(backfillDays)` — L57
Внутренний: ISO-дата начала скользящего окна (N дней назад; импорт идемпотентен по external_id).

### `syncHealthConnect(api, opts)` — L72
Полный цикл синка: (опц.) перепроверка/перезапрос прав → `HealthConnect.readWorkouts({ since })` → `api.importHealthConnectWorkouts(workouts)` (POST `health_connect_import`) → обновление `hc_last_sync_iso` в Preferences. Возвращает `{ imported, skipped, total }`.

### `connectAndSyncHealthConnect(api, backfillDays)` — L107
Подключение «с нуля»: isAvailable → requestPermissions (ошибки с code 'unavailable'/'denied') → сброс флага hc_disabled → первичный syncHealthConnect.

## `src/services/PinAuthService.js` (300 строк)
Вход по 4-значному PIN: JWT-токены шифруются AES-GCM с PBKDF2-ключом из PIN, хранятся в Preferences. Анти-brute-force: после 5 неудач экспоненциальный лок (30с → 15 мин). Только Capacitor. Экспортируется синглтон.

### `getKeyMaterial(pin)` — L21
Внутренний: importKey PIN для PBKDF2.

### `deriveKey(salt, pin)` — L32 / `deriveKeyWithIterations(salt, pin, iterations)` — L36
Внутренние: PBKDF2 (120000 итераций, legacy 1000) → AES-GCM 256.

### `generateRandomBytes(length)` — L52, `base64Encode(buffer)` — L56, `base64Decode(str)` — L60
Внутренние крипто/кодек-хелперы.

### `encodePayload(combined, iterations)` — L64 / `decodePayload(value)` — L68
Внутренние: формат `v2:<iterations>:<base64>` c legacy-фолбэком.

### `PinAuthService::isAvailable()` — L87
native + window.crypto.subtle.

### `PinAuthService::isPinEnabled()` — L91
Флаг `auth_pin_enabled === 'true'` в Preferences.

### `PinAuthService::_getLockState()` — L101
Внутренний: читает `auth_pin_lock` (failedAttempts, lockedUntil) из Preferences.

### `PinAuthService::_setLockState(state)` — L117 / `_clearLockState()` — L121
Внутренние: запись/удаление состояния лока в Preferences.

### `PinAuthService::_checkLockState()` — L125
Внутренний: активен ли лок (locked + waitSeconds); протухший лок очищается.

### `PinAuthService::_registerFailure()` — L138
Внутренний: инкремент неудач; при ≥5 ставит lockedUntil = 30с × 2^(n−5), максимум 15 мин.

### `PinAuthService::setPinAndSaveTokens(pin, accessToken, refreshToken)` — L159
Валидирует PIN (4 цифры), шифрует `{ accessToken, refreshToken }` AES-GCM, пишет `auth_pin_enabled` + `auth_pin_data` (v2-формат) в Preferences, сбрасывает лок.

### `PinAuthService::verifyAndGetTokens(pin)` — L208
Проверяет лок → расшифровывает данные ключом из PIN. Успех → `{ success, tokens }` + сброс лока; OperationError (неверный PIN) → _registerFailure и сообщение о локе/неверном PIN.

### `PinAuthService::clearPin()` — L286
Удаляет `auth_pin_enabled`, `auth_pin_data` и лок из Preferences.

## `src/services/PushService.js` (114 строк)
FCM push-уведомления для Capacitor: регистрация токена, отправка на бэкенд, навигация по тапу на уведомление.

### `registerPushNotifications(api)` — L19
Гвард native; сохраняет api в модульный ref; checkPermissions → requestPermissions; один раз вешает listeners (setupListeners) и вызывает `PushNotifications.register()`. Возвращает `{ ok, reason? }`.

### `unregisterPushNotifications(api)` — L51
`PushNotifications.unregister()` + POST `unregister_push_token` с device_id (при logout).

### `setupListeners()` — L66
Внутренний: addListener'ы (await — до register()): `registration` → POST `register_push_token` (fcm_token, device_id, platform); `registrationError` — no-op; `pushNotificationReceived` — dev-лог; `pushNotificationActionPerformed` — навигация window.location.href по data.link / type 'chat' → /chat / type 'workout' → /calendar?date=.

## `src/services/telegramMiniApp.js` (160 строк)
Telegram Mini App: детект контекста, ожидание SDK, fullscreen-инициализация, safe-area инсеты и синхронизация темы.

### `isTelegramContext()` — L14
Запущены ли внутри Telegram: window.Telegram.WebApp.initData или `tgWebAppData=` в hash/search (работает до загрузки SDK).

### `waitForTelegramSdk(timeoutMs)` — L27
Внутренний: поллинг window.Telegram.WebApp каждые 50 мс, до 1.5с; resolve WebApp или null.

### `getTelegramPlatform()` — L49
`WebApp.platform` ('android'|'ios'|'tdesktop'|...) или null вне Telegram.

### `isTelegramMobile()` — L58
platform === 'android' || 'ios'.

### `isTelegramDesktop()` — L64
platform в списке десктоп/веб-клиентов (tdesktop, macos, unigram, web, weba, webk).

### `getInitData()` — L71
Подписанная строка initData для серверной проверки ('' вне Telegram).

### `applyTelegramInsets(webApp)` — L84
Внутренний: пишет CSS-переменные `--tg-safe-area-inset-top/bottom`, `--tg-status-bar-height`, `--tg-buttons-band-height` (сумма safeAreaInset + contentSafeAreaInset) и класс `tg-fullscreen` на `<html>`.

### `syncThemeWithTelegram(webApp)` — L113
Внутренний: при предпочтении темы 'system' применяет colorScheme Telegram через applyTheme; подписывается на событие `themeChanged`.

### `initTelegramMiniApp()` — L130
Инициализация: ждёт SDK → ready() + expand() → syncThemeWithTelegram → при Bot API 8.0+ requestFullscreen + disableVerticalSwipes + applyTelegramInsets с подпиской на fullscreenChanged/safeAreaChanged/contentSafeAreaChanged/viewportChanged. Возвращает WebApp или null.

## `src/services/TokenStorageService.js` (291 строка)
Хранение токенов и device_id: web — localStorage; native — Preferences как основной источник (SecureStorage отключён из-за нестабильного Android KeyStore). Экспортирует синглтон и хелпер isNativeCapacitor.

### `withTimeout(promise, ms)` — L26
Внутренний: Promise.race с таймаутом 5с (защита от зависаний SecureStorage).

### `generateUuid()` — L36
Внутренний: crypto.randomUUID с ручным v4-фолбэком.

### `isNativeCapacitor()` — L48
Экспортируемый гвард: Capacitor.isNativePlatform() (или getPlatform ∈ {android, ios}); false при ошибке. Используется по всему фронту.

### `TokenStorageService::_getSecureStorage()` — L63
Намеренно всегда возвращает null — SecureStorage отключён (зависания KeyStore, сброс после обновления ОС); остальной код сохраняет совместимость.

### `TokenStorageService::getTokens()` — L71
web → localStorage; native → Preferences-бэкап (`auth_tokens_backup`) первым, затем (мёртвая ветка) SecureStorage, затем localStorage. Возвращает `{ accessToken, refreshToken }`.

### `TokenStorageService::_getTokensFromPreferencesBackup()` — L111
Внутренний: парсит JSON из Preferences `auth_tokens_backup`; при успехе фоном пытается восстановить в SecureStorage.

### `TokenStorageService::_tryRestoreToSecureStorage(at, rt)` — L127
Внутренний: запись токенов в SecureStorage с таймаутом (фактически no-op, т.к. _getSecureStorage → null).

### `TokenStorageService::saveTokens(accessToken, refreshToken)` — L138
web → localStorage; native → await Preferences (`auth_tokens_backup`), затем фоновая запись в SecureStorage (no-op).

### `TokenStorageService::clearTokens()` — L171
Удаляет Preferences-бэкап, localStorage-ключи и (если был бы) SecureStorage.

### `TokenStorageService::getDeviceId()` — L197
`planrun_device_id` из localStorage (web) или Preferences (native).

### `TokenStorageService::saveDeviceId(id)` — L214
Сохраняет device_id в localStorage/Preferences.

### `TokenStorageService::getOrCreateDeviceId()` — L238
getDeviceId, при отсутствии — generateUuid + saveDeviceId.

### `TokenStorageService::isPasswordReauthBypassEnabled()` — L247
Флаг `auth_password_reauth_bypass` (localStorage/Preferences) — пользователь явно выбрал «войти по паролю», lock screen не показывается.

### `TokenStorageService::setPasswordReauthBypass(enabled)` — L266
Ставит/снимает флаг bypass в localStorage и Preferences.

## `src/services/WebPushService.js` (114 строк)
Web Push (браузер): service worker `/sw.js`, подписка PushManager с VAPID, регистрация подписки на бэкенде. Класс со статическими методами, export default.

### `urlBase64ToUint8Array(base64String)` — L1
Внутренний: VAPID-ключ base64url → Uint8Array для applicationServerKey.

### `WebPushService.isSupported()` — L13
Браузер поддерживает serviceWorker + PushManager + Notification.

### `WebPushService.getPermission()` — L21
`Notification.permission` или 'unsupported'.

### `WebPushService.registerServiceWorker()` — L28
`navigator.serviceWorker.register('/sw.js')` и ожидание ready.

### `WebPushService.ensureSubscription({api, csrfToken, vapidPublicKey})` — L36
Проверяет поддержку/permission granted → регистрирует SW → получает или создаёт push-подписку (userVisibleOnly, VAPID) → POST `register_web_push_subscription` (subscription.toJSON + user_agent + csrf).

### `WebPushService.getCurrentSubscription()` — L75
Текущая подписка из существующей регистрации SW (или регистрирует SW).

### `WebPushService.unregister({api, csrfToken})` — L88
unsubscribe() подписки (ошибки гасятся) + POST `unregister_web_push_subscription` с endpoint.

## `src/stores/useAuthStore.js` (553 строки)
Zustand-store авторизации (с persist-обёрткой, но partialize пустой — ничего не персистится). Владеет экземпляром ApiClient, состоянием user/isAuthenticated/isLocked, lock screen, PIN/биометрия, recovery, фоновая блокировка.

### `shouldPrefetchAiPlan(userData)` — L15
Модульный хелпер: префетчить план только если onboarding завершён и training_mode === 'ai'.

### `prefetchAiPlanInBackground()` — L22
Модульный хелпер: динамический импорт usePlanStore → checkPlanStatus → loadPlan / startStatusPolling / setPlanStatusChecked(false) — фоновая загрузка плана без блокировки UI.

### `useAuthStore::setDrawerOpen(open)` — L56
Экшен: открыть/закрыть боковое меню профиля (значение или updater-функция).

### `useAuthStore::setSettingsPanelOpen(open)` — L58
Экшен: открыть/закрыть панель настроек.

### `useAuthStore::setLocked(value)` — L61
Экшен: выставляет isLocked; при value=true сбрасывает `_biometricAutoTriggered` (авто-биометрия выстрелит снова один раз).

### `useAuthStore::tryAutoTriggerBiometric()` — L72
Экшен-guard: атомарно проверяет и взводит флаг `_biometricAutoTriggered`; true — можно запускать авто-биометрию (ровно один раз на сессию блокировки).

### `useAuthStore::initialize()` — L93
Главный экшен старта приложения: создаёт ApiClient, вешает onTokenExpired (биометрическое recovery через CredentialBackupService → иначе logout(false)); safety-таймаут 3с на показ login. Native: при включённых PIN/биометрии (и без password-bypass) — показывает lock screen и выходит; зеркалирует токены Preferences → localStorage. Telegram Mini App: initTelegramMiniApp + telegramMiniAppAuth по initData. Затем getCurrentUser → set user/isAuthenticated, setupBackgroundLock, фоновый префетч AI-плана.

### `useAuthStore::setupBackgroundLock()` — L225
Экшен (однократный): подписки на visibilitychange и Capacitor appStateChange. background → запоминает lastActiveAt; foreground → лок через 15 мин неактивности (только при _lockEnabled) + проактивный `api.getCurrentUser()` для refresh токенов.

### `useAuthStore::unlock(tokens)` — L265
Экшен: setToken → getCurrentUser → снять lock, сбросить password-bypass, фоновый префетч плана и регистрация push. Потребителей в репо не найдено (LockScreen использует pinLogin/biometricLogin → _completeUnlock).

### `useAuthStore::_completeUnlock(tokens, recoveryFn)` — L294
Внутренний экшен: общая логика разблокировки — setToken → getCurrentUser(throwOnNetworkError); при недействительной сессии — recoveryFn(api) (PIN/биометрическое credential recovery) и повторный getCurrentUser. Успех: сброс bypass, set user/isAuthenticated/isLocked=false, префетч плана, регистрация push.

### `useAuthStore::login(username, password, useJwt)` — L343
Экшен: api.login → set user/isAuthenticated, дозагрузка полного user через getCurrentUser, фоновый префетч плана; native — setupBackgroundLock + registerPushNotifications. Возвращает `{ success, access_token?, refresh_token? }` или `{ success: false, error }`.

### `useAuthStore::logout(clearStoredCredentials = true)` — L387
Экшен: немедленно сбрасывает UI-state (user/isAuthenticated/isLocked) и план (usePlanStore.clearPlan); затем unregister push (при явном выходе), api.logout, очистка BiometricService/PinAuthService/CredentialBackupService (только при clearStoredCredentials=true; false — сессия истекла, способы входа сохраняются).

### `useAuthStore::beginPasswordReauth()` — L424
Экшен: ставит password-reauth bypass (lock screen не покажется) и делает logout(false) — пользователь идёт на форму пароля.

### `useAuthStore::pinLogin(pin)` — L432
Экшен: PinAuthService.verifyAndGetTokens → предпочитает более свежие токены из TokenStorageService → _completeUnlock с recovery через CredentialBackupService.recoverAndLogin(pin). Успех — пересохраняет актуальные токены под PIN. Сетевые ошибки помечаются isNetworkError.

### `useAuthStore::biometricLogin()` — L479
Экшен: BiometricService.authenticateAndGetTokens → _completeUnlock с recovery через recoverAndLoginBiometric (если включено). Флаг _unlocking защищает от вмешательства onTokenExpired.

### `useAuthStore::setPlanGenerationMessage(message)` — L515
Экшен: сообщение о генерации плана после онбординга (показывается на дашборде).

### `useAuthStore::updateUser(userData)` — L518
Экшен: заменяет user; isAuthenticated выставляется если userData.authenticated === true (используется после регистрации).

### `useAuthStore::checkBiometricAvailability()` — L528
Экшен: BiometricService.checkAvailability + isBiometricEnabled → `{ available, type, enabled }` (для LockScreen/настроек).

### `useAuthStore::checkPinAvailability()` — L540
Экшен: PinAuthService.isPinEnabled → `{ enabled }`.

## `src/stores/useCoachStore.js` (179 строк)
Zustand-store тренерского workspace: атлеты/группы/шаблоны/события с API + UI-state (вид, выбор, фильтр, модалки). Селекторы экспортируются отдельно.

### `daysSince(dateStr)` — L13
Модульный хелпер: дней с даты (Infinity для null/invalid).

### `isAtRisk(athlete)` — L21
Модульный хелпер: атлет «требует внимания» — compliance недели < 50% или >7 дней без активности.

### `hasFreshUpload(athlete)` — L32
Модульный хелпер: была ли активность сегодня.

### `useCoachStore::loadAll(api)` — L54
Экшен: параллельно GET coach_athletes, coach_requests(pending), get_coach_groups, list_workout_templates, coach_events(48ч); нормализует и кладёт athletes/groups/templates/events/requestsCount; loadError при сбое.

### `useCoachStore::reloadEvents(api)` — L84
Экшен: перезагрузка ленты событий (GET `coach_events`, 48ч).

### `useCoachStore::reloadTemplates(api)` — L95
Экшен: перезагрузка шаблонов (GET `list_workout_templates`).

### `useCoachStore::setView(view)` — L106
Экшен: режим списка 'table' | 'grid' | 'stream'.

### `useCoachStore::setFilterGroup(filterGroup)` — L110
Экшен: фильтр 'all' | groupId | 'risk' | 'fresh'.

### `useCoachStore::setActiveAthleteId(id)` — L114
Экшен: активный атлет (overlay).

### `useCoachStore::toggleSelected(id)` — L118
Экшен: переключить выбор атлета (новый Set).

### `useCoachStore::selectMany(ids, on)` — L125
Экшен: массовое добавление/снятие выбора.

### `useCoachStore::clearSelected()` — L132
Экшен: очистить выбор.

### `useCoachStore::openBulkAssign()` — L136 / `closeBulkAssign()` — L140
Экшены: открыть/закрыть модалку массового назначения.

### `selectFilteredAthletes(state)` — L147
Экспортируемый селектор: атлеты по текущему фильтру (all/risk/fresh/по id группы).

### `selectKpi(state)` — L158
Экспортируемый селектор: KPI — кол-во risk, fresh, вопросов (events kind==='question'), средний compliance % недели.

### `coachHelpers` (const, экспорт) — L177
Объект `{ daysSince, isAtRisk, hasFreshUpload }` для переиспользования в компонентах.

## `src/stores/usePlanStore.js` (576 строк)
Zustand-store плана тренировок — единый источник правды о генерации/пересчёте: plan, hasPlan, planStatus, isGenerating/generationLabel, readiness-check, поллинг статуса.

### `usePlanStore::_updateGeneratingState()` — L37
Внутренний экшен: пересчитывает isGenerating (флаги действий ИЛИ активный planStatus) и generationLabel («Генерация нового плана...» / «Пересчёт плана...» / «Генерация плана...»).

### `usePlanStore::startStatusPolling()` — L60
Экшен: фоновый поллинг GET `check_plan_status` каждые 5с (старт через 3с), пока генерация активна. При has_plan — loadPlan + triggerRefresh (useWorkoutRefreshStore); при error — стоп с записью ошибки. Не запускается при активном action-поллинге.

### `usePlanStore::stopStatusPolling()` — L125
Экшен: clearTimeout таймера поллинга.

### `usePlanStore::initPlanStatus()` — L136
Экшен: проверка статуса при старте/F5 с дедупликацией через _initPromise; обновляет planStatus/hasPlan и при активной генерации запускает startStatusPolling.

### `usePlanStore::applyQueuedPlanState(queueResult)` — L172
Экшен: переводит store в состояние «job поставлен в очередь» (generating + queued + job_id/job_type) и запускает поллинг.

### `usePlanStore::_requireReadinessCheck(result, pendingAction)` — L191
Внутренний экшен: сервер требует check-in самочувствия — сохраняет planReadinessCheck и отложенное действие (generate/recalculate/next_plan), сбрасывает флаги генерации.

### `usePlanStore::loadPlan(userId)` — L205
Экшен: GET `load` через api.getPlan; hasPlan по наличию weeks_data; при успехе нормализует planStatus в «план есть».

### `usePlanStore::savePlan(planData)` — L249
Экшен: POST `save` через api.savePlan; обновляет plan/hasPlan/planStatus локально.

### `usePlanStore::checkPlanStatus(userId)` — L287
Экшен: GET `check_plan_status`; обновляет planStatus/hasPlan; при активной генерации без action-поллинга — startStatusPolling. На ошибке сохраняет активный статус (не затирает генерацию).

### `usePlanStore::regeneratePlan(withProgress)` — L320
Экшен: POST `regenerate_plan_with_progress` (или `regenerate_plan` при withProgress=false). Может вернуться requires_readiness_check → _requireReadinessCheck. Иначе applyQueuedPlanState + triggerRefresh.

### `usePlanStore::recalculatePlan(reason)` — L356
Экшен: POST `recalculate_plan`; затем собственный поллинг check_plan_status каждые 5с до 40 попыток. Успех → loadPlan + triggerRefresh; ошибка/таймаут → POST `reactivate_plan` (восстановление старого плана) + loadPlan + сообщение об ошибке.

### `usePlanStore::generateNextPlan(goals)` — L417
Экшен: POST `generate_next_plan`; аналогичный поллинг до 50 попыток с reactivate_plan-фолбэком; поддерживает readiness-check.

### `usePlanStore::submitPlanReadinessCheck(answer)` — L477
Экшен: POST `submit_plan_readiness_check` (check_id + ответы), затем выполняет отложенное действие: generateNextPlan / regeneratePlan(false) / recalculatePlan.

### `usePlanStore::dismissPlanReadinessCheck()` — L516
Экшен: сброс readiness-check без выполнения действия.

### `usePlanStore::clearPlan()` — L525
Экшен: стоп поллинга + полный сброс состояния плана (вызывается при logout).

### `usePlanStore::setPlanStatusChecked(hasPlan)` — L541
Экшен: зафиксировать «статус проверен, плана нет» (или есть) без загрузки.

### `usePlanStore::setPlan(planData)` — L553
Экшен: установка плана для оптимистичных обновлений (из CalendarScreen/Dashboard); нормализует planStatus при наличии weeks_data.

## `src/stores/usePreloadStore.js` (17 строк)
Микро-store фоновой предзагрузки вкладок (native): после загрузки Dashboard триггерит префетч Calendar и Stats.

### `usePreloadStore::triggerPreload()` — L12
Экшен: ставит `preloadTriggered = true`; CalendarScreen/StatsScreen подписаны и начинают загрузку данных.

## `src/stores/useWorkoutRefreshStore.js` (185 строк)
Zustand-store глобального обновления данных тренировок: счётчик `version` (подписка экранов) + проверка `data_version` бэкенда. Браузер — polling 30с; native — resume listener + push + foreground-polling 60с.

### `useWorkoutRefreshStore::triggerRefresh()` — L34
Экшен: инкремент version — все подписанные экраны перезагружают данные.

### `useWorkoutRefreshStore::checkForUpdates()` — L43
Экшен: GET `data_version` через api.getDataVersion; если версия изменилась с прошлой проверки — triggerRefresh. Возвращает true при обновлении.

### `useWorkoutRefreshStore::startAutoRefresh()` — L71
Экшен: запуск авто-обновления (идемпотентно). Native: init версии + setTimeout-цикл 60с + Capacitor App `appStateChange` (resume → checkForUpdates, fallback visibilitychange) + PushNotifications `pushNotificationReceived` (типы workout_sync/strava_sync/polar_sync/coros_sync/new_workout → checkForUpdates). Браузер: setTimeout-цикл 30с.

### `useWorkoutRefreshStore::stopAutoRefresh()` — L169
Экшен: clearTimeout + снятие resume/push-листенеров (_resumeCleanup).

### `useWorkoutRefreshStore::startDataPolling()` — L181 / `stopDataPolling()` — L182
Legacy-алиасы startAutoRefresh/stopAutoRefresh.

## `src/stores/useWorkoutStore.js` (186 строк)
Zustand-store результатов тренировок (workouts map по дате, allResults, currentDay). Потребителей в репо не найдено — экраны работают с api напрямую; store, по-видимому, мёртвый.

### `useWorkoutStore::loadAllResults()` — L18
Экшен: api.getAllResults (GET `get_all_results`) → allResults + map workouts{date}.

### `useWorkoutStore::loadDay(date)` — L55
Экшен: api.getDay (GET `get_day`) → currentDay.

### `useWorkoutStore::saveResult(date, result)` — L84
Экшен: api.saveResult (POST `save_result`) + оптимистичное обновление workouts/allResults.

### `useWorkoutStore::resetResult(date)` — L128
Экшен: api.reset (POST `reset`) + удаление из локального состояния.

### `useWorkoutStore::getResult(date)` — L164
Селектор-метод: результат из map по дате или null.

### `useWorkoutStore::hasResult(date)` — L170
Селектор-метод: есть ли результат за дату.

### `useWorkoutStore::clearWorkouts()` — L176
Экшен: сброс workouts/allResults/currentDay/error.

## `src/workers/chatStream.worker.js` (84 строки)
Web Worker для чтения AI-стрима вне главного потока (пара к ChatStreamWorker.js; потребителей у пары не найдено).

### `self.onmessage(e)` — L7
Обработчик `{ type:'start', url, body, token }`: fetch POST на url (Bearer, credentials include), читает ReadableStream, парсит NDJSON-строки; postMessage: `{type:'chunk'}` на каждый chunk, `{type:'plan_updated'}`, `{type:'done', fullContent}`, `{type:'error', message}`.

## `src/App.jsx` (306 строк)
Корневой компонент: роутинг (react-router), гейты авторизации/онбординга, lock screen, maintenance mode, предзагрузка модулей, push, deep links OAuth, проверка обновлений приложения, Telegram topbar.

### `ScrollToTop()` — L33
Компонент-хелпер: плавный скролл вверх при смене pathname/search; рендерит null.

### `RoutedErrorBoundary({children})` — L41
Компонент: AppErrorBoundary с resetKey из текущего location — сброс ошибки при навигации.

### `OnboardingGate({isAuthenticated, user})` — L52
Компонент-гейт /onboarding: гость → /landing; user не загружен → лоадер; онбординг пройден и это не смена режима (loc.state.mode) → /; иначе OnboardingFlow.

### `App()` — L61
Главный компонент (export default). Эффекты: `initialize()` стора авторизации; startAppUpdatePolling; загрузка getSiteSettings (maintenance_mode/registration_enabled); preloadAuthenticatedModules + preloadScreenModulesDelayed после логина; registerPushNotifications (только native); Capacitor `appUrlOpen` deep link `planrun://oauth-callback` → редирект на /settings?tab=integrations. Внутренние хелперы: handleLogout (L131) — вызов logout(); handleRegister (L135) — updateUser с authenticated: true после регистрации. Рендер: лоадер → LockScreen → maintenance-оверлей → Routes (landing/register/login/forgot/reset/privacy/design-system/onboarding/авторизованная зона AppLayout с вкладками/публичный профиль /:username) + AppUpdateModal + SettingsPanel.

## `src/main.jsx` (34 строки)
Точка входа. Функций не объявляет; модульные side effects: initLogger + installGlobalErrorLogger; добавляет класс `native-app` на `<html>` в Capacitor; detectAndroidEdgeToEdge(); регистрация service worker WebPushService (если поддерживается); рендер `<React.StrictMode><AppErrorBoundary><App/></...>` в #root.
