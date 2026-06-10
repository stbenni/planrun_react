# Backend AI-пайплайн 2/2 (prompt_builder + валидаторы) — справочник функций

## `planrun-backend/planrun_ai/plan_validator.php` (74 строки)
Агрегатор валидаторов нормализованного плана: подключает все 6 специализированных валидаторов, собирает и сортирует их issues, даёт утилиты для решения о корректирующей регенерации.

### `collectNormalizedPlanValidationIssues(array $normalizedPlan, array $trainingState, array $context = []): array` — L11
Вызывает все 6 валидаторов (schedule, pace, load, taper, goal_consistency, workout_completeness), мёрджит результаты и сортирует issues: сначала по severity (error раньше warning), затем по week_number, затем по code. Возвращает плоский массив issue-объектов.

### `validateNormalizedPlanAgainstTrainingState(array $normalizedPlan, array $trainingState, array $context = []): array` — L45
Тонкая обёртка над collectNormalizedPlanValidationIssues: возвращает только тексты сообщений (массив строк) — для логов/legacy-вывода.

### `shouldRunCorrectiveRegeneration(array $validationIssues): bool` — L52
Возвращает true, если среди issues есть хотя бы один с severity=error — сигнал, что план нужно регенерировать корректирующим вызовом LLM (используется в plan_generator.php).

### `scoreValidationIssues(array $validationIssues): int` — L62
Числовая оценка «плохости» набора issues: error = 3 балла, warning = 1. Используется в plan_generator для сравнения исходного и исправленного планов (принять repair только если score не вырос).

### `validatorFormatPaceSec(int $sec): string` — L70
Форматирует секунды/км в строку «M:SS». Используется во всех pace-сообщениях валидаторов. Дублирует formatPace/formatPaceSec из prompt_builder.php.

## `planrun-backend/planrun_ai/prompt_builder.php` (3579 строк)
Центральный построитель промптов генерации тренировочных планов: три независимых сценария (первичная генерация, пересчёт, новый план после завершённого) плюс утилиты — VDOT-калькулятор Daniels, расчёт макроцикла, оценка реалистичности цели, парсинг пользовательских пожеланий из текста.

### `getPromptWeekdayOrder(): array` — L18
Возвращает map день-недели → порядковый номер (mon=1 … sun=7). Используется для сортировки preferred_days; также вызывается из тестов и PlanSkeletonBuilder (через sortPromptWeekdayKeys).

### `getPromptWeekdayPatterns(): array` — L22
Map день → regex-паттерн распознавания дня недели в русском/английском свободном тексте (понедельник/пн/monday…). Используется только в extractScheduleOverridesFromReason.

### `sortPromptWeekdayKeys(array $days): array` — L34
Фильтрует невалидные ключи дней и сортирует оставшиеся в порядке Пн→Вс. Вызывается из getPreferredLongRunDayKey, TrainingStateBuilder, PlanSkeletonBuilder.

### `getPromptWeekdayLabel(string $day, bool $short = false): string` — L47
Русская подпись дня недели — короткая («Пн») или полная («Понедельник»). Используется в buildTrainingStateBlock.

### `getPreferredLongRunDayKey(array $userData): ?string` — L57
Выбирает предпочтительный день длительной: первый из ['sun','sat'] в preferred_days, иначе последний тренировочный день; null если preferred_days пуст. Вызывается из buildTrainingStateBlock, TrainingStateBuilder, PlanSkeletonBuilder.

### `extractScheduleOverridesFromReason(?string $reason): array` — L76
Парсит свободный текст причины пересчёта regex-ами («длительная в воскресенье», «отдых в понедельник») и возвращает map тип-тренировки (long/rest/tempo/interval) → день недели. Вызывается только из applyScheduleOverridesToUserData.

### `computeRaceDayPosition(?string $startDateStr, ?string $raceDateStr): ?array` — L108
По дате старта плана и дате забега вычисляет позицию дня гонки: номер недели плана (от понедельника недели старта), индекс дня 0-6 и русское имя дня. null при невалидных/перевёрнутых датах. Используется в buildGoalBlock для указания LLM, куда ставить type=race.

