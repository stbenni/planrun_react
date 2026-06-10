# Семантические дубли из построчного аудита (фаза 1) — для верификации

## Backend
- formatPace (prompt_builder.php:289) / formatPaceSec (prompt_builder.php:456) / validatorFormatPaceSec (plan_validator.php:70) — 3 форматтера темпа
- _paceCheckEasy / _paceCheckLong (pace_validator.php:64/81) — почти построчные копии
- парсинг времени «H:MM:SS → сек» скопирован 5+ раз внутри prompt_builder (calculatePaceZones, assessGoalRealism, buildGoalBlock); VDOT-из-забега и VDOT-из-easy-pace продублированы (calculatePaceZones vs assessGoalRealism)
- computeMacrocycle/computeHealthMacrocycle + formatMacrocyclePrompt/formatHealthMacrocyclePrompt — параллельные структуры
- PlanGenerationProcessorService: isRunningRelevantManualActivity (L1510) == isRunningRelevantImportedActivity (L1519) байт-в-байт
- NotificationTemplateService: getDefaultRuntimeTemplate (L241) vs getEditableDefinitionMap (L348) vs NotificationSettingsService::getEventCatalog — тройная синхронизация каталога 14 событий
- PlanNotificationService::getUnread ~ getRecent; PlanGenerationQueueService::findLatestActiveJobForUser (L245) ~ findActiveJobForUser (L282)
- NotificationSettingsService::isInQuietHours (L710) ~ isInQuietHoursFromSettings (L1715); normalizeTime ~ normalizeStoredTime
- WeekService::copyWeek — логика копирования упражнений дублирует copyDay
- скрипты: 4-5 клонов parseArgs (dry_run_coaching_prompt, live_generate_one_user, live_next_plan_batch, live_plan_generation_batch, inspect_ai_runtime); dryResolveUser==liveGenResolveUser; liveNextIssue==liveBatchIssue, liveNextBool==liveBatchBool; live_next_plan_batch — урезанный форк live_plan_generation_batch; eval_plan_generation: user-/synthetic-ветки дублируют пайплайн
- 2 пути пересчёта плана: run_recalculate_for_user (enqueue→worker) vs live_recalculate_batch (прямой process); test_weekly_review_for_user ~ weekly_ai_review; strava_register_webhook ⊂ strava_daily_health_check; polar_register_webhook ⊂ polar_webhook_health; run_weekly_adaptation_for_user vs weekly_plan_adaptation; weekly_digest vs proactive_coach
- providers: OAuth-флоу и маппинг полей тренировки дублируются между Coros/Garmin/Polar/Suunto/Strava (см. clones.txt); GarminProvider/PolarProvider/GpxTcxParser paceFromKmAndMinutes — дубль c багом переноса секунд в GpxTcxParser:431
- api/complete_specialization_api.php (тонкая обёртка) vs planrun-backend/complete_specialization_api.php; complete_specialization_api ↔ register_api — крупные общие блоки (clones.txt: 3 блока по 25-27 строк)
- migrate_all.php ↔ NotificationSettingsService (4 блока по 24 строки — DDL миграций?)
- migrate_executed_exercises.php ↔ ExecutedExerciseService.php (26 строк)
- TelegramLoginService.php:37-65 ↔ TelegramMiniAppService.php:148-175 (29 строк); внутри TelegramLoginService:309-329 ↔ 363-383
- plan_generator.php: внутренние дубли 351-374↔708-731, 366-388↔723-745, тройной 60-80/376-393/733-750
- plan_validator.php:21-42 ↔ PlanQualityGate.php:726-747

## Frontend
- ChatScreen: JSON-парс metadata x3 (getMessageAttachment L91, getMessageToolsUsed L101, msgMeta L1196); блок messages-map x3 (L953, L1057, L1193)
- регэксп-парсинг описаний тренировок x4: AddTrainingModal L321-389, ResultModal L180-221+294-322, DaySheetV3 parseRunStructure L21, calV3 buildRunSegments L87; + parseDescription TodayHeroV3:273 ≈ NextWorkoutSectionV3:119 + extractKm/extractIntervals WeekSectionV3 — итого 6-7 реализаций парсинга описания
- intervalTotalKm/fartlekTotalKm + recalc дистанция↔время↔темп: AddTrainingModal ↔ ResultModal
- stripHtml x3 (DayModal:23, calV3:76, inline AddTrainingModal)
- DayModal (жив в UserProfileScreen) дублирует DaySheetV3
- daysToRace x4 (AthleteGrid:29, AthleteOverlay:36, AthleteTable:52, CompareAthletesPanel:21); DISTANCE_LABELS x4 там же + GoalCountdownWidget + RacePredictionWidget; SPEC_LABELS x2 (TrainersScreen:37, FindTrainerV3:9)
- относительное время x4: formatRelative (AthleteOverlay:449), formatTime (EventQuickReplySheet:27), formatRelativeTime (EventStream:24), formatLastActivity/Short (AthleteTable/AthleteGrid); firstLine x2
- kebab-меню (open+клик-вне+Escape): PlanActionsMenuV3 ~ ChatHeaderMenu; «Escape + body overflow lock» x7 Coach-модалок — кандидат на хук
- формат темпа/времени: StatsUtils.formatPace, formatPaceFromSeconds x2 (PaceChart, CombinedWorkoutChart), workoutFormUtils.formatPace, локальные в PersonalRecordsWidget/GoalCountdownWidget, fmtPaceSec (blocks.jsx:18), fmtTime (blocks.jsx:481), lapFormat — 8+ реализаций
- ymd (statsV3Utils) ≡ formatDateStr (StatsUtils); workoutDateStr/km дубли statsAchievements/statsV3Utils; 2 разных метода avg-pace (легаси vs v3)
- LeafletRouteMap vs MapboxRouteMap — осознанный drop-in дубль (выбор по VITE_MAPBOX_TOKEN); расхождение hover-маркера
- useIsDesktop (StatsV3:18) дублирует hooks/useMediaQuery; useIsMobile (Calendar/v3) — проверить
- TYPE_LABELS/TYPE_PROPER/TYPE_INTERVAL_SUFFIX/MONTHS_GEN x3-4 файла Dashboard v3; USER_DIST_TO_KEY x2 (GoalSectionV3, RacePredictionV3); isAiPlanMode x2 (useDashboardData, DashboardV3)
- handleWorkoutClick/handleCloseSheet/handleDeleteWorkout: StatsScreen L130-161 ≈ UserProfileScreen L310-333; normPlan-нормализация (data ?? res) дублируется
- runStravaSync/runHuaweiSync (useSettingsActions) vs syncProvider (SettingsScreen:1376)
- CredentialBackupService.js:36-73 ↔ PinAuthService.js:45-82 (clones.txt, ~30 строк)
- TrainingLoadWidget ≈ FormSectionV3 (TSB/ATL/CTL — мёртвый vs живой); TrendComparisonWidget ≈ TrendsSmallV3+StatsSectionV3 (мёртвый vs живой)
- DashboardStatsWidget.loadStats ≈ ProfileQuickMetricsWidget.loadStats (оба мертвы)
- PaceChart/HeartRateChart/CombinedWorkoutChart — 7 функций дословно (мёртвые предшественники живого CombinedWorkoutChart)

ЗАМЕЧАНИЕ: дубли, где обе стороны мёртвые (см. DEAD-CODE-FRONTEND.md), помечать «решается удалением мёртвого кода», не предлагать рефакторинг.
