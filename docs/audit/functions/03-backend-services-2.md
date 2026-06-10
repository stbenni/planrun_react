# Backend services 2/6 (ChatToolRegistry…MetricsService) — справочник функций

## `planrun-backend/services/ChatToolRegistry.php` (1111 строк)
Реестр и исполнитель инструментов (tools) AI-чата: определяет OpenAI-совместимые tool-схемы для LLM и выполняет вызовы tools по имени, делегируя в доменные сервисы (WeekService, WorkoutService, StatsService, TrainingPlanService и др.).

### class ChatToolRegistry — L11
Единственный класс файла. Принимает mysqli-соединение и ChatContextBuilder; используется ChatService в tool-loop диалога.

#### `__construct($db, ChatContextBuilder $contextBuilder)` — L16
Сохраняет соединение БД и контекст-билдер чата в поля.

#### `getChatTools()` — L21
Возвращает массив из 25 tool-определений (OpenAI function-calling формат) через хелпер `toolDef()`. Чистая функция без побочных эффектов.

#### `executeTool(string $name, string $argsJson, ?int $userId)` — L114
Декодирует JSON-аргументы (при ошибке парсинга логирует warning и подставляет пустой массив), прогоняет `resolveNaturalDateArgs()`, затем через `match` диспетчеризует в соответствующий `execute*`-метод; неизвестный tool → `{"error":"unknown_tool"}`. Возвращает JSON-строку результата.

#### `toolDef(string $name, string $description, array $properties, array $required = [])` — L179 (private)
Хелпер: собирает структуру `['type'=>'function','function'=>...]` для одного tool-определения.

#### `requireUser(?int $userId)` — L184 (private)
Возвращает JSON-ошибку `user_required`, если userId не задан, иначе null. Гард в начале каждого tool-обработчика.

#### `requireAiPlanMode(?int $userId)` — L192 (private)
Читает `users.training_mode`; если режим не `ai` — возвращает JSON-ошибку `not_ai_mode` (правка плана через AI разрешена только в режиме AI-тренера). Гард для всех мутирующих план tools.

#### `validateDate(string $date)` — L214 (private)
Проверка формата даты `Y-m-d` регэкспом.

#### `formatDateRu(string $date)` — L218 (private)
Преобразует `Y-m-d` → `d.m.Y` для русскоязычных сообщений.

#### `getUserTz(?int $userId)` — L223 (private)
Возвращает DateTimeZone пользователя через `getUserTimezone()` (user_functions.php), фолбэк Europe/Moscow.

#### `resolveNaturalDateArgs(array &$args, ?int $userId)` — L232 (private)
Для всех date-ключей аргументов (`date`, `date1`, `source_date` и т.п.) не в формате Y-m-d пытается разрешить естественную дату через DateResolver относительно «сегодня» в TZ юзера; мутирует $args.

#### `getDayPlanDataByDate(int $userId, string $date)` — L253 (public)
Читает `training_plan_days` JOIN `training_plan_weeks`: id/type/description последнего plan-day на дату. Используется в swap.

#### `findDayIdByDate(int $userId, string $date)` — L268 (public)
То же, но возвращает только id plan-day или null.

#### `executeGetDate(array $args, ?int $userId)` — L285 (private)
Tool-обработчик: резолвит текстовую фразу («завтра», «в среду») в Y-m-d через DateResolver в TZ пользователя.

#### `executeGetPlan(array $args, ?int $userId)` — L295 (private)
Читает неделю плана через WeekRepository (по week_number, date или «сегодня») и возвращает дни недели с русскими типами. Только чтение БД.

#### `executeGetWorkouts(array $args, ?int $userId)` — L328 (private)
История выполненных тренировок за период через `ChatContextBuilder::getWorkoutsHistory()` (лимит 100); форматирует темп/пульс/ощущения, режет notes до 300 символов, считает суммарный км.

#### `executeGetDayDetails(array $args, ?int $userId)` — L359 (private)
Полные детали дня через `ChatContextBuilder::getDayDetails()`: план, упражнения, факт(ы). Если тренировок за день несколько — возвращает все с пометкой для модели. Содержит inline-замыкание `$formatWorkout` (L381) — форматирование одной тренировки в компактный массив.

#### `executeUpdateTrainingDay(array $args, ?int $userId)` — L417 (private)
Меняет тип/описание plan-day на дату через `WeekService::updateTrainingDayById()`; для `rest` зануляет is_key_workout. Требует режим ai. Пишет в `training_plan_days`.

#### `executeDeleteTrainingDay(array $args, ?int $userId)` — L441 (private)
Удаляет plan-day на дату через `WeekService::deleteTrainingDayById()`. Требует режим ai.

#### `executeMoveTrainingDay(array $args, ?int $userId)` — L458 (private)
Переносит тренировку: удаляет target-день, копирует source→target через `WeekService::copyDay()`, затем прямыми UPDATE/DELETE превращает source в чистый rest (очищает description и `training_day_exercises`), инвалидирует кэш `training_plan_{userId}`. Требует режим ai.

#### `executeSwapTrainingDays(array $args, ?int $userId)` — L503 (private)
Меняет местами type/description двух plan-days двумя вызовами `WeekService::updateTrainingDayById()`. Требует режим ai.

#### `executeRecalculatePlan(array $args, ?int $userId)` — L529 (private)
Запускает фоновый пересчёт плана через `TrainingPlanService::recalculatePlan()` (возвращает pid). Требует режим ai.

#### `executeGenerateNextPlan(array $args, ?int $userId)` — L541 (private)
Запускает генерацию нового плана через `TrainingPlanService::generateNextPlan()`. Требует режим ai.

#### `executeLogWorkout(array $args, ?int $userId)` — L553 (private)
Валидирует дистанцию (0.1–300 км), находит номер недели в `training_plan_weeks`, формирует result_time из минут и сохраняет результат через `WorkoutService::saveResult()` с пометкой «[из чата]». В ответе — резюме с темпом.

#### `executeGetStats(array $args, ?int $userId)` — L609 (private)
Статистика за период week/month/plan/all: `StatsService::getStats()` + `getAllWorkoutsList()` (до 500), фильтрация по дате, суммы км/часов, средний темп, тренд по неделям (последние 4).

#### `executeRacePrediction(array $args, ?int $userId)` — L648 (private)
Прогноз времени забега: берёт VDOT из `StatsService::getBestResultForVdot()`, считает предсказания `calculateVdotPredictions()` и зоны темпа `calculateVdotPaceZones()`.