### `getSuggestedPlanWeeks($userData, $goalType)` — L136
Вычисляет длительность плана в неделях: для health — health_plan_weeks или фиксированные программы (start_running=8, couch_to_5k=10); иначе из разницы training_start_date и конечной даты (weight_goal_date/race_date); fallback-дефолты по типу цели (health/weight_loss=12, race/time_improvement=null). null если нет даты старта.

### `calculatePaceZones($userData)` — L174
Рассчитывает тренировочные темповые зоны (easy/long/marathon/tempo/interval/repetition/recovery, сек/км). Каскад источников: 1) готовые pace_rules из training_state; 2) VDOT из последнего забега / обратный расчёт из easy_pace_sec / 92% от целевого результата → getTrainingPaces; 3) упрощённый fallback от easy_pace_sec с фиксированными сдвигами. Возвращает null, если данных нет. Помечает источник полем 'source'.

### `formatPace($sec)` — L289
Форматирует секунды в «M:SS». Дубликат formatPaceSec (без типов).

### `getMinEasyKm(array $userData): float` — L299
Минимальная дистанция easy-бега по уровню/объёму: 3 км для novice/beginner или <15 км/нед, 4 км для intermediate или <30 км/нед, иначе 5 км. Используется в блоках запретов/правил/финальной проверки промпта. Переменная $sessions читается, но не используется.

### `calculateDetrainingFactor(int $daysSince, string $experienceLevel = 'intermediate'): float` — L323
Оценка потери формы после паузы (0.0-1.0): ступенчатый спад по дням, для advanced/expert — медленнее. Чистая функция; вызывается из plan_generator.php и TrainingStateBuilder (вне батча), внутри батча не используется.

### `_vdotOxygenCost(float $vMetersPerMin): float` — L353
Формула Daniels: кислородная стоимость бега при скорости v м/мин. Внутренний примитив VDOT-калькулятора.

### `_vdotFractionVO2max(float $tMinutes): float` — L361
Формула Daniels: доля VO2max, удерживаемая t минут. Внутренний примитив VDOT-калькулятора.

### `estimateVDOT(float $distanceKm, int $timeSec): float` — L372
Оценивает VDOT по результату забега (дистанция+время) через _vdotOxygenCost/_vdotFractionVO2max; клампит в 20-85.

### `predictRaceTime(float $vdot, float $targetDistKm): int` — L387
Предсказывает время на дистанции при данном VDOT бисекцией (50 итераций, границы 2-12 мин/км). Возвращает секунды.

### `getTrainingPaces(float $vdot): array` — L415
Тренировочные темпы из VDOT: для каждой зоны (easy_slow/easy_fast/marathon/threshold/interval/repetition как % VO2max) решает квадратное уравнение обратной кислородной стоимости. Возвращает темпы сек/км (easy — пара [slow, fast]), кламп 150-600.

### `formatPaceSec(int $sec): string` — L456
Форматирует секунды темпа в «M:SS». Дубликат formatPace (строго типизированный).

### `formatTimeSec(int $sec): string` — L463
Форматирует секунды времени в «H:MM:SS» или «M:SS». Используется в assessGoalRealism, predictAllRaceTimes, StatsController.

### `predictAllRaceTimes(float $vdot): array` — L476
Прогнозы на 4 стандартные дистанции (5k/10k/half/marathon): секунды, форматированное время, темп. Вызывает predictRaceTime; используется в assessGoalRealism и StatsController.

### `assessGoalRealism(array $userData): array` — L495
Главная оценка реалистичности цели (для race/time_improvement; для прочих целей сразу 'realistic'). Проверки: достаточно ли недель (по таблице минимумов на дистанцию/уровень), базового объёма, числа сессий; сравнение целевого времени с VDOT-прогнозом (пороги «амбициозно/нереально» зависят от дистанции и сужаются с возрастом 35+, доп. предупреждение 50+); подмешивает warnings из computeMacrocycle без дублей. Эвристически оценивает weekly_km, если объём не указан явно. Вердикт: unrealistic / challenging / realistic; плюс messages с suggestions (предложения изменить дату/дистанцию/время), VDOT, прогнозы, темпы. В контексте '_assessment_context'='registration' смягчает результат через softenGoalAssessmentForRegistration. Вызывается из api_v2.php.

