# Backend services 3/6 (Notification*…PlanNotification) — справочник функций

## `planrun-backend/services/NotificationDispatcher.php` (249 строк)
Маршрутизатор внешней доставки уведомлений по 4 каналам (mobile_push, web_push, telegram, email). Применяет шаблоны (NotificationTemplateService), проверяет настройки/quiet hours (NotificationSettingsService), при необходимости откладывает в очередь или email-дайджест.

### class NotificationDispatcher — L11
Наследует BaseService. Держит инстансы NotificationSettingsService и NotificationTemplateService.

#### `__construct($db)` — L15
Вызывает родительский конструктор и создаёт settingsService/templateService.

#### `dispatchToUser(int $userId, string $eventKey, string $title, string $body, array $options = [])` — L21
Главная точка входа: прогоняет title/body/options через `NotificationTemplateService::prepare()`, затем для каждого канала из `options.target_channels` (по умолчанию все CHANNEL_KEYS) вызывает `dispatchChannel()`. Возвращает массив результатов по каналам (bool|'digest'|'deferred').

#### `processQueuedDelivery(array $queuedDelivery)` — L49
Обрабатывает элемент очереди `notification_delivery_queue` (от cron-воркера): валидирует поля, повторно проверяет guard через `canDeliver()`; при quiet_hours возвращает status=deferred c новым deliver_after, при иных отказах логирует skipped в `notification_deliveries` и возвращает skipped; иначе шлёт через `sendChannelNow()` с ignore_quiet_hours=true. Возвращает ['status','error_text'(,'deliver_after')].

#### `getUserTelegramId(int $userId)` — L103 (private)
Читает `users.telegram_id` по id, возвращает int (0 если нет).

#### `dispatchChannel(int $userId, string $channel, string $eventKey, string $title, string $body, array $options)` — L115 (private)
Логика одного канала: для email при daily-дайджесте (`shouldUseEmailDigest()`) кладёт элемент в `notification_email_digest_items` (queueEmailDigestItem) и логирует status=digest; иначе проверяет `canDeliver()`; при allowed шлёт сразу (`sendChannelNow()`); при quiet_hours (и без skip_queue) откладывает в `notification_delivery_queue` (queueDelivery) с deliver_after из `getQuietHoursResumeAt()` и логирует deferred. Возвращает bool|'digest'|'deferred'.

#### `shouldUseEmailDigest(int $userId, string $eventKey, array $options)` — L174 (private)
true, если режим email-дайджеста юзера = daily и не задан force_instant_email / ignore_quiet_hours, и событие не системное (`system.*`).

#### `sendChannelNow(int $userId, string $channel, string $eventKey, string $title, string $body, array $options)` — L190 (private)
Физическая отправка: mobile_push → `PushNotificationService::sendToUser` (FCM); web_push → `WebPushNotificationService::sendToUser`; telegram → `TelegramLoginService::sendMessageIfConfigured` (Bot API); email → `EmailNotificationService::sendToUser`. Для web_push/telegram/email пишет результат в `notification_deliveries` через `logDelivery()`. Возвращает bool успеха.

## `planrun-backend/services/NotificationService.php` (103 строки)
Единый производитель уведомлений: пишет in-app строку в `plan_notifications` (со свёрткой по ref_key через UPSERT) и параллельно запускает внешнюю доставку через NotificationDispatcher. Используется PlanNotificationService и ChatService.

### class NotificationService — L10
Без наследования; хранит $db.

#### `__construct($db)` — L13
Сохраняет соединение с БД.

#### `create(int $userId, string $eventKey, string $title, string $body, array $opts = [])` — L32
Формирует metadata (link, category) и сохраняет in-app строку через `storeRow()` (INSERT или UPSERT по ref_key в `plan_notifications`). Если `opts.dispatch !== false`, вызывает `NotificationDispatcher::dispatchToUser()` в try/catch — сбой доставки не ломает in-app запись.

#### `markReadByRefKey(int $userId, string $refKey)` — L64
UPDATE `plan_notifications` SET read_at=NOW() для непрочитанных строк с данным ref_key (например при открытии чата сворачивает «chat:123»).

#### `storeRow(int $userId, string $type, string $message, ?string $metaJson, ?string $refKey)` — L77 (private)
INSERT в `plan_notifications`; при наличии ref_key — `ON DUPLICATE KEY UPDATE` с перезаписью message/metadata, обновлением created_at и сбросом read_at (свёртка повторных уведомлений в одну строку).

## `planrun-backend/services/NotificationSettingsService.php` (1733 строки)
Центральный сервис настроек уведомлений: схема 7 таблиц, каталог событий, чтение/сохранение настроек юзера, guard-проверки доставки (canDeliver), quiet hours, очередь отложенной доставки, email-дайджест, dispatch-guards (дедупликация) и лог доставок.

### class NotificationSettingsService — L7
Наследует BaseService. Статический флаг $schemaEnsured и кэш $settingsCache по userId.

#### `const CHANNEL_KEYS` — L11 (public)
Список каналов: mobile_push, web_push, telegram, email.

#### `const AI_COACH_EVENTS` — L13 (private)
Карта 15 проактивных событий AI-тренера (coach.proactive_*) → [label, description] для каталога.

#### `ensureSchema()` — L31
Идемпотентно создаёт 7 таблиц (CREATE TABLE IF NOT EXISTS): notification_channel_settings, notification_preferences, web_push_subscriptions, notification_deliveries, notification_dispatch_guards, notification_delivery_queue, notification_email_digest_items. Бросает исключение при ошибке SQL.