#### `executeGetProfile(array $args, ?int $userId)` — L674 (private)
Профиль через `UserProfileService::getProfile()` с русификацией полов/уровней/целей; дополнительно читает `integration_tokens` (список подключённых интеграций).

#### `executeUpdateProfile(array $args, ?int $userId)` — L719 (private)
Обновляет одно из whitelist-полей профиля (PROFILE_FIELDS) через `UserProfileService::updateProfile()`.

#### `executeGetTrainingLoad(array $args, ?int $userId)` — L735 (private)
ATL/CTL/TSB через `TrainingLoadService::getTrainingLoad()` + ACWR через `contextBuilder->calculateACWR()`; маппит TSB в текстовый статус, прикладывает последние 7 TRIMP.

#### `executeAddTrainingDay(array $args, ?int $userId)` — L769 (private)
Добавляет новый plan-day на свободную дату через `WeekService::addTrainingDayByDate()`; отказывает, если день уже существует. Требует режим ai.

#### `executeCopyDay(array $args, ?int $userId)` — L788 (private)
Копирует тренировку source→target через `WeekService::copyDay()`. Требует режим ai.

#### `calculateVdotPredictions(float $vdot)` — L808 (public)
Чистая математика: квадратное уравнение Daniels (a=0.000104, b=0.182258) → прогноз времени для 5k/10k/half/marathon по %VO2max.

#### `calculateVdotPaceZones(float $vdot)` — L824 (public)
Та же формула для зон темпа easy/tempo/threshold/interval/repetition (% от VDOT 0.65–1.05).

#### `formatSeconds(int $totalSec)` — L843 (public)
Секунды → `H:MM:SS` либо `M:SS`.

#### `loadTrainingState(int $userId)` — L855 (private)
Лениво строит training_state через `TrainingStateBuilder::buildForUserId()` и кэширует в `$stateCache` на время запроса (тяжёлая сборка из многих таблиц). При ошибке логирует warning и кэширует пустой массив.

#### `executeGetPersonalRecords(array $args, ?int $userId)` — L867 (private)
Личные рекорды 5k/10k/half/marathon из `training_state['best_races']` (время, темп, VDOT, дата, количество записей).

#### `executeGetComplianceHistory(array $args, ?int $userId)` — L891 (private)
Срез `training_state['recent_compliance']` на N недель (1–12, дефолт 4).

#### `executeGetMacrocyclePhase(array $args, ?int $userId)` — L903 (private)
Из training_state вычисляет текущую неделю от training_start_date, ищет фазу макроцикла по диапазонам week_from/week_to, дни до гонки, recovery-недели.

#### `executeGetLoadPolicy(array $args, ?int $userId)` — L944 (private)
Возвращает параметры нагрузки из `training_state['load_policy']` (growth ratio, cutback, taper, минимумы км и т.д.).

#### `executeLogWellness(array $args, ?int $userId)` — L966 (private)
UPSERT в `daily_wellness` (INSERT … ON DUPLICATE KEY UPDATE c COALESCE — частичное обновление): сон/настроение/болезненность/стресс/энергия (1–5), RPE (1–10), notes до 500 символов. Содержит inline-клампер `$clamp`.

#### `executeGetWeather(array $args, ?int $userId)` — L1028 (private)
Прогноз погоды до 6 дней через `WeatherService::getForecastForUser()` (HTTP к погодному API внутри сервиса); добавляет advice_tags через `classifyConditions()`. Ошибки: weather_disabled / no_location.

#### `executeGetWellnessTrend(array $args, ?int $userId)` — L1065 (private)
Читает `daily_wellness` за N дней (1–30) и считает средние по каждой метрике.

### Зарегистрированные tools (getChatTools, L21–L111)
- **get_plan** — L23 — план тренировок на неделю (по week_number/date/сегодня); обработчик `executeGetPlan`.
- **get_workouts** — L27 — история выполненных тренировок за период; `executeGetWorkouts`.
- **get_day_details** — L31 — детали дня: план + упражнения + факт; `executeGetDayDetails`.
- **update_training_day** — L34 — изменить запланированную тренировку (с подтверждением); `executeUpdateTrainingDay`.
- **swap_training_days** — L39 — поменять местами тренировки двух дат; `executeSwapTrainingDays`.
- **delete_training_day** — L43 — удалить тренировку на дату; `executeDeleteTrainingDay`.
- **move_training_day** — L46 — перенести тренировку на другую дату (source → rest); `executeMoveTrainingDay`.
- **recalculate_plan** — L50 — пересчитать весь план (async, 3-5 мин); `executeRecalculatePlan`.
- **generate_next_plan** — L53 — сгенерировать новый план после завершения текущего; `executeGenerateNextPlan`.
- **log_workout** — L56 — записать результат тренировки (км, время, пульс, оценка); `executeLogWorkout`.
- **get_stats** — L64 — статистика объёмов/выполнения за week/month/plan/all; `executeGetStats`.
- **race_prediction** — L67 — прогноз времени на 5k/10k/half/marathon по VDOT; `executeRacePrediction`.
- **get_profile** — L70 — профиль пользователя + подключённые интеграции; `executeGetProfile`.
- **update_profile** — L71 — обновить одно поле профиля из whitelist; `executeUpdateProfile`.
- **get_training_load** — L75 — ATL/CTL/TSB + ACWR; `executeGetTrainingLoad`.
- **add_training_day** — L76 — добавить тренировку на свободную дату; `executeAddTrainingDay`.
- **copy_day** — L81 — скопировать тренировку на другую дату; `executeCopyDay`.
- **get_date** — L85 — преобразовать текстовую дату в Y-m-d; `executeGetDate`.
- **get_personal_records** — L88 — личные рекорды по дистанциям; `executeGetPersonalRecords`.
- **get_compliance_history** — L89 — выполнение плана по неделям; `executeGetComplianceHistory`.
- **get_macrocycle_phase** — L92 — фаза макроцикла, неделя N из M, дни до гонки; `executeGetMacrocyclePhase`.
- **get_load_policy** — L93 — параметры нагрузки (target volume, growth ratio…); `executeGetLoadPolicy`.
- **log_wellness** — L94 — UPSERT самочувствия (сон/настроение/стресс/RPE); `executeLogWellness`.
- **get_wellness_trend** — L104 — тренды самочувствия за 1–30 дней; `executeGetWellnessTrend`.
- **get_weather** — L107 — прогноз погоды до 6 дней с advice_tags; `executeGetWeather`.