### `softenGoalAssessmentForRegistration(array $assessment): array` — L878
Смягчает вердикт для регистрации: unrealistic → caution, добавляет ободряющее info-сообщение, помечает assessment_mode=advisory и blocks_registration=false. Прогоняет все messages через softenGoalAssessmentMessageForRegistration.

### `softenGoalAssessmentMessageForRegistration(array $message): array` — L903
Переписывает жёсткие формулировки одного сообщения («нереалистично» → «очень амбициозно» и т.п.), понижает type error → warning.

### `getDistanceSpec(string $dist): array` — L953
Справочник параметров подготовки по дистанции (5k/10k/half/marathon, алиасы 21.1k/42.2k): доли фаз, диапазон длительной, описания интервалов/темпового/фартлека/контрольных. Fallback — spec 10k. Используется в computeMacrocycle и buildRecalcTrainingPrinciplesBlock.

### `computeMacrocycle(array $userData, string $goalType): ?array` — L1008
Калькулятор макроцикла для race/time_improvement (null для прочих или <4 недель). Рассчитывает: длительности фаз (pre_base/base/build/peak/taper по spec и горизонту, особые ветки для <8 и >24 недель), recovery-недели (каждые 3-4), control-недели (перед разгрузочными), прогрессию длительной по неделям (старт от 40-45% недельного объёма, пик с марафонскими капами по базе/горизонту, инкремент ≤3 км, -20% в recovery, taper-спад), стартовый/пиковый объёмы (с ограничением достижимости +10%/нед), лимиты ключевых по фазам и предупреждения о нереалистичных целях. Возвращает структуру для formatMacrocyclePrompt/assessGoalRealism/computePlanChunks.

### `computeHealthMacrocycle(array $userData, string $goalType): ?array` — L1326
Упрощённый макроцикл для health/weight_loss: фазы адаптация → развитие → поддержание (без peak/taper, max_key_workouts=0 везде), recovery-недели, прогрессия длительной с потолком по цели (weight_loss до 8/15 км, health до 10/12), объёмы с капом достижимости. Не вызывается для start_running/couch_to_5k. null если <4 недель.

### `formatHealthMacrocyclePrompt(array $mc, string $goalType): string` — L1476
Рендерит результат computeHealthMacrocycle в текст промпта: фазы, прогрессия длительной, объёмы, разгрузочные недели, акценты для снижения веса.

### `formatMacrocyclePrompt(array $mc): string` — L1518
Рендерит результат computeMacrocycle в текст промпта: предупреждения о цели, фазы с длительными и лимитами ключевых, прогрессия длительной по неделям (последняя неделя — «забег»), объёмы, спецификации тренировок по дистанции, контрольные забеги, модификации для новичка.

### `buildUserInfoBlock($userData)` — L1617
Блок «ИНФОРМАЦИЯ О ПОЛЬЗОВАТЕЛЕ»: пол, возраст, рост/вес, уровень, объём, сессии/нед, длительная, ЧСС (макс — оценка 220-возраст если не задана), история травм с указанием учитывать.

### `buildGoalBlock($userData, $goalType)` — L1680
Блок «ЦЕЛЬ ТРЕНИРОВОК» по типу цели: race — дистанция, дата и позиция дня забега (computeRaceDayPosition), целевое время и рассчитанный целевой темп, история забегов; weight_loss — текущий/целевой вес и дата; time_improvement — аналог race; health — программа и текущий уровень. Комфортный темп показывает только если calculatePaceZones вернул null (иначе конфликт с зонами).

### `buildStartDateBlock($startDate, $suggestedWeeks)` — L1884
Короткий блок: дата начала тренировок (первая неделя = неделя этой даты) и требуемое количество недель.

### `buildPreferencesBlock($userData)` — L1896
Блок «ПРЕДПОЧТЕНИЯ»: беговые дни, ОФП-дни (с жёстким требованием ставить type=other; либо явный запрет ОФП), время тренировок, дорожка, место ОФП, ограничения по здоровью.