#### `getEventCatalog()` — L165 (static)
Возвращает статический каталог групп событий (workouts/chat/ai_coach/plan/system) с label/description/channels/roles/locked для UI настроек. AI-группа собирается из `getAiCoachCatalogEvents()`.

#### `getEventDefinitions()` — L310 (static)
Плоская карта event_key → определение события (+ group_key/group_label) из каталога. Используется dispatcher'ом и canDeliver.

#### `getAiCoachCatalogEvents()` — L327 (private static)
Преобразует AI_COACH_EVENTS в массив событий каталога с channels=CHANNEL_KEYS.

#### `buildAiCoachDefaultPreferences(bool $mobilePushEnabled)` — L340 (private static)
Дефолтные preferences для всех AI-coach событий: mobile_push по легаси-флагу юзера, остальные каналы off.

#### `getSettings(int $userId)` — L353
Собирает полные настройки юзера: дефолты (`buildDefaultSettings()`) + строка `notification_channel_settings` + строки `notification_preferences` + web push подписки. Возвращает структуру {version, timezone, channels, schedule, quiet_hours, preferences, catalog, paused}; кэширует в $settingsCache. Бросает 404, если юзер не найден.

#### `saveSettings(int $userId, array $payload)` — L421
Валидирует/нормализует входной payload и UPSERT'ит `notification_channel_settings` (каналы, quiet hours, время напоминаний, digest_mode, paused) и `notification_preferences` per-event (с учётом locked-событий и поддерживаемых каналов). После — `syncLegacyUserFlags()` и возврат свежих настроек. Пишет в 2 таблицы + users.

#### `canDeliver(int $userId, string $channel, string $eventKey, bool $ignoreQuietHours = false)` — L584
Guard-проверка доставки: unknown_event → paused (kill-switch) → unknown/unsupported channel → locked (только email) → channel_disabled/unavailable → web_push not_implemented (без VAPID) → event_disabled (per-event preference) → quiet_hours. Возвращает ['allowed' => bool, 'reason' => string].

#### `hasAnyDeliverableChannel(int $userId, string $eventKey, bool $ignoreQuietHours = false, array $channels = CHANNEL_KEYS)` — L637
true, если хотя бы один канал проходит `canDeliver()`. Используется проактивным AI-тренером, чтобы не генерировать сообщение «в никуда».

#### `getWorkoutReminderSchedule(int $userId, string $scope)` — L647
Возвращает event_key/время/час/минуту напоминания о тренировке для scope today|tomorrow из settings.schedule.

#### `logDelivery(int $userId, string $eventKey, string $channel, string $status, array $payload = [])` — L660
INSERT строки аудита в `notification_deliveries` (title/body/entity/error усечены до лимитов). Молча выходит при ошибке prepare.

#### `getQuietHoursResumeAt(int $userId, ?DateTimeImmutable $referenceUtc = null)` — L681
Возвращает UTC datetime окончания quiet hours юзера ('Y-m-d H:i:s') с учётом его timezone, либо null, если quiet hours выключены/start==end. Если сейчас не quiet hours — возвращает текущее время.

#### `isInQuietHours(int $userId, ?DateTimeImmutable $referenceUtc = null)` — L710
true, если текущее (или заданное) время попадает в quiet-hours окно юзера в его таймзоне.

#### `getEmailDigestMode(int $userId)` — L731
Возвращает 'instant'|'daily' из настроек email-канала.

#### `getNextEmailDigestAt(int $userId, ?DateTimeImmutable $referenceUtc = null)` — L736
UTC-время следующей отправки дайджеста: ближайшие 09:00 локального времени юзера; null, если email-канал выключен/недоступен.

#### `queueDelivery(int $userId, string $eventKey, string $channel, string $title, string $body, array $payload = [], ?string $deliverAfterUtc = null)` — L759
INSERT в `notification_delivery_queue` (отложенная доставка, например на конец quiet hours). Возвращает insert_id или 0.

#### `queueEmailDigestItem(int $userId, string $eventKey, string $title, string $body, array $payload = [], ?string $digestAfterUtc = null)` — L808
INSERT в `notification_email_digest_items` с digest_after = `getNextEmailDigestAt()` по умолчанию. Возвращает insert_id или 0.

#### `reserveDueEmailDigestUsers(int $limit = 25)` — L850
Возвращает user_id с pending дайджест-элементами, у которых digest_after <= now (для cron). Предварительно сбрасывает зависшие processing (>30 мин) обратно в pending.

#### `reserveDueEmailDigestItemsForUser(int $userId)` — L880
Атомарно помечает due pending элементы дайджеста юзера как processing и возвращает их список (id, event_key, title, body, link, entity, даты).

#### `markEmailDigestItemsCompleted(array $itemIds, string $status = 'sent', ?string $errorText = null)` — L940
UPDATE статуса (sent|failed|skipped) для списка id дайджест-элементов. Параметр $errorText не используется в теле.

#### `rescheduleEmailDigestItems(array $itemIds, string $digestAfterUtc, ?string $errorText = null)` — L957
Возвращает элементы дайджеста в pending с новым digest_after. $errorText также игнорируется.

#### `reserveDueQueuedDeliveries(int $limit = 50)` — L974
Резервация due элементов `notification_delivery_queue` для cron-воркера: сброс зависших processing, выбор pending по deliver_after, по одному атомарный UPDATE pending→processing (attempts+1), возврат полных строк через `getQueuedDeliveryById()`.

#### `markQueuedDeliveryCompleted(int $queueId, string $status = 'sent', ?string $errorText = null)` — L1027
UPDATE финального статуса (sent|failed|skipped) и last_error для элемента очереди доставки.