Константы: `ALLOWED_TYPES` — L163 (допустимые типы тренировок), `PROFILE_FIELDS` — L165 (whitelist полей update_profile), `TYPE_RU` — L171 (русские названия типов).

## `planrun-backend/services/CoachEventsService.php` (373 строки)
Лента событий тренера за последние дни: новые загрузки тренировок атлетов, риски (низкий compliance / долгое отсутствие), неотвеченные сообщения, свежие личные рекорды. Используется CoachController.getEvents.

### class CoachEventsService — L21 (extends BaseService)

#### `getEvents(int $coachId, int $hoursBack = 48)` — L30
Собирает события из четырёх коллекторов (uploads, risks, questions ≥72ч, PRs ≥168ч), сортирует по created_at DESC. Возвращает `{events: [...]}`.

#### `collectUploads(int $coachId, int $hoursBack)` — L48 (private)
Читает `workouts` JOIN `user_coaches` JOIN `users`: тренировки атлетов за последние N часов (LIMIT 30), формирует события kind=upload с CTA «Похвалить».

#### `collectRisks(int $coachId)` — L94 (private)
SQL с подзапросами по `training_plan_days` (план недели) и `workouts` (выполнено за неделю, последняя активность): атлеты с compliance < 50% или >7 дней без активности → события kind=risk (tone warn/danger), CTA «Связаться».

#### `collectQuestions(int $coachId, int $hoursBack)` — L165 (private)
Читает `chat_messages`/`chat_conversations` (type='admin'): неотвеченные сообщения атлетов (нет более позднего сообщения от admin в той же беседе), по одному событию на атлета, kind=question, CTA «Ответить».

#### `collectPRs(int $coachId, int $hoursBack)` — L228 (private)
Для каждого атлета тренера берёт `StatsService::getBestRacesProgression()` (52 недели) с кэшем `Cache::set` на 10 мин (`coach_pr_records_{id}`); записи с датой свежее cutoff → события kind=pr с временем и VDOT, CTA «Поздравить».

#### `formatPrTime(int $sec)` — L317 (private)
Секунды → `H:MM:SS`/`M:SS`, «—» при ≤0.

#### `prDistanceLabel(string $key)` — L326 (private)
Маппинг ключа дистанции (5k/10k/half/marathon) в русское название.

#### `activityTypeLabel(string $t)` — L333 (private)
Маппинг типа активности в русское название (фолбэк «Тренировка»).

#### `formatKm(float $km)` — L357 (private)
Километраж без лишних нулей (≥100 — целое).

#### `formatUploadDetail(?float $distKm, ?string $pace, int $durMin, ?int $hr)` — L362 (private)
Строка деталей загрузки: длительность · темп · ЧСС, разделённые «·».

## `planrun-backend/services/CoachService.php` (1341 строка)
Сервис тренерской платформы: каталог тренеров, запросы атлет→тренер, связи user_coaches, профиль тренера, список и детали атлетов, ценообразование, группы атлетов, админ-обработка заявок «стать тренером». Используется CoachController и AdminController.

### class CoachService — L8 (extends BaseService)

#### `nameFields(array $row)` — L11 (private)
Из строки users собирает first_name/last_name/name (полное имя) с null вместо пустых строк.

#### `listCoaches(array $filters, int $limit, int $offset)` — L24
Каталог тренеров: читает `users` (role='coach') с фильтрами specialization (JSON_CONTAINS) и accepts_new, считает total, для каждого подгружает прайс через `loadPricing()`. N+1 по pricing.

#### `createRequest(int $athleteId, int $coachId, string $message = '')` — L102
Создаёт запрос на тренировку: валидации (не сам себе, тренер существует и принимает, нет pending-дубликата, не связаны), INSERT в `coach_requests`. Возвращает id запроса.

#### `getRequests(int $coachId, string $status, int $limit, int $offset)` — L153
Список запросов тренеру с данными атлета (цель, дистанция, уровень) из `coach_requests` JOIN `users` + total.

#### `acceptRequest(int $coachId, int $requestId)` — L197
Принимает запрос: UPDATE `coach_requests` → accepted, INSERT IGNORE в `user_coaches` (can_view/can_edit=1), переводит атлета в `training_mode='coach'` (UPDATE users), best-effort уведомление атлету через NotificationService. Возвращает athleteId.

#### `rejectRequest(int $coachId, int $requestId)` — L254
Отклоняет pending-запрос (UPDATE `coach_requests` → rejected).

#### `getUserCoaches(int $userId)` — L273
Список тренеров атлета из `user_coaches` JOIN `users` (имя, аватар, био, специализация).

#### `removeCoachRelationship(int $currentUserId, ?int $coachId, ?int $athleteId)` — L301
Разрывает связь (DELETE из `user_coaches`; вызывается и атлетом, и тренером); при разрыве откатывает training_mode атлета coach→self (UPDATE users). 404 если связи нет.

#### `applyAsCoach(int $userId, array $input)` — L333
Заявка «стать тренером»: проверки (не тренер, нет pending-заявки), валидация специализации/био (100–500 симв.)/опыта (1–50 лет), INSERT в `coach_applications` (включая pricing JSON).

#### `getMyCoachProfile(int $userId)` — L403
Coach-поля собственного профиля из `users` + прайс через `loadPricing()`.

#### `updateCoachProfile(int $userId, array $input)` — L434
Частичный UPDATE coach-полей `users` (динамический SET по присутствующим ключам) с теми же валидациями био/специализации/опыта; только для role coach/admin.

#### `getCoachAthletes(int $coachId)` — L494
Главный список атлетов тренера: один большой SQL по `user_coaches`/`users` с подзапросами — last_activity (UNION workout_log+workouts), week_total / week_total_so_far (по `training_plan_days`), week_completed (UNION с дедупликацией по дате), unread_results (по `plan_notifications`). Затем подгружает группы (`coach_group_members`/`coach_athlete_groups`) и обогащает через `enrichAthletesWithPlanAndVolume()` и `enrichAthletesWithVdot()`.

#### `getAthleteDetails(int $coachId, int $athleteId, ?string $weekStart = null)` — L635
Drill-in атлета: проверка связи в `user_coaches` (404 иначе), затем недельный план, 8 недель объёма, история VDOT, последние заметки.