### `buildPaceZonesBlock($userData)` — L1953
Блок «ТРЕНИРОВОЧНЫЕ ЗОНЫ»: рендерит зоны из calculatePaceZones (E/M/T/I/R/Rec с RPE-описаниями) и правила применения зон к полям pace/interval_pace; запрещает LLM придумывать другие темпы. Пустая строка если зон нет.

### `formatPromptTimeForBenchmark(string $time, ?string $distanceKey = null): string` — L1989
Нормализует строку времени к «HH:MM:SS»: «M:SS» для half/marathon трактует как часы:минуты. Используется только в extractPlanningBenchmarkFromReason.

### `extractPlanningBenchmarkFromReason(?string $reason): array` — L2005
Извлекает из текста причины пересчёта упомянутую дистанцию (по ключевым словам) и время-результат (regex) → ['planning_benchmark_distance', 'planning_benchmark_time']. Вызывается только из applyScheduleOverridesToUserData.

### `extractPlanningEasyFloorFromReason(?string $reason): ?float` — L2039
Извлекает из текста причины минимальную дистанцию easy («easy не короче N км»). Вызывается только из applyScheduleOverridesToUserData.

### `applyScheduleOverridesToUserData(array $userData, ?string $reason): array` — L2053
Применяет извлечённые из текста причины оверрайды к userData: убирает rest-день из preferred_days (и пересчитывает sessions_per_week), ставит preferred_long_day, planning_benchmark_*, planning_easy_min_km. Вызывает extractScheduleOverridesFromReason/extractPlanningBenchmarkFromReason/extractPlanningEasyFloorFromReason. Продакшн-вызовов нет — только unit-тесты.

### `buildTrainingStateBlock(array $userData): string` — L2084
Блок «TRAINING STATE» из $userData['training_state']: VDOT с источником/уверенностью, readiness, недели до цели, день длительной, возраст, special population flags, аналитика пост-тренировочного фидбэка (боль/усталость/RPE/риск), сигналы атлета из заметок, load_policy (safety envelope роста объёма, recovery weeks). Пустая строка если state нет.

### `buildWeekSkeletonBlock(array $userData): string` — L2172
Блок «WEEK SKELETON» из $userData['plan_skeleton']: по неделе на строку — типы по дням Пн…Вс и фаза. Пустая строка если скелета нет.

### `buildWorkoutIntentBlock(array $userData, string $goalType, bool $isFlexibleRecalc = false): string` — L2202
Блок «WORKOUT INTENT» + «QUALITY DAY CONTRACT» (только race/time_improvement): для марафона — правила про goal_pace_specific tempo и редкое использование control; контракт intent-ов для tempo/interval/control/long. Во flexible-режиме разрешает LLM выбирать структуру самому.

### `buildTrainingPrinciplesBlock($userData, $goalType)` — L2231
Блок «ПРИНЦИПЫ И СТРУКТУРА ПЛАНА» по цели: health — текстовые программы start_running/couch_to_5k/regular_running/custom + computeHealthMacrocycle для нефиксированных; race/time_improvement — formatMacrocyclePrompt(computeMacrocycle) либо краткий fallback + зоны; weight_loss — принципы жиросжигания (пульсовая зона из max_hr или 220-возраст), ОФП, + health-макроцикл.

### `buildKeyWorkoutsBlock($userData)` — L2351
Блок «КЛЮЧЕВЫЕ ТРЕНИРОВКИ И ПРАВИЛА ПО ФАЗАМ»: ASCII-таблица допустимых типов по фазам (с вариантом для novice/intermediate+), taper-схемы по дистанции, типы ключевых, расстановка по числу сессий (+recovery run при 5+), таблица абсолютных запретов (с getMinEasyKm), схемы прогрессии интервалов/темповых/фартлека, марафонско-полумарафонская специфика (MP-сегменты, прогрессивная длительная, пороговые интервалы, генеральная репетиция).

### `buildMandatoryRulesBlock($userData)` — L2507
Блок «ОБЯЗАТЕЛЬНЫЕ ПРАВИЛА»: расписание (беговые только в preferred_days, остальные rest; ОФП только в свои дни), запрет дат, ровно 7 дней, только русский язык в notes, ASCII-таблица минимальных дистанций и структуры easy/tempo/long/race week.