#### `rescheduleQueuedDelivery(int $queueId, string $deliverAfterUtc, ?string $errorText = null)` — L1046
Возвращает элемент очереди в pending с новым deliver_after (повторное откладывание, например снова quiet hours).

#### `getDeliveryLog(int $userId, int $limit = 12)` — L1064
Последние записи `notification_deliveries` юзера с человеко-читаемыми label'ами события/канала/статуса (для UI «история уведомлений»).

#### `acquireDispatchGuard(int $userId, string $eventKey, string $entityType, string $entityId, int $staleAfterSeconds = 1800)` — L1129
Дедупликация отправки по сущности: удаляет протухший processing-guard, затем INSERT IGNORE в `notification_dispatch_guards`. true = guard захвачен (можно слать), false = уже обрабатывается/отправлено. При пустых entity-значениях разрешает отправку.

#### `markDispatchGuardSent(int $userId, string $eventKey, string $entityType, string $entityId)` — L1170
Переводит guard в status='sent' с sent_at=NOW() (финализация — повторно слать нельзя).

#### `releaseDispatchGuard(int $userId, string $eventKey, string $entityType, string $entityId)` — L1194
Удаляет processing-guard (откат при ошибке отправки, чтобы можно было повторить).

#### `buildDefaultSettings(array $user)` — L1217 (private)
Строит дефолтную структуру настроек из контекста юзера: доступность каналов (push-токены, web push подписки + VAPID env-ключи, telegram_id + бот, email), легаси-флаги users.push_*, дефолтные preferences по всем событиям (+ AI-coach дефолты). Вызывает `TelegramLoginService::isBotConfigured()` и env().

#### `getUserContext(int $userId)` — L1376 (private)
SELECT из `users` (+ подзапросы COUNT по `push_tokens` и `web_push_subscriptions`): role, email, telegram_id, timezone, легаси push-поля. Бросает 500 при ошибке prepare.

#### `getChannelSettingsRow(int $userId)` — L1410 (private)
SELECT строки `notification_channel_settings` юзера или null.

#### `getPreferenceRows(int $userId)` — L1438 (private)
SELECT всех строк `notification_preferences` юзера, ключ — event_key.

#### `getWebPushSubscriptionItems(int $userId)` — L1461 (private)
До 12 последних web push подписок юзера (endpoint, user_agent, created_at, last_seen_at) из `web_push_subscriptions`.

#### `syncLegacyUserFlags(int $userId)` — L1497 (private)
Обратная синхронизация в `users`: push_workouts_enabled / push_chat_enabled / push_workout_hour / push_workout_minute выводятся из новых настроек (для старого кода, читающего легаси-поля).

#### `extractChannelEnabled(array $channelInput, string $channel, bool $default)` — L1536 (private)
Достаёт enabled-флаг канала из payload (поддерживает форму {enabled: bool} и скалярную), иначе default. Возвращает 0|1.

#### `normalizeBool($value)` — L1546 (private)
Приведение произвольного значения (bool/число/строка '1','true','yes','on') к 0|1.

#### `normalizeTime($value, string $fallback)` — L1562 (private)
Валидация строки 'H:MM'/'HH:MM' → 'HH:MM', иначе fallback.

#### `normalizeStoredTime(string $value, string $fallback)` — L1574 (private)
То же для значений из БД формата TIME (опциональные секунды отбрасываются).

#### `normalizeGuardValue(string $value, int $limit)` — L1585 (private)
trim + mb_substr до лимита (для entity_type/entity_id).

#### `getQueuedDeliveryById(int $queueId)` — L1589 (private)
SELECT полной строки `notification_delivery_queue` по id с декодированием push_data_json; null, если нет.

#### `getChannelLabels()` — L1648 (private)
Русские label'ы каналов для лога доставок.

#### `getStatusLabels()` — L1657 (private)
Русские label'ы статусов (sent/failed/skipped/deferred/digest).

#### `sanitizeIdList(array $ids)` — L1667 (private)
Фильтрует список id: только положительные int, уникальные.

#### `normalizeEmailDigestMode($value, string $fallback = 'instant')` — L1678 (private)
Приводит значение к 'instant'|'daily', иначе fallback.

#### `isInQuietHoursAtTime(DateTimeImmutable $localTime, string $start, string $end)` — L1683 (private)
Чистая проверка попадания локального времени в окно [start, end) с поддержкой перехода через полночь; start==end → false.

#### `calculateQuietHoursResumeLocal(DateTimeImmutable $localTime, string $start, string $end)` — L1697 (private)
Вычисляет локальный момент окончания quiet hours (учитывает overnight-окно: если ещё до полуночи — конец завтра).

#### `isInQuietHoursFromSettings(array $settings)` — L1715 (private)
Проверка quiet hours по уже загруженным настройкам (без повторного запроса к БД); строит DateTime в таймзоне юзера. Используется в canDeliver.

## `planrun-backend/services/NotificationTemplateService.php` (618 строк)
Шаблоны уведомлений: дефолтные runtime-шаблоны (title/link/email_action_label/push_data) по event_key + админ-переопределения в таблице `notification_template_overrides` с {{placeholder}}-рендерингом.

### class NotificationTemplateService — L5
Наследует BaseService. Статический $schemaEnsured + кэш всех overrides ($overrideCache).

#### `ensureSchema()` — L9
CREATE TABLE IF NOT EXISTS `notification_template_overrides`; бросает 500 при ошибке.