#### `getAthleteWeekPlan(int $athleteId, string $weekStart, string $weekEnd)` — L660 (private)
7 дней: план из `training_plan_days` + факт (UNION `workouts`+`workout_log` с суммированием км по дате, CONVERT/COLLATE для pace); собирает день за днём с label/completed/distance_done.

#### `getAthleteVolumeWeeks(int $athleteId, int $weeks)` — L732 (private)
Сумма км по календарным неделям (пн–вс) за N недель из UNION `workouts`+`workout_log`, группировка в PHP.

#### `getAthleteVdotHistory(int $athleteId, int $limit)` — L771 (private)
VDOT-точки из забегов `workout_log` за 6 месяцев: парсит result_time и считает VDOT через `MetricsService::estimateVdot()`; сортирует хронологически.

#### `parseTimeToSeconds(string $t)` — L810 (private)
Парсинг `H:MM:SS`/`M:SS`/числа в секунды.

#### `getAthleteRecentNotes(int $athleteId, int $coachId, int $limit)` — L818 (private)
Последние заметки из `plan_day_notes` от тренера или самого атлета, с флагом author_is_coach.

#### `enrichAthletesWithPlanAndVolume(array &$athletes, array $athleteIds, string $today)` — L852 (private)
Двумя batch-запросами добавляет каждому атлету today_plan (из `training_plan_days` на сегодня) и volume_spark/volume_7d (7 дней км из UNION workouts+workout_log).

#### `enrichAthletesWithVdot(array &$athletes)` — L941 (private)
Добавляет vdot каждому атлету через `MetricsService::getVdot()` (N+1, ошибки молча пропускаются). Комментарий про self::$vdotCache устарел — кэша нет.

#### `getPricing(int $coachId)` — L960
Прайс тренера из `coach_pricing` (id, type, label, price, currency, period, sort_order).

#### `updatePricing(int $coachId, array $items, ?int $pricesOnRequest)` — L978
Опционально обновляет `users.coach_prices_on_request`, затем полная перезапись `coach_pricing` (DELETE + INSERT построчно, пропуская позиции без label).

#### `getGroups(int $coachId)` — L1012
Группы атлетов тренера из `coach_athlete_groups` LEFT JOIN `coach_group_members` с member_count.

#### `saveGroup(int $coachId, string $name, string $color, ?int $groupId)` — L1041
Создаёт или переименовывает группу (`coach_athlete_groups`); валидация имени ≤100, цвет hex или дефолт #6366f1.

#### `deleteGroup(int $coachId, int $groupId)` — L1070
Проверяет владение и удаляет членов (`coach_group_members`) + саму группу.

#### `getGroupMembers(int $coachId, int $groupId)` — L1084
Состав группы (с проверкой владения) из `coach_group_members` JOIN `users`.

#### `updateGroupMembers(int $coachId, int $groupId, array $userIds)` — L1113
Полная замена состава группы: валидирует userIds по `user_coaches` (только свои атлеты), DELETE всех + INSERT валидных. Возвращает число членов.

#### `getAthleteGroups(int $coachId, int $userId)` — L1150
Группы конкретного атлета у данного тренера.

#### `getApplications(string $status, int $limit, int $offset)` — L1180
Админка: список заявок «стать тренером» из `coach_applications` JOIN `users` (декодирует JSON-поля) + total.

#### `approveApplication(int $applicationId, int $reviewerId)` — L1219
Одобрение заявки: UPDATE `users` (role='coach' + coach-поля из заявки), копирование pricing-позиций в `coach_pricing`, UPDATE статуса заявки (approved + reviewed_by).

#### `rejectApplication(int $applicationId, int $reviewerId)` — L1287
Отклонение pending-заявки (UPDATE `coach_applications` → rejected).

#### `isCoachOrAdmin(int $userId)` — L1305
Проверка роли пользователя по `users.role` ∈ {coach, admin}.

#### `loadPricing(int $coachId)` — L1316 (private)
Прайс тренера без id/sort_order — для вложений в каталог/профиль.

#### `requireGroupOwnership(int $coachId, int $groupId)` — L1330 (private)
404, если группа не принадлежит тренеру.

## `planrun-backend/services/CoachTemplateService.php` (423 строки)
Шаблоны тренировок тренера и массовое назначение (bulk-assign) шаблона выбранным атлетам на дату; conflict-policy через preflight (overwrite=false → diff, повторный вызов с overwrite=true применяет). Используется CoachController.

### class CoachTemplateService — L17 (extends BaseService)

#### `getTemplates(int $coachId)` — L22
Все шаблоны тренера из `coach_workout_templates` (сортировка по uses_count DESC) + их упражнения одним IN-запросом из `coach_workout_template_exercises`, сгруппированные по template_id.

#### `createTemplate(int $coachId, array $data)` — L77
Создать/обновить шаблон: валидация name/type, при наличии template_id принадлежащего тренеру — UPDATE + полная перезапись упражнений (DELETE), иначе INSERT; затем `upsertExercises()`. Возвращает template_id.

#### `deleteTemplate(int $coachId, int $templateId)` — L134
DELETE шаблона по id+coach_id (упражнения остаются на FK/каскад — отдельного удаления нет).

#### `bulkAssign(int $coachId, int $templateId, array $athleteIds, string $date, bool $overwrite = false)` — L154
Массовое назначение: валидация даты, фильтрация атлетов по `user_coaches.can_edit`, поиск конфликтов (`findExistingPlanDays`); без overwrite возвращает diff конфликтов; с overwrite — в транзакции удаляет существующие дни, создаёт новые через `WeekService::addTrainingDayByDate()`, копирует упражнения, инкрементит uses_count. Возвращает assigned/overwritten/forbidden_count/errors.

#### `isValidType(string $type)` — L256 (private)
Whitelist из 12 типов тренировок.

#### `getTemplateOwned(int $coachId, int $templateId)` — L262 (private)
Шаблон по id, только если принадлежит тренеру.

#### `getTemplateExercises(int $templateId)` — L274 (private)
Упражнения шаблона в порядке order_index.

#### `filterAthletesCoachCanEdit(int $coachId, array $athleteIds)` — L289 (private)
Оставляет только атлетов из `user_coaches` с can_edit=1.

#### `findExistingPlanDays(array $athleteIds, string $date)` — L306 (private)
Map athleteId → существующий plan_day (`training_plan_days`) на дату.