### `buildFormatResponseBlock($userData = null)` — L2569
Блок «ФОРМАТ ОТВЕТА»: JSON-схема {weeks:[{days:[7]}]}, полный шаблон дня со всеми полями, примеры easy/long/tempo/interval/fartlek/rest (ОФП/СБУ — только если пользователь включил), правила формата (description запрещён, distance_km для interval/fartlek = null) и чек-лист финальной проверки с getMinEasyKm.

### `buildTrainingPlanPrompt($userData, $goalType = 'health')` — L2630
Сценарий 1 — первичная генерация плана с нуля: роль тренера + конкатенация всех блоков (user info, goal, start date, preferences, training state, skeleton, pace zones, principles, key workouts, intent, mandatory rules) + задача + формат. На L2657 выражение `$userData ?? $modifiedUser ?? null` — $modifiedUser здесь не определён (мёртвая часть выражения).

### `computePlanChunks($userData, $goalType): ?array` — L2672
Решает, разбивать ли длинный план (>16 недель, жёсткий кап 30) на чанки для нескольких LLM-вызовов: для race/time_improvement — по фазам макроцикла (_splitByMacrocyclePhases), для health/weight_loss — пополам. Возвращает массив чанков {week_from, week_to, weeks_count, phase_label, start_date} или null. Вызывается из plan_generator.php.

### `_splitByMacrocyclePhases(array $phases, int $totalWeeks, string $startDate): array` — L2717
Жадно группирует фазы макроцикла в чанки ≤16 недель, затем для каждого чанка вычисляет start_date смещением от старта плана. Параметр $totalWeeks не используется в теле.

### `buildPartialPlanPrompt($userData, $goalType, array $chunk, int $totalWeeks, int $chunkIndex, int $totalChunks, ?array $prevLastWeek = null): string` — L2781
Промпт генерации одного чанка длинного плана: общий контекст пользователя + блок «КОНТЕКСТ ГЕНЕРАЦИИ» (какая часть из скольких, относительная нумерация недель) + сводка последней недели предыдущего чанка для плавного перехода (типы/дистанции/объём, запрет скачка >10%). Вызывается из plan_generator.php. Тот же артефакт `$userData ?? $modifiedUser ?? null` на L2834.

### `buildRecalculationPrompt($userData, $goalType, array $recalcContext)` — L2850
Сценарий 2 — пересчёт текущего плана: подменяет training_start_date/health_plan_weeks в копии userData, собирает блоки (training state и skeleton пропускаются в режиме flexible), вставляет buildRecalcContextBlock (текущее состояние) и buildRecalcTrainingPrinciplesBlock (оригинальный макроцикл), принципы возврата к нагрузке в зависимости от detraining_factor (≥0.95 продолжать / ≥0.85 −10-15% / иначе плавный возврат 1-2 недели), повтор критичного расписания, формат. В buildFormatResponseBlock из-за `$userData ?? …` передаётся оригинальный $userData, а не $modifiedUser.

### `buildRecalcTrainingPrinciplesBlock($userData, $goalType, array $recalcContext)` — L2964
Блок принципов для пересчёта: если фаза не определена или цель не соревновательная — делегирует buildTrainingPrinciplesBlock. Иначе: текущая фаза и остаток, оставшиеся фазы с перенумерацией 1..N (с учётом weeks_into_phase и weeks_to_generate), прогрессия длительной в новой нумерации, объёмы от реального avg_weekly_km_4w, жёсткие марафонские требования (MP-блоки в длительных, peak 30-34 км, монотонность, recovery после промежуточных стартов, easy от VDOT, race-week), разгрузочные/контрольные недели в новой нумерации, зоны и spec дистанции.

### `buildRecalcContextBlock(array $ctx, ?string $origStartDate): string` — L3144
Блок «ТЕКУЩЕЕ СОСТОЯНИЕ» для пересчёта: дни с последней тренировки, detraining-процент с рекомендацией, compliance за 2 недели, реальные средние объём/темп/пульс/тяжесть за 4 недели, ACWR с зонами риска, история объёмов по неделям (rollup), ключевые тренировки цикла, детальный план→факт, недели до цели, последние 8 тренировок, агрегированная сводка сохранённых недель (чанки по ~4), детальная структура последних недель плана, текущая фаза, причина пересчёта от пользователя, старая/новая даты старта.