#### `prepare(string $eventKey, string $title, string $body, array $options = [])` — L35
Главный метод (вызывается NotificationDispatcher): берёт дефолтный шаблон (`getDefaultRuntimeTemplate()`), строит контекст плейсхолдеров, накладывает override из БД (title/body/link/email_action_label через `renderTemplate()`); generic-заголовки заменяет шаблонными (`shouldReplaceTitle()`); мёржит push_data и проставляет link. Возвращает ['title','body','options'].

#### `getAdminTemplateCatalog()` — L95
Каталог редактируемых шаблонов для админки: группы из `NotificationSettingsService::getEventCatalog()`, по каждому событию — placeholders, defaults (из `getEditableDefinitionMap()`) и текущие overrides из БД.

#### `saveOverride(string $eventKey, array $payload, int $adminUserId)` — L151
Валидирует event_key по карте редактируемых, санитизирует 4 шаблона; если все пустые — `resetOverride()`; иначе UPSERT в `notification_template_overrides` с updated_by=admin. Сбрасывает кэш, возвращает конфиг события (`getTemplateConfigByEventKey()`).

#### `resetOverride(string $eventKey)` — L209
DELETE override из таблицы + сброс кэша.

#### `getTemplateConfigByEventKey(string $eventKey)` — L228
Ищет событие в `getAdminTemplateCatalog()`; бросает 404, если не найдено.

#### `getDefaultRuntimeTemplate(string $eventKey, array $options)` — L241 (private)
match по event_key → дефолтные title/link/email_action_label/push_data для ~15 событий (workout reminders, chat, plan.*, coach.athlete_result_logged, performance.vdot_updated); динамика через `buildPlanUpdateTitle()`/`buildAthleteResultTitle()`/`buildVdotTitle()`/`buildCalendarLink()`. default-ветка пробрасывает значения из options.

#### `getEditableDefinitionMap()` — L348 (private static)
Статическая карта 14 редактируемых событий: дефолтные title/body/link/email_action_label-шаблоны и допустимые placeholders для админки.

#### `shouldReplaceTitle(string $eventKey, string $currentTitle, string $templateTitle)` — L458 (private)
true, если текущий заголовок пуст или равен одному из generic-вариантов для данного события (тогда берётся title из шаблона).

#### `buildTemplateContext(string $eventKey, string $title, string $body, array $template, array $options)` — L489 (private)
Собирает контекст плейсхолдеров: app_name, title, body, link, sender_name, plan_action/plan_date/workout_date, athlete_name/slug, source_type + производные plan_update_title/athlete_result_title/vdot_title.

#### `buildPlanUpdateTitle(string $planAction)` — L513 (private)
match: add/delete/copy → «Тренер добавил/удалил/скопировал тренировку», иначе «Тренер обновил план».

#### `buildAthleteResultTitle(string $athleteName)` — L522 (private)
«{Имя}: новый результат» или «Атлет внёс результат».

#### `buildVdotTitle(string $sourceType)` — L526 (private)
match: race/control → «VDOT обновлён после забега/контрольной», иначе «VDOT обновлён».

#### `buildCalendarLink(string $date = '', string $athleteSlug = '')` — L534 (private)
Строит '/calendar?athlete=…&date=…' через http_build_query.

#### `contextValue(array $options, string $key)` — L546 (private)
Достаёт значение из options.template_context либо напрямую из options; trim-строка.

#### `renderTemplate(string $template, array $context)` — L552 (private)
preg_replace_callback подстановка `{{key}}` из контекста; неизвестные ключи → пустая строка.

#### `getOverride(string $eventKey)` — L559 (private)
Один override из кэша всех (`getAllOverrides()`).

#### `getAllOverrides()` — L564 (private)
SELECT всей таблицы `notification_template_overrides` в кэш (event_key → шаблоны/updated_by/updated_at).

#### `sanitizeNullableTemplate($value, int $maxLength)` — L606 (private)
trim; пустое → null; иначе обрезка до maxLength.

## `planrun-backend/services/PlanExplanationService.php` (292 строки)
Генерирует человеко-читаемое (RU) объяснение «почему план такой» для metadata.explanation: сводка по training state (VDOT, readiness, самочувствие) + контур первой недели плана. Без LLM — чисто детерминированные текстовые правила.

### class PlanExplanationService — L7
Наследует BaseService.

#### `buildExplanation(int $userId, string $jobType, array $payload, array $planData, ?array $trainingState = null)` — L8
Единственный public-метод: берёт training state (переданный или собранный `TrainingStateBuilder::buildForUserId()` — чтение многих таблиц), извлекает контур плана, входные сигналы и summary. Возвращает {summary, inputs, plan_outline, readiness, vdot, overall_signal_risk}. Вызывается из PlanGenerationProcessorService::attachGenerationExplanation.

#### `extractPlanOutline(array $planData)` — L27 (private)
По первой неделе плана считает: weeks_count, объём недели (weekly_target_km/total_km/сумма дней), число quality-дней (tempo/interval/fartlek/control/race), rest-дней и км длительной.

#### `buildInputSignals(string $jobType, array $payload, array $state)` — L81 (private)
Список фраз-«входов»: readiness, VDOT-сигнал (`buildHumanFormSignal()`), сводка самочувствия (`buildHumanSignalSummary()`), reason/goals из payload, фактический объём 4 недель при recalculate.

#### `buildSummary(string $jobType, array $payload, array $state, array $outline, array $inputs)` — L116 (private)
Склеивает state-предложение + outline-предложение; при пустых — дефолтная фраза по jobType. Параметры $payload/$inputs фактически не используются в теле.