#### `deletePlanDayWithExercises(int $dayId, int $userId)` — L326 (private)
Явное удаление `training_day_exercises` + `training_plan_days`.

#### `copyExercisesToDay(array $templateExercises, int $planDayId, int $userId)` — L342 (private)
INSERT упражнений шаблона в `training_day_exercises` нового дня.

#### `upsertExercises(int $templateId, array $exercises)` — L374 (private)
INSERT упражнений в `coach_workout_template_exercises` (вызывается после полной очистки при UPDATE).

#### `getUserNames(array $ids)` — L406 (private)
Map id → username из `users` (для отображения конфликтов).

## `planrun-backend/services/DateResolver.php` (123 строки)
Резолвер русскоязычных естественных дат («завтра», «в среду», «через неделю», «15 февраля») в Y-m-d относительно базовой даты. Без обращений к БД/сети.

### class DateResolver — L7
Статические словари: `$dayNames` — L9 (дни недели → ISO номер), `$monthNames` — L16 (месяцы → номер).

#### `resolveFromText(string $text, DateTime $relativeTo)` — L31
Каскад регэкспов: сегодня/вчера/позавчера/завтра/послезавтра → «через N дней» (0–365) → «через неделю» → день недели (с поддержкой «следующий») → «D месяца [год]» (2020–2030, checkdate). Возвращает Y-m-d или null. Вызывается ChatToolRegistry и ChatPromptBuilder.

#### `hasDateReference(string $text)` — L108
Быстрая проверка наличия отсылки к дате в тексте (набор регэкспов). Используется ChatPromptBuilder.

## `planrun-backend/services/EmailNotificationService.php` (140 строк)
Отправка нотификационных писем пользователю (одиночное уведомление и ежедневный дайджест): строит простую HTML/текст-разметку и шлёт через EmailService. Используется NotificationDispatcher, UserProfileService и cron-скриптом дайджеста.

### class EmailNotificationService — L7 (extends BaseService)

#### `getUserEmail(int $userId)` — L8 (private)
Читает `users.email`; пустая строка при отсутствии.

#### `buildActionUrl(string $rawLink)` — L22 (private)
Абсолютизирует ссылку: относительный путь префиксуется APP_URL из env.

#### `sendToUser(int $userId, string $subject, string $body, array $options = [])` — L32
Собирает HTML+plain-text письмо (subject/body экранируются, опционально кнопка-ссылка `options['link']`/`action_label`) и шлёт через `EmailService::send()`; false если нет email или SMTP не настроен; ошибки логируются warning без проброса.

#### `sendDailyDigestToUser(int $userId, array $items, array $options = [])` — L76
Дайджест-письмо со списком уведомлений (title/body/link на пункт), HTML `<ul>` + текстовая версия; отправка через `EmailService::send()` с warning-логированием ошибок.

## `planrun-backend/services/EmailService.php` (159 строк)
Низкоуровневая отправка email через PHPMailer: SMTP (по env MAIL_*) либо PHP mail(); плюс два готовых шаблона писем (сброс пароля, код подтверждения).

### class EmailService — L13

#### `__construct()` — L25
Читает env: MAIL_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION/FROM_*, APP_URL (фолбэк по HTTP_HOST); useSmtp = есть host+username.

#### `send($to, $subject, $bodyHtml, $bodyText = null)` — L53
Отправляет письмо PHPMailer'ом (UTF-8, base64, таймаут 10с): SMTP с STARTTLS/SMTPS и опциональным отключением проверки сертификата (MAIL_VERIFY_PEER=0), иначе isMail(). Любая ошибка → generic Exception «Не удалось отправить письмо».

#### `sendPasswordResetLink($toEmail, $username, $token, $expiresInMinutes = 60)` — L107
Шаблонное письмо со ссылкой `{APP_URL}/reset-password?token=...`. Вызывается AuthService.

#### `sendVerificationCode($toEmail, $code, $expiresMin = 10)` — L134
Шаблонное письмо с 6-значным кодом подтверждения регистрации. Вызывается EmailVerificationService.

#### `isConfigured()` — L156
true если SMTP настроен либо доступна функция mail() (практически всегда true).

## `planrun-backend/services/EmailVerificationService.php` (192 строки)
Коды подтверждения email при регистрации: генерация/хранение в таблице `email_verification_codes`, проверка с лимитом попыток и TTL, доставка письма. Используется register_api.php / RegistrationService.

### class EmailVerificationService — L9 (extends BaseService)
Константы: `TABLE` — L10, `CODE_LENGTH` — L11 (6), `EXPIRES_MINUTES` — L12 (10), `MAX_ATTEMPTS` — L13 (3).

#### `__construct($db)` — L15
Родительский конструктор + подгрузка env_loader при необходимости.

#### `sendVerificationCode(string $email)` — L22
Валидирует email, проверяет наличие таблицы, генерирует 6-значный код (random_int), удаляет старый код и INSERT нового с expires_at, затем `deliverVerificationCode()`. Пишет в `email_verification_codes`.

#### `verifyCode(string $email, string $code)` — L58
Проверка кода: формат email/длина кода → чтение записи → исчерпаны попытки (удаляет код) → истёк срок (удаляет) → неверный код (декремент attempts_left) → совпал (удаляет код, success). Возвращает структуру success/error/attempts_left.

#### `assertStorageReady()` — L143 (private)
SHOW TABLES: 503 если таблица `email_verification_codes` не создана миграцией.

#### `deleteCode(string $email)` — L153 (private)
DELETE кода по email.

#### `deliverVerificationCode(string $email, string $code, int $expiresMinutes)` — L164 (private)
Если есть vendor/autoload — шлёт через `EmailService::sendVerificationCode()`; иначе fallback на голый `mail()` с base64-subject. Ошибки логируются и пробрасываются как RuntimeException 500.

## `planrun-backend/services/ExecutedExerciseService.php` (259 строк)
Фиксация фактически выполненных упражнений (ОФП/СБУ) в таблице `executed_exercises`: отметка выполнения дня, история для AI (progressive overload), подсветка выполненных дней в календаре. Используется ExerciseController, WorkoutBuilderService, ofp_enricher.

### class ExecutedExerciseService — L13 (extends BaseService)

#### `markCompleted(int $userId, int $planDayId, string $executedDate, array $exercises)` — L19
Проверяет принадлежность plan_day юзеру, удаляет прежние отметки за день (re-mark) и INSERT каждой записи (planned_*/executed_* поля, rpe, notes). Возвращает `{saved: N}`.