### `buildNextPlanPrompt($userData, $goalType, array $nextPlanContext)` — L3376
Сценарий 3 — новый план после завершения предыдущего: роль «бегун с базой», блоки пользователя + buildPreviousPlanHistoryBlock + принципы/ключевые/правила, задача (стартовый объём = реальный за 4 недели, первая неделя recovery 80-85%, далее +5-10%/нед), повтор расписания, формат. Тот же артефакт `$userData ?? $modifiedUser` на L3439.

### `buildPreviousPlanHistoryBlock(array $ctx): string` — L3447
Блок «ИСТОРИЯ ПРЕДЫДУЩЕГО ПЛАНА»: параметры старого плана, тренировки/набег, средний и пиковый объёмы, прогрессия начало→конец, лучшие показатели (длительная/темповый/интервалы/средний темп/пульс/тяжесть), compliance, ключевые тренировки по факту, текущая форма за 4 недели (стартовый ориентир нового плана), последние 8 тренировок, пожелания пользователя, дата старта и число недель.

## `planrun-backend/planrun_ai/validators/goal_consistency_validator.php` (113 строк)
Валидатор соответствия интенсивности плана типу цели и special-population флагам.

### `collectGoalConsistencyValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array` — L5
По каждой неделе считает quality-сессии (tempo/interval/fartlek/control/race). Для health: >1 quality = error, quality у novice/beginner = warning, race/control день = warning. Для weight_loss: >1 quality = warning. Severe-флаги (беременность, хроника, возврат после травмы): любые не-race quality = error, race/control = warning. Консервативные флаги (65+, перерыв, низкая уверенность VDOT): >1 quality = warning. Вызывает normalizeTrainingType (plan_normalizer).

## `planrun-backend/planrun_ai/validators/load_validator.php` (122 строки)
Валидатор недельной нагрузки: скачки объёма и ключевые тренировки подряд.

### `collectLoadValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array` — L5
Две проверки по неделям: 1) рост объёма против reference-недели (после recovery сравнивает с последней нормальной) выше allowed_growth_ratio из load_policy (или по readiness: low=1.08/high=1.12/иначе 1.10) → weekly_volume_spike (error при большом превышении/ratio), с исключениями для race-недель и base-reentry (возврат к базе ≥50 км — потолок 80-90% базы); для low-base профилей — абсолютный порог pre_threshold_absolute_growth_km; 2) две ключевые тренировки в соседние дни → back_to_back_key_workouts (error если участвует race/long). Использует PLAN_KEY_WORKOUT_TYPES и normalizeTrainingType из plan_normalizer.

## `planrun-backend/planrun_ai/validators/pace_validator.php` (237 строк)
Валидатор соответствия темпов плана VDOT-derived pace_rules из training_state.

### `collectPaceValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array` — L20
Диспетчер: если pace_rules нет — пусто; иначе по каждому дню по типу вызывает _paceCheckEasy/_paceCheckLong/_paceCheckTempo/_paceCheckInterval/_paceCheckFartlek. Парсит темпы через parsePaceToSeconds (plan_normalizer).

### `_paceCheckEasy(int $paceSec, array $paceRules, int $weekNumber, string $date, array $day): array` — L64
Проверяет easy pace против коридора easy_min_sec−15 … easy_max_sec+20 (кламп 150-600). Выход за коридор: error при отклонении >25 сек, иначе warning. Код easy_pace_out_of_range. Условие `$min === 0 || $max === 0` мёртвое из-за max(150,…).

### `_paceCheckLong(int $paceSec, array $paceRules, int $weekNumber, string $date, array $day): array` — L81
Идентична _paceCheckEasy, но для long-коридора (long_min_sec/long_max_sec) и кода long_pace_out_of_range.

### `_paceCheckTempo(int $paceSec, array $paceRules, int $weekNumber, string $date, array $day, array $trainingState): array` — L98
Для subtype=race_pace сравнивает с goal-specific target (resolveGoalSpecificTempoPaceTargetSec из plan_normalizer) или race_pace_sec, tolerance 20 сек → race_pace_tempo_out_of_range. Для обычного tempo — с tempo_sec (или goal-specific) с tolerance tempo_tolerance_sec+5 (≥20 при goal-specific) → tempo_pace_out_of_range. Error при превышении tolerance+15.