#### `buildHumanSignalSummary(array $state)` — L140 (private)
Текст по feedback_analytics (pain/fatigue/risk_level) и счётчикам заметок athlete_signals (сон, стресс, поездки, болезнь).

#### `formatReadinessLabel(string $readiness)` — L191 (private)
high/low/normal → «высокая/сниженная/нормальная».

#### `formatRiskLevelLabel(string $riskLevel)` — L199 (private)
high/moderate → «напряжённый/умеренно напряжённый», иначе «спокойный».

#### `formatQualityDaysLabel(int $count)` — L207 (private)
Русская словоформа для 0–4+ интенсивных тренировок.

#### `buildHumanOutlineSummary(array $outline, string $jobType)` — L218 (private)
Фраза про объём/длительную/quality первой недели с лид-словом по jobType («В ближайших днях…» / «В начале следующего блока…»).

#### `buildHumanStateSummary(array $state)` — L243 (private)
Два предложения: о форме (VDOT-сигнал или по readiness) и об осторожности (боль/усталость/риск/восстановление).

#### `buildHumanFormSignal(float $vdot, string $source)` — L277 (private)
Фраза о форме в зависимости от источника VDOT (забег/контрольная — порог 48.0; easy-тренировки; иначе нейтрально).

## `planrun-backend/services/PlanGenerationProcessorService.php` (2274 строки)
Исполнитель задач генерации/пересчёта плана из очереди: LLM-планировщик (DeepSeek через llm_planner) или legacy-pipeline, quality gate, детерминированные safety-repairs, critique-pass, OFP-enrichment, сохранение плана и snapshot'ов, ревью-сообщение в чат.

### class PlanGenerationProcessorService — L18
Наследует BaseService.

#### `process(int $userId, string $jobType = 'generate', array $payload = [], ?int $jobId = null)` — L19
Оркестратор: создаёт trace (AiObservabilityService), выбирает путь по env PLAN_GENERATION_MODE (llm_planner → `processViaLlmPlanner()`; иначе legacy generatePlanViaPlanRunAI / recalculatePlanViaPlanRunAI / generateNextPlanViaPlanRunAI). Далее: critique-pass, повторный `ensureIntermediateRacesInPlan()`, OFP-enrichment + fallback, explanation, сохранение (saveTrainingPlan/saveRecalculatedPlan + UserRepository.update training_start_date), `syncLatestTrainingPlanSnapshot()`, `appendPlanReview()` (сообщение в чат), `persistPlanSummary()`. Логирует success/failure события в AiPlanGenerationEventLogger и observability-событие в finally. Возвращает payload с weeks_count/metadata, при ошибке пробрасывает исключение.

#### `processViaLlmPlanner(int $userId, string $jobType, array $payload, ?string $traceId = null)` — L270 (private)
Production-путь: обогащает payload (`enrichRecalculatePayload()`/`enrichNextPlanPayload()`), вызывает `DeepSeekPlanPlanner::generate()` (HTTP к DeepSeek через LlmGateway), применяет `enforceRaceDayConsistency()`, hard safety repairs, прогоняет PlanQualityGate (режим из `resolveQualityGateMode()`); при should_block_save бросает 500. Собирает финальные _generation_metadata (generator, quality_gate, macro_plan) и возвращает {plan, training_state, usage, start_date/cutoff_date/kept_weeks/mutable_from_date}.

#### `applySinglePassHardSafetyRepairs(array $plan, array $state, string $startDate)` — L399 (private)
«Медицинские» repairs только для марафона: cap длительной ≤32 км в последние 21 день до старта и cap доли длительной ≤60% (макс. 65%) недельного объёма; пересчитывает duration/notes/target_volume_km. Возвращает [plan, repairs[]].

#### `buildMacroPlanMetadataFromWeeks(array $weeks)` — L544 (private)
Сводка macro_plan по неделям: phase, суммарный объём (`sumWeekDistances()`), длительная, risk_note. Для metadata.

#### `sumWeekDistances(array $days)` — L571 (private)
Сумма км по дням недели через `resolvePlanDayDistanceKm()`, округление до 0.1.

#### `resolvePlanDayDistanceKm(array $day)` — L582 (private)
Км дня: distance_km; для interval/fartlek — calculateIntervalTotalKm/calculateFartlekTotalKm (training_utils); иначе парсинг «N км» из description; 0.0 по умолчанию.

#### `envBool(string $key, bool $default)` — L603 (private)
Чтение boolean из env ('1/true/yes/on' / '0/false/no/off').

#### `resolveQualityGateMode(string $configMode, array $user, array $state)` — L646 (private)
P0.3 auto-режим quality gate: strict при рисковых special_population_flags (беременность, return_after_injury, pain/illness signal), protective-сценариях или goal_realism.severity=major; иначе permissive. Возвращает [mode, reason].

#### `buildQualityGateFailureMessage(array $issues)` — L689 (private)
Склеивает до 3 сообщений blocking-issues (severity=error) в строку через ' | '.

#### `enforceRaceDayConsistency(array $plan, array $trainingState, array $user, ?string $startDate = null, bool $capRaceWeekSupplementary = false)` — L703 (private)
Гарантирует консистентность race-дней: вычисляет дистанцию/целевой темп, ставит главный старт на календарную дату (`placeRaceOnCalendarDate()`), опционально режет объём race-недели (`capRaceWeekSupplementaryVolume()`), нормализует все race-дни (distance/pace/duration/is_key_workout), пересчитывает объёмы недель и принудительно восстанавливает intermediate races.