#### `getLastExecuted(int $userId, string $exerciseName, ?int $exerciseId = null, int $lookbackWeeks = 12)` — L104
Последние ≤5 выполнений упражнения с весом (по exercise_id либо case-insensitive имени) за lookback-период; возвращает last_weight/sets/reps/date + history. Используется AI для подбора веса.

#### `getRecentHistoryForUser(int $userId, int $lookbackWeeks = 8)` — L160
Агрегат по упражнениям за период: max вес, последняя дата, число выполнений (GROUP BY name+category). Для AI-prompt контекста.

#### `getCompletedDatesByCategory(int $userId, int $lookbackWeeks = 26)` — L191
Map `дата → [категории]` (ofp/sbu) с непустыми executed-записями — подсветка календаря на фронте.

#### `getByPlanDay(int $userId, int $planDayId)` — L215
Все executed-записи конкретного plan-day.

#### `ensureSchema()` — L230 (private)
CREATE TABLE IF NOT EXISTS `executed_exercises` (lazy-миграция, выполняется при каждом публичном вызове, ошибки подавлены `@`).

## `planrun-backend/services/ExerciseService.php` (218 строк)
CRUD упражнений тренировочного дня (`training_day_exercises`) и библиотека упражнений: валидация через ExerciseValidator, данные через ExerciseRepository, инвалидация кэша плана.

### class ExerciseService — L11 (extends BaseService)

#### `__construct($db)` — L16
Создаёт ExerciseRepository и ExerciseValidator.

#### `addDayExercise($data, $userId)` — L30
Валидация → `ExerciseRepository::addExercise()` → инвалидация кэша `training_plan_{userId}`. Возвращает exercise_id.

#### `updateDayExercise($data, $userId)` — L67
Валидация → `ExerciseRepository::updateExercise()` → инвалидация кэша.

#### `deleteDayExercise($exerciseId, $userId)` — L106
Валидация → `ExerciseRepository::deleteExercise()` → инвалидация кэша.

#### `reorderDayExercises($data, $userId)` — L144
В транзакции обновляет order_index каждой позиции (`UPDATE training_day_exercises`), commit/rollback, инвалидация кэша + debug-лог.

#### `listExerciseLibrary($userId)` — L205
Библиотека упражнений через `ExerciseRepository::getExerciseLibrary()` (общая, без фильтра по юзеру).

## `planrun-backend/services/GoalProgressService.php` (414 строк)
Еженедельные снапшоты прогресса к цели (VDOT, объём, compliance, ACWR, прогноз vs целевое время) в `goal_progress_snapshots` + детекция milestone'ов для ProactiveCoachService и weekly review.

### class GoalProgressService — L15 (extends BaseService)

#### `__construct($db, $statsService = null, $contextBuilder = null)` — L20
DI с lazy-дефолтами: StatsService и ChatContextBuilder.

#### `takeSnapshot(int $userId, ?string $date = null)` — L42
Собирает снапшот: цель из `users`, VDOT из `StatsService::getBestResultForVdot()`, объём недели (`getWeekStats`), ACWR и compliance из ChatContextBuilder, прогноз времени через `predictRaceTime()` (prompt_builder), недель до гонки; UPSERT через `upsertSnapshot()`. Идемпотентен по (user, date).

#### `processAllUsers(?string $date = null)` — L105
Снапшоты для всех активных пользователей (onboarding_completed=1, не banned); ошибки по-юзерно логируются. Вызывается cron-скриптом goal_progress_snapshot.php. Возвращает счётчик.

#### `detectMilestones(int $userId)` — L132
Сравнивает 2 последних снапшота (из ≤8): vdot_improvement (Δ≥0.5), volume_record, consistency_streak (кратно 4 недель), goal_achievable (прогноз стал ≤ цели). Возвращает массив событий с приоритетами. Используется ProactiveCoachService.

#### `getProgressSummary(int $userId)` — L198
Сводка по ≤8 снапшотам для AI-промптов: текущий VDOT и тренды (1w/8w), средние км и compliance, streak, gap до цели и on_track. Используется weekly_ai_review.

#### `getRecentSnapshots(int $userId, int $limit = 8)` — L233
Чтение `goal_progress_snapshots` DESC с приведением типов полей.

#### `getUser(int $userId)` — L258 (private)
goal-поля из `users` (goal_type, race_distance/date/target_time).

#### `parseTargetTimeSec(array $user)` — L270 (private)
`H:MM:SS`/`M:SS` → секунды или null.

#### `parseTargetDistKm(array $user)` — L279 (private)
race_distance (5k/half/42k/число) → км через словарь либо извлечение числа.

#### `getWeekStats(int $userId, string $date)` — L296 (private)
Сумма км/сессий/longest за неделю даты по двум таблицам: `workout_log` (is_completed=1) и `workouts`. Возможен двойной счёт при дублировании ручной отметки и импорта.

#### `upsertSnapshot(array $data)` — L345 (private)
INSERT … ON DUPLICATE KEY UPDATE в `goal_progress_snapshots`. Внимание: строка типов `issdsdiddsssiii` смещена относительно параметров (vdot→'s', vdot_source→'d', weekly_km→'s', weekly_sessions→'d') — строковый vdot_source биндится как double и затирается в 0.

#### `isVolumeRecord(array $current, array $allSnapshots)` — L389 (private)
true если weekly_km текущего снапшота строго больше всех предыдущих.

#### `getConsistencyStreak(array $snapshots)` — L397 (private)
Длина серии недель с compliance ≥60% и ≥2 сессиями (с конца).

#### `avgField(array $snapshots, string $field)` — L409 (private)
Среднее по non-null значениям поля.

## `planrun-backend/services/JwtService.php` (341 строка)
Самописный JWT (HS256): создание/проверка access и refresh токенов, хранение refresh в таблице `refresh_tokens` (hash sha256) с ротацией, sliding expiration, grace period и лимитом 5 токенов на пользователя. Используется AuthService.

### class JwtService — L9 (extends BaseService)
Константы: `MAX_REFRESH_TOKENS_PER_USER` — L143 (5), `ROTATION_GRACE_SECONDS` — L144 (300).

#### `__construct($db)` — L17
Считывает из env: JWT_ACCESS_EXPIRATION_DAYS (1), JWT_REFRESH_INITIAL_DAYS (30), JWT_REFRESH_SLIDING_DAYS (30, cap JWT_REFRESH_MAX_AGE_DAYS=90); резолвит секрет.