### `_paceCheckInterval(array $day, array $paceRules, int $weekNumber, string $date): array` — L145
Сравнивает interval_pace с interval_sec, tolerance interval_tolerance_sec+7 → interval_pace_out_of_range (error при >tolerance+15). Пусто, если темп не задан или target ≤0.

### `_paceCheckFartlek(array $day, array $paceRules, int $weekNumber, string $date): array` — L171
Проверяет pace каждого скоростного сегмента фартлека: target по типу сегмента (race_pace→race_pace_sec/18, interval/vo2/repetition→interval_sec, tempo/threshold→tempo_sec, generic fast→tempo_sec с tolerance 25); recovery/easy сегменты пропускает. Код fartlek_segment_pace_out_of_range (error при >tolerance+18).

## `planrun-backend/planrun_ai/validators/schedule_validator.php` (85 строк)
Валидатор расписания: 7 дней в неделе, соответствие skeleton и preferred_days.

### `collectScheduleValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array` — L5
Проверки по неделям (все error): 1) ровно 7 дней (invalid_week_day_count); 2) тип каждого дня совпадает с expected_skeleton из context (schedule_skeleton_mismatch); 3) беговой день (isRunTypeForSchedule, кроме race) вне preferred_days (run_on_non_preferred_day); 4) если sessions_per_week == числу preferred_days — rest/free в обязательный беговой день (missing_run_on_required_day). Использует normalizePreferredDayKeys, PLAN_DAY_KEYS, PLAN_DAY_KEY_TO_INDEX, normalizeSkeletonDayType из plan_normalizer.

## `planrun-backend/planrun_ai/validators/taper_validator.php` (76 строк)
Валидатор подводки перед забегом.

### `collectTaperValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array` — L5
Находит неделю с днём type=race; если она не первая: 1) сравнивает «вспомогательный» объём race-недели (total_volume минус дистанция самой гонки) с потолком = объём предыдущей недели × race_week_supplementary_ratio (из load_policy или по дистанции: марафон 0.35 / половинка 0.45 / иначе 0.60) → taper_race_week_too_big (error для длинных гонок); 2) для длинных гонок — предгоночная неделя почти не снизила объём против пред-taper недели → taper_not_reduced (warning).

## `planrun-backend/planrun_ai/validators/workout_completeness_validator.php` (155 строк)
Валидатор содержательности ключевых тренировок: tempo/control/interval/fartlek должны иметь конкретную структуру.

### `hasMeaningfulTempoStructure(array $day): bool` — L5
true, если у tempo-дня есть хоть что-то структурное: duration_minutes>0, непустые notes, distance_km с warmup/cooldown, либо exercises.

### `hasMeaningfulControlStructure(array $day): bool` — L27
Аналог для control-дня: notes, exercises, либо distance_km с warmup/cooldown.

### `hasMeaningfulComplexWorkoutStructure(array $day): bool` — L44
Для fartlek делегирует hasUsableFartlekSegments (plan_normalizer); для interval — reps>0 и interval_m>0, либо notes с распознаваемой структурой отрезков (regex «N×M», «м/мин», «отрез/ускор/повтор»).

### `resolvePersonalizedTempoStimulusFloorKm(array $trainingState, int $weekNumber, ?int $raceWeekNumber): ?float` — L60
Персональный минимум дистанции tempo-стимула из load_policy: max(tempo_min_km, easy_build_min_km×tempo_floor_ratio). null для recovery-недель и недель ≥ race−1.

### `collectWorkoutCompletenessValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array` — L93
По каждому дню: tempo/control без структуры → key_workout_missing_structure (error для race/time_improvement, иначе warning); tempo для race/time_improvement с distance_km меньше персонального floor без notes/exercises → tempo_stimulus_too_small (error); interval/fartlek без структуры → complex_workout_missing_structure. Использует findNormalizedPlanRaceWeekNumber (plan_normalizer).