#### `ensureIntermediateRacesInPlan(array $plan, array $intermediateRaces)` — L784 (private)
Safety-net: для каждого промежуточного забега из state проверяет наличие race-дня с правильной дистанцией (manual user input защищён, mismatch >0.6 км → force-fix); при отсутствии переписывает день на race с дистанцией/notes. Идемпотентен, пишет error_log при force.

#### `capRaceWeekSupplementaryVolume(array $plan, array $trainingState)` — L873 (private)
Находит неделю с race; если суммарный объём остальных дней превышает cap (resolveRaceWeekSupplementaryCap от объёма предыдущей недели) — пропорционально уменьшает дистанции не-race дней и пересчитывает объёмы.

#### `placeRaceOnCalendarDate(array $plan, string $startDate, string $raceDate, float $raceDistanceKm, ?string $goalPace, array $intermediateRaceDates = [])` — L932 (private)
Проставляет date каждому дню от startDate, ставит race на целевую неделю/день недели (с дистанцией/темпом/notes «Главный старт»), лишние race-дни (кроме intermediate) конвертирует в rest. Safety-net: если дата старта вне горизонта плана — force-ставит race на последнюю неделю (error_log).

#### `resolveRaceDistanceKm(string $raceDistance)` — L1055 (private)
match '5k'/'10k'/'half|21.1k'/'marathon|42.2k' → км; иначе 0.

#### `enrichRecalculatePayload(int $userId, array $payload)` — L1070 (private)
Дозаполняет payload пересчёта: cutoff_date (понедельник, `resolveDefaultRecalculateCutoffDate()`), kept_weeks (WeekRepository::getMaxWeekNumberBefore), current_phase (detectCurrentPhase), actual_weekly_km_4w (агрегация беговых км из `workout_log`+`workouts` за 28 дней), mutable_from_date (resolveRecalculationCutoffDateValue + `hasRunningWorkoutOnDate()`), progression_counters и continuation_context.

#### `enrichNextPlanPayload(int $userId, array $payload)` — L1182 (private)
Для next_plan: cutoff_date (понедельник), last_plan_avg_km и recent_plan_weeks из WeekRepository::getRecentWeekSummaries (4 последних недели), continuation_context. Содержит inline-колбэк формирования recent_plan_weeks (L1210).

#### `resolveDefaultRecalculateCutoffDate(int $userId, array $planningUser)` — L1228 (private)
max(понедельник текущей недели, выровненный к понедельнику старт плана из WeekRepository::getPlanDateRange либо users.training_start_date).

#### `buildRecalculateContinuationContext(array $payload)` — L1252 (private)
Компактный контекст для планировщика: mode=recalculate, anchor_date, kept_weeks, current_phase, actual_weekly_km_4w, progression_counters.

#### `buildNextPlanContinuationContext(array $payload)` — L1264 (private)
То же для next_plan: anchor_date, last_plan_avg_km, recent_plan_weeks.

#### `buildCompletedProgressionCounters(int $userId, string $cutoffDate)` — L1274 (private)
Читает quality-дни (tempo/interval/fartlek/control) из `training_plan_days` до cutoff, пересекает с фактически выполненными датами (`loadCompletedRunningDateSet()`) и возвращает счётчики tempo/interval/fartlek/control/completed_key_days + race_pace_count.

#### `loadCompletedRunningDateSet(int $userId, string $fromDate, string $toDate)` — L1356 (private)
Set дат с беговыми активностями из `workout_log` (is_completed=1, фильтр `isRunningRelevantManualActivity()`) и `workouts` (фильтр `isRunningRelevantImportedActivity()`).

#### `loadPlanningUserProfile(int $userId)` — L1412 (private)
UserRepository::getForPlanning + декодирование JSON-полей preferred_days/preferred_ofp_days в массивы.

#### `alignDateToMonday(string $date)` — L1431 (private)
Сдвигает дату к понедельнику её ISO-недели; null при пустой/невалидной дате.

#### `hasRunningWorkoutOnDate(int $userId, string $date)` — L1452 (private)
true, если на дату есть релевантная беговая запись в `workout_log` или `workouts` (через isRunningRelevantWorkoutEntry из training_utils).

#### `isRunningRelevantManualActivity(string $activityType)` — L1510 (private)
true для пустого типа или running/run/trail running/treadmill.

#### `isRunningRelevantImportedActivity(string $activityType)` — L1519 (private)
Идентичная копия предыдущего метода (дубликат байт-в-байт, различие только семантическое — manual vs imported).

#### `persistFailure(int $userId, string $message)` — L1528
Public: фиксирует ошибку генерации в `user_training_plans` — UPDATE последнего snapshot (is_active=FALSE, error_message) либо создание нового неактивного snapshot с ошибкой. Вызывается воркером очереди и API при провале job.

#### `loadUserPreferences(int $userId)` — L1553 (private)
preferred_days/preferred_ofp_days из users (через UserRepository::getField + `decodeWeekdayPreferenceField()`); null, если preferred_days пуст (нет ограничений расписания).

#### `decodeWeekdayPreferenceField(mixed $raw)` — L1572 (private)
Массив строк из array|JSON-string; пустые значения отфильтровываются.

#### `getUserRepository()` — L1594 (private)
Фабрика UserRepository.

#### `getWeekRepository()` — L1598 (private)
Фабрика WeekRepository.

#### `enrichPlanWithOfpAndSbu(array $planData, int $userId)` — L1611 (private)
Step 3: LLM-enricher ОФП/СБУ — загружает users.* и активную `exercise_library`, вызывает enrichPlanWithOfp (LLM, planrun_ai/ofp_enricher.php); полученные сессии инжектит в rest/free/пустые other|sbu дни (type, description через WorkoutBuilderService::buildOfpDescription, duration, exercises). Не бросает — при ошибке логирует и возвращает план как есть.