#### `resolveSecretKey()` — L32 (private)
JWT_SECRET_KEY/JWT_SECRET из env (мин. 32 символа, иначе RuntimeException); в dev-окружении (APP_ENV/CLI/localhost) — фиксированный dev-секрет; в prod без секрета — исключение.

#### `createToken($payload, $expiration = null)` — L65
Собирает JWT вручную: base64url(header).base64url(payload+iat/exp).HMAC-SHA256.

#### `verifyToken($token)` — L93
Проверяет подпись (hash_equals) и exp; возвращает payload или null.

#### `createAccessToken($userId, $username)` — L130
Access-токен с type=access на expirationTime.

#### `getAccessTokenExpiration()` — L139
TTL access-токена в секундах (для expires_in).

#### `createRefreshToken($userId, $deviceId = null, $expirationSeconds = null)` — L154
Refresh-токен (type=refresh, device_id) + сохранение в БД через `saveRefreshToken()`.

#### `saveRefreshToken($userId, $token, $deviceId = null, $expirationSeconds = null)` — L175 (private)
Пишет sha256-хэш в `refresh_tokens`: сначала сокращает TTL старых токенов того же device_id до 5-минутного grace, INSERT нового (device_id колонка определяется через SHOW COLUMNS), затем обрезает до 5 свежих токенов на юзера и чистит истёкшие.

#### `verifyRefreshToken($token)` — L251
Проверяет JWT (type=refresh) и наличие неистёкшего хэша в `refresh_tokens`.

#### `refreshAccessToken($refreshToken, $deviceId = null)` — L279
Ротация: верифицирует refresh, читает username из `users`, выдаёт новый access + новый refresh со sliding TTL, отзывает старый refresh. Возвращает пару токенов + expires_in.

#### `revokeRefreshToken($token)` — L319
DELETE из `refresh_tokens` по хэшу.

#### `base64UrlEncode($data)` — L331 (private) / `base64UrlDecode($data)` — L338 (private)
URL-safe base64 кодирование/декодирование.

## `planrun-backend/services/LlmGateway.php` (875 строк)
Общий шлюз к OpenAI-совместимым LLM API (DeepSeek/Qwen): пул API-ключей по purpose, thinking-mode payload, HTTP-запросы с ретраями/backoff/Retry-After, конкурентный лимитер на MySQL (таблица `llm_gateway_locks` + GET_LOCK), observability-логирование через AiObservabilityService. Все методы статические.

### class LlmGatewayRequestException — L10 (extends RuntimeException)
Исключение запроса к LLM с метаданными ретрая.

#### `__construct(...)` — L17
message, httpStatus, retryable, retryAfterSeconds, responseBody, previous.

#### `getHttpStatus()` — L32 / `isRetryable()` — L37 / `getRetryAfterSeconds()` — L42 / `getResponseBody()` — L47
Геттеры соответствующих полей.

### class LlmGateway — L53
Константа `LIMITER_TABLE` — L55 (`llm_gateway_locks`); static-флаг `$limiterTableReady` — L57.

#### `provider(?string $baseUrl = null)` — L59
Провайдер из env LLM_PROVIDER/PLAN_LLM_PROVIDER либо эвристика по baseUrl ('deepseek' / 'openai-compatible').

#### `apiKey(?string $purpose = null)` — L71
Случайный ключ из пула `apiKeys()` (балансировка).

#### `apiKeys(?string $purpose = null)` — L81
Собирает дедуплицированный пул ключей из env по каскаду: purpose-специфичные (PLAN_LLM_*/LLM_CHAT_*/LLM_{PURPOSE}_*) → общие → DEEPSEEK_*; поддерживает списки через `splitApiKeys()`.

#### `headers(?string $baseUrl = null, ?string $apiKey = null, ?string $purpose = null)` — L117
HTTP-заголовки: Content-Type + Authorization Bearer (если ключ есть).

#### `apiKeyFingerprint(string $apiKey)` — L128
Первые 12 hex символов sha256 ключа — для логов без утечки ключа.

#### `splitApiKeys(string $value)` — L134 (private)
Разбивает строку ключей по пробелам/запятым/точкам с запятой.

#### `selectApiKey(array $apiKeyPool)` — L148 (private)
Случайный элемент пула.

#### `withThinkingMode(array $payload, ?string $baseUrl = null, bool $enableThinking = false)` — L157
Адаптирует payload под провайдера: для deepseek — `thinking.type` + reasoning_effort (env LLM_REASONING_EFFORT); для остальных — `chat_template_kwargs.enable_thinking`.

#### `requestChatCompletion(string $baseUrl, array $payload, array $options = [])` — L176
Сахар над `requestJson()` с путём `/chat/completions`.

#### `requestJson(string $baseUrl, string $path, array $payload, array $options = [])` — L181
Центральный метод: берёт лизу лимитера (`acquireConcurrencyLease`), затем до max_attempts cURL POST (с TCP keepalive для долгих reasoner-запросов); connection error / retryable HTTP-статус → backoff и ретрай (Retry-After приоритетнее); не-200/битый JSON → LlmGatewayRequestException; успех → логирует usage-метрики и возвращает decoded JSON. Все исходы пишутся в AiObservabilityService (если options['db'] передан); лиза освобождается в finally.

#### `acquireConcurrencyLease(array $options)` — L376
Если в options есть mysqli и заданы лимиты (env LLM_GATEWAY_GLOBAL_MAX_CONCURRENT / per-purpose) — создаёт таблицу лимитера и берёт лизу в каждом пуле через `acquirePoolLease()`; при сбое освобождает взятые и бросает исключение. Возвращает дескриптор лизы или null.

#### `releaseConcurrencyLease(?array $lease)` — L427
DELETE строк лизы из `llm_gateway_locks` по id+owner_token (ошибки подавлены).

#### `describeConcurrencyLease(?array $lease)` — L454
Публичная обёртка `leaseObservabilityPayload()` (используется ChatService для логов).

#### `resolveConcurrencyLimits(array $options)` — L459 (private)
Карта пулов лимитов: global + purpose:{name}; пусто при limit_concurrency=false.

#### `resolvePurposeConcurrencyLimit(string $purpose)` — L482 (private)
Лимит из env по алиасам (PLAN_LLM_MAX_CONCURRENT, LLM_CHAT_MAX_CONCURRENT, LLM_GATEWAY_{P}_MAX_CONCURRENT).

#### `resolveLimiterWaitSeconds(array $options)` — L505 (private)
Сколько ждать свободного слота: options → per-purpose env → LLM_GATEWAY_LIMIT_WAIT_SECONDS (15с).

#### `resolveLimiterTtlSeconds(array $options)` — L527 (private)
TTL лизы: options → env → расчёт из timeout×attempts+60 (30–1800с).

#### `acquirePoolLease(...)` — L543 (private)
Цикл до дедлайна: MySQL named lock (GET_LOCK по sha1 пула) → чистка истёкших лиз → подсчёт активных → INSERT лизы если меньше лимита; иначе sleep 250мс и повтор. По таймауту бросает LlmGatewayRequestException 429 retryable.

#### `ensureLimiterTable(mysqli $db)` — L598 (private)
CREATE TABLE IF NOT EXISTS `llm_gateway_locks` (один раз на процесс).

#### `acquireMysqlNamedLock(mysqli $db, string $lockName, int $waitSeconds)` — L625 (private)
SELECT GET_LOCK(); true при получении.

#### `releaseMysqlNamedLock(mysqli $db, string $lockName)` — L638 (private)
SELECT RELEASE_LOCK() с подавлением ошибок.

#### `deleteExpiredPoolLeases(mysqli $db, string $pool)` — L652 (private)
DELETE лиз с истёкшим expires_at.

#### `countActivePoolLeases(mysqli $db, string $pool)` — L663 (private)
COUNT активных лиз пула.

#### `insertPoolLease(...)` — L676 (private)
INSERT лизы с metadata_json (pid, limit, active_before_acquire) и TTL.

#### `leaseObservabilityPayload(?array $lease)` — L710 (private)
Поля limiter_* для логов (enabled, wait_ms, ttl, pools).

#### `sanitizeLimiterPool(string $pool)` — L724 (private)
Нормализация имени пула: lowercase, [a-z0-9:_-], ≤80 символов.

#### `isRetryableThrowable(Throwable $e)` — L731
true если LlmGatewayRequestException с retryable. Используется plan_generation_worker.

#### `queueRetryDelaySeconds(Throwable $e, int $attempts = 1)` — L736
Задержка для очереди ретраев: Retry-After из исключения либо базовая из env (429 → 120с, 5xx → 90с), линейно ×attempts + джиттер, cap 1800с.

#### `isRetryableHttpStatus(int $httpStatus)` — L757 (private)
408/409/425/429/500/502/503/504/529.

#### `parseRetryAfter(string $headers)` — L762 (private)
Заголовок Retry-After: секунды либо HTTP-дата → секунды.

#### `backoffSeconds(int $attempt, array $options)` — L785 (private)
Экспоненциальный backoff с cap и джиттером (env LLM_GATEWAY_RETRY_*), в секундах.

#### `sleepBeforeRetry(int $seconds)` — L797 (private)
usleep с cap 120с (учитывает длинные Retry-After).

#### `optionInt(array $options, string $key, int $default, int $min, int $max)` — L804 (private)
int-опция с клампом.

#### `envInt(string $key, int $default, int $min, int $max)` — L810 (private)
int из env с клампом.

#### `durationMs(float $startedAt)` — L817 (private)
Прошедшее время в мс.

#### `extractUsageMetrics(array $response)` — L822 (private)
usage + токен-метрики (prompt/completion/total/cache_hit/miss/cached) из ответа LLM.

#### `logRequestEvent(array $options, string $status, array $payload, int $durationMs)` — L841 (private)
Пишет событие в `AiObservabilityService::logEvent()` (surface/event_type/trace_id/user_id из options); ошибки подавлены.

#### `sanitizeObservabilityPayload(array $payload)` — L867 (private)
Удаляет api_key/messages/prompt/response_body, режет error до 700 символов.

## `planrun-backend/services/MetricsService.php` (218 строк)
Единая точка входа для расчётных метрик бегуна (VDOT, прогнозы, темпы, ACWR, compliance, объёмы); делегирует в prompt_builder-функции, TrainingStateBuilder и WorkoutRepository.

### class MetricsService — L21
Не наследует BaseService; держит mysqli и lazy WorkoutRepository.

#### `__construct($db)` — L25
Сохраняет соединение БД.

#### `workoutRepo()` — L29 (private)
Lazy-инициализация WorkoutRepository.

#### `estimateVdot(float $distanceKm, int $timeSec)` — L48
Обёртка над глобальной `estimateVDOT()` из prompt_builder.php. Используется CoachService (история VDOT атлета).

#### `getVdot(int $userId)` — L66
Текущий VDOT через `TrainingStateBuilder::buildForUserId()` (приоритет источников: benchmark_override → last_race → best_result → stale race → easy_pace → target_time). Возвращает {vdot, source, detail}. Используется CoachService.

#### `predictRaceTime(float $vdot, float $targetDistKm)` — L92
Обёртка над prompt_builder::predictRaceTime() (секунды). Внешних вызовов через экземпляр не найдено.

#### `getTrainingPaces(float $vdot)` — L102
Обёртка над prompt_builder::getTrainingPaces() (sec/km по зонам). Внешних вызовов через экземпляр не найдено.

#### `calculateACWR(int $userId)` — L116
ACWR за 28 дней: активности из `WorkoutRepository::getAllActivitiesForDateRange()`, фильтр беговых типов, load = минуты × intensity-фактор по rating (1–10) либо км×6; acute (7д) / chronic-weekly (28д/4) → зона low/optimal/caution/danger. Покрыто юнит-тестом; в проде повсеместно вызывается одноимённый метод ChatContextBuilder, не этот.

#### `isRunningLoadRelevantActivity(string $activityType)` — L171 (private)
Беговые типы: running / trail running / treadmill / бег.

#### `getCompliance(int $userId, int $days = 14)` — L185
Compliance за N дней через `WorkoutRepository::getCompliance()` + расчёт pct. Внешних вызовов не найдено.

#### `getWeeklyKm(int $userId)` — L199
Километраж текущей недели через `WorkoutRepository::getWeeklyKm()`. Внешних вызовов не найдено.

#### `getAvgWeeklyKm(int $userId, int $weeks = 4)` — L208
Средний недельный км через WorkoutRepository. Внешних вызовов не найдено.

#### `getDaysSinceLastWorkout(int $userId)` — L215
Дней с последней тренировки через WorkoutRepository. Внешних вызовов не найдено.