#### `ensureOfpDaysInPlan(int $userId, array $planData)` — L1701 (private)
Детерминированный fallback: если ofp_preference задан, force-конвертирует rest/free (и пустые other/sbu) дни в preferred_ofp_days в type=other с шаблонной сессией WorkoutBuilderService::buildOfpSession/buildOfpDescription. Читает users (ofp_preference, preferred_ofp_days, weight_kg, experience_level).

#### `applyPlanCritique(array $planData, int $userId, string $mode, ?array $trainingState)` — L1785 (private)
Self-critique pass (env PLAN_CRITIQUE_ENABLED): собирает контекст (WorkoutAnalysisRepository rollup/key workouts, ChatContextBuilder::calculateACWR), вызывает runPlanSelfCritique (LLM); при should_revise — revisePlanWithCritique (второй LLM-вызов). Результат критики кладёт в metadata.critique. Никогда не бросает. Параметр $trainingState не используется в теле.

#### `persistPlanSummary(int $userId, array $planData)` — L1854 (private)
UPDATE `users`: last_plan_summary, last_plan_risk_review_json (risk_review + critique одним JSON), last_plan_generated_at — чтобы чат видел сводку плана.

#### `syncLatestTrainingPlanSnapshot(int $userId, ?string $startDate, array $planData)` — L1885 (private)
Актуализирует последний snapshot в `user_training_plans` (start_date, marathon_date, target_time, is_active=TRUE, plan_description) либо создаёт новый; деактивирует остальные (`deactivateOtherTrainingPlanSnapshots()`).

#### `findLatestTrainingPlanSnapshotId(int $userId)` — L1924 (private)
id последней строки `user_training_plans` юзера или null.

#### `resolveTrainingPlanSnapshotStartDate(array $user, ?string $startDate)` — L1938 (private)
startDate → users.training_start_date → сегодня.

#### `resolveTrainingPlanSnapshotTargets(array $user)` — L1950 (private)
[planDate, targetTime] по goal_type: race/time_improvement → race_date+race_target_time (форматирование), weight_loss → weight_goal_date, иначе [null, null].

#### `formatTrainingPlanSnapshotTargetTime(?string $rawTime)` — L1969 (private)
Нормализует 'H:MM:SS'/'MM:SS' (срез ведущего нуля часов); невалидное — как есть, пустое — null.

#### `resolveTrainingPlanSnapshotDescription(array $planData)` — L1992 (private)
metadata.explanation.summary или null.

#### `createTrainingPlanSnapshot(int $userId, ?string $startDate, ?string $planDate, ?string $targetTime, ?string $errorMessage, ?string $planDescription, bool $isActive)` — L2001 (private)
INSERT в `user_training_plans`; возвращает id или null.

#### `deactivateOtherTrainingPlanSnapshots(int $userId, int $activePlanId)` — L2028 (private)
UPDATE is_active=FALSE для всех прочих активных snapshot'ов юзера.

#### `appendPlanReview(int $userId, array $planData, string $reviewStartDate, string $mode, ?array $realismContext = null)` — L2041 (private)
Генерирует ревью плана через generatePlanReview (LLM, plan_review_generator.php), при пустом — `buildFallbackPlanReview()`; добавляет «Коротко почему: …» из explanation; отправляет AI-сообщение в чат юзеру через ChatService::addAIMessageToUser с event_key plan.generated/recalculated/next_generated. Ошибки только логирует.

#### `buildFallbackPlanReview(array $planData, string $reviewStartDate, string $mode, ?array $realismContext = null)` — L2089 (private)
Детерминированное ревью без LLM: число недель, день длительной, rest-дни первой недели (через normalizeTrainingPlan), сухой факт про realism-таргет (`renderRealismFactLineForFallback()`).

#### `syncRaceTargetTimeIfAdjusted(int $userId, ?array $realismContext)` — L2163 (private)
UPDATE users.race_target_time на effective_target_time, если AI скорректировал цель. НЕ вызывается нигде (мёртвый код): в `process()` комментарии явно говорят «не sync'аем race_target_time — это user intent».

#### `buildRealismContextForReview(?array $trainingState)` — L2182 (private)
Из training_state.pace_strategy строит компактный realism-контекст для plan_review: severity, mode, gap_pct, goal/predicted/effective target time/pace, race_distance(+label), current_vdot; null без strategy.

#### `renderRealismFactLineForFallback(?array $realism)` — L2223 (private)
Строка «Цель в профиле … план рассчитан на реалистичный таргет …» только при severity major/moderate и различающихся goal/effective; иначе ''.

#### `formatWeeksLabel(int $weeksCount)` — L2243 (private)
Русская словоформа «неделя/недели/недель».

#### `attachGenerationExplanation(int $userId, string $jobType, array $payload, array $planData, ?array $trainingState = null)` — L2257 (private)
Вызывает `PlanExplanationService::buildExplanation()` и кладёт результат в metadata.explanation; ошибки только логирует.

## `planrun-backend/services/PlanGenerationQueueService.php` (320 строк)
Очередь задач генерации плана (таблица `plan_generation_jobs`): enqueue с дедупликацией, резервация (FOR UPDATE SKIP LOCKED с legacy-fallback), recovery зависших, статусы completed/failed с ретраями.

### class PlanGenerationQueueService — L8
Наследует BaseService.

#### `const TABLE` — L9 (private)
'plan_generation_jobs'.

#### `const STATUS_PENDING / STATUS_RUNNING / STATUS_COMPLETED / STATUS_FAILED` — L10–L13 (private)
Строковые статусы задач.

#### `enqueue(int $userId, string $jobType = 'generate', array $payload = [], int $maxAttempts = 5)` — L15
Проверяет доступность таблицы; при активной задаче юзера (`findActiveJobForUser()`) возвращает deduplicated=true; иначе INSERT pending-задачи с payload_json. Возвращает {job_id, queued, deduplicated, status}.

#### `reserveNextJob()` — L62
Резервирует следующую pending-задачу: сначала `recoverStaleRunningJobs()`, затем транзакционный путь (`reserveNextJobWithLock()`) с fallback на `reserveNextJobLegacy()` при несовместимости SKIP LOCKED (`isSkipLockedCompatibilityError()`). Возвращает строку job или null.

#### `reserveNextJobWithLock()` — L82 (private)
SELECT … FOR UPDATE SKIP LOCKED внутри транзакции + `markJobRunning()`; commit/rollback; возвращает job с инкрементом attempts.

#### `reserveNextJobLegacy()` — L117 (private)
Без блокировок: SELECT первой pending + атомарный `markJobRunning()` (защита условием status=pending).

#### `markJobRunning(int $jobId)` — L140 (private)
UPDATE pending→running (started_at=NOW(), attempts+1, last_error=NULL); true при affected_rows>0.

#### `recoverStaleRunningJobs(?int $timeoutSeconds = null)` — L158
Зависшие running старше таймаута (env PLAN_GENERATION_RUNNING_TIMEOUT_SECONDS, мин 60): при attempts<max → обратно в pending (requeue), иначе → failed. Возвращает {requeued, failed}, логирует.

#### `markCompleted(int $jobId, array $result = [])` — L209
UPDATE → completed с result_json и finished_at.

#### `markFailed(int $jobId, string $errorMessage, int $attempts, int $maxAttempts, int $retryDelaySeconds = 300)` — L224
При attempts<max → pending с available_at=NOW()+delay (ретрай), иначе → failed с finished_at; пишет last_error.

#### `findLatestActiveJobForUser(int $userId)` — L245
Последняя pending|running задача юзера или null (для API-статуса).

#### `findLatestJobForUser(int $userId)` — L262
Последняя задача юзера любого статуса или null.

#### `isQueueAvailable()` — L277
SHOW TABLES LIKE 'plan_generation_jobs' — существует ли таблица.

#### `findActiveJobForUser(int $userId)` — L282 (private)
Как findLatestActiveJobForUser, но возвращает только id/status/job_type (для дедупликации в enqueue).

#### `assertQueueTableAvailable()` — L299 (private)
Бросает 503 с подсказкой про migrate_all.php, если таблицы нет.

#### `canUseTransactionalReservation()` — L308 (private)
method_exists-проверка begin_transaction/commit/rollback у db-обёртки.

#### `isSkipLockedCompatibilityError(Throwable $e)` — L314 (private)
Эвристика по тексту ошибки ('skip locked'/'for update'/'syntax') — старый MySQL без SKIP LOCKED.

## `planrun-backend/services/PlanNotificationService.php` (229 строк)
In-app уведомления тренер↔атлет поверх таблицы `plan_notifications`: создание (через NotificationService → доставка), чтение ленты, отметка прочитанным. Маппит внутренние типы на event_key каталога.

### class PlanNotificationService — L7
Без наследования; хранит $db.

#### `__construct($db)` — L10
Сохраняет соединение с БД.

#### `notify($userId, $type, $message, $metadata = null)` — L21
Маппит type на event_key (`mapTypeToEventKey()`), строит link на /calendar (athlete/date), вызывает `NotificationService::create()` с dispatch_options (plan_action, plan_date, athlete_slug/name через `getUsername()`, push_data). Незамапленные типы — только in-app (dispatch=false).

#### `notifyCoachPlanUpdated($athleteId, $coachId, $action = 'update', $date = null)` — L67
Уведомляет атлета «Тренер {имя} добавил/обновил/удалил/скопировал/оставил заметку…»; делегирует в `notify()` c type=coach_plan_updated.

#### `notifyAthleteResultLogged($athleteId, $date = null)` — L94
Уведомляет всех тренеров атлета (`getCoachesForAthlete()`) «Атлет {имя} внёс результат…»; type=athlete_result_logged с athlete_slug для ссылки.

#### `getUnread($userId, $limit = 20)` — L114
SELECT непрочитанных строк `plan_notifications` (read_at IS NULL), metadata декодируется из JSON.

#### `getRecent($userId, $limit = 50, $sinceDays = 30)` — L139
Последние уведомления (включая прочитанные) за окно дней для notification center.

#### `markRead($notificationId, $userId)` — L163
UPDATE read_at=NOW() одной строки; true при affected_rows>0.

#### `markAllRead($userId)` — L176
UPDATE read_at=NOW() для всех непрочитанных юзера.

#### `getUsername($userId)` — L184 (private)
users.username или «Пользователь».

#### `getUsernameSlug($userId)` — L193 (private)
users.username_slug или null.

#### `getCoachesForAthlete($athleteId)` — L202 (private)
coach_id из `user_coaches` атлета; пустой массив, если таблицы нет (SHOW TABLES guard).

#### `mapTypeToEventKey(string $type, array $metadata)` — L218 (private)
athlete_result_logged → coach.athlete_result_logged; coach_plan_updated → plan.coach_note_added (action=note) | plan.coach_updated; иначе null.
