# Backend tests — обзор

PHPUnit 10 (`planrun-backend/phpunit.xml`, bootstrap `tests/bootstrap.php`). Тест-сьюты: `tests/Unit` и `tests/Feature`. Файлы `tests/test_chat_fixes.php` и `tests/test_chat_tools.php` в сьюты НЕ входят — это ручные CLI-скрипты. Большинство тестов работают с РЕАЛЬНОЙ БД MySQL (через `getDBConnection()`), создавая пользователей с префиксованными username; bootstrap чистит их по shutdown. Часть тестов использует fake-mysqli классы (in-memory), PHPUnit-моки почти не используются (только RegistrationServiceTest).

## `planrun-backend/tests/bootstrap.php` (167 строк)

Бутстрап PHPUnit. Подробно:
- Ставит `APP_ENV=testing`; переключает `session_save_path` на `sys_get_temp_dir()/planrun-phpunit-sessions` (чтобы CLI-тесты не зависели от системного пути сессий).
- Грузит `config/env_loader.php`; поддерживает отдельную тестовую БД через env `PLANRUN_PHPUNIT_DB_NAME` (переопределяет `DB_NAME`).
- Грузит `db_config.php` и `auth.php` — глобальные функции (`getDBConnection()`, `login()`, `logout()`, `isAuthenticated()`) доступны всем тестам.
- Глобальная функция **`planrunPhpunitDeleteRows(mysqli $db, array &$deleted, string $table, string $where)`** — хелпер удаления строк с подсчётом affected_rows.
- `register_shutdown_function`: автоочистка реальной БД после прогона (только CLI + APP_ENV=testing):
  - удаляет фикстурную тренировку user_id=1 (workout_log с конкретной заметкой) и связанные post_workout_followups / chat_messages / plan_day_notes;
  - удаляет всех пользователей с тестовыми префиксами username (`recalc_proc_%`, `skeleton_break_%`, `workout_rating_%`, `feedback_state_%`, `ai_smoke_%`, `metrics_%`, `training_state_%`, `pending_confirm_%`, `chat_repo_%`, `chat_context_%`, `tool_registry_%`, `repo_%`, `plan_service_%`, `athlete_signals_%`, `planning_user_%`, `post_followup_%`, `plan_readiness_%`) и каскадно — их строки во всех таблицах с колонкой `user_id` (через INFORMATION_SCHEMA), плюс `user_coaches`/`coach_requests` по `coach_id`, `email_verification_codes` по email.

Вывод: тесты пишут в реальную (боевую/локальную) БД, изоляция — конвенция префиксов username + shutdown-очистка.

## `planrun-backend/tests/Fixtures/golden_plan_policy_cases.php` (603 строки)

Дата-фикстура (return массива, без классов/функций): 39 «золотых» кейсов политики построения скелета плана. Каждый кейс: `name`, `goal_type`, `user` (race_date/distance, sessions_per_week, preferred_days, experience_level и т.п.), `options` (start_date, weeks), `expected` (forbidden_run_days, long_day и др. инварианты). Потребитель: `tests/Unit/GoldenPlanPolicyTest.php` (dataProvider).

## `planrun-backend/tests/Fixtures/synthetic_plan_eval_cases.php` (225 строк)

Дата-фикстура: 13 синтетических профилей пользователей для eval-прогона генерации планов (novice couch-to-5k, weekday-only, 6 дней без пятницы и т.д.). Потребитель — НЕ PHPUnit, а скрипт `planrun-backend/scripts/eval_plan_generation.php`.

## `planrun-backend/tests/test_chat_fixes.php` (578 строк)

НЕ PHPUnit — ручной CLI-скрипт (`#!/usr/bin/env php`, запуск `php tests/test_chat_fixes.php`). Проверяет 10 «архитектурных фиксов» ChatService: через Reflection достаёт private-поля `actionParser`/`confirmationHandler`/`promptBuilder` и дёргает private-методы (extractSwapDatesFromText, parseReplaceWithRaceProposal, double-execution guard и др.). Свои assert_true/assert_false с echo ✅/❌ и счётчиком passed/failed. Требует реальную БД, LLM не требует.

## `planrun-backend/tests/test_chat_tools.php` (299 строк)

НЕ PHPUnit — ручной CLI-скрипт. Реальные вызовы 8+ chat tools через Reflection (`ChatToolRegistry::executeTool`) для живого user_id=1 (st_benni), ~13 вызовов `runTest(...)` с pretty-print JSON-результата. Требует реальную БД с данными пользователя 1.

## `planrun-backend/tests/Feature/AuthTest.php` (82 строки)

Глобальные функции auth (`isAuthenticated()`, `logout()` из `auth.php`) через манипуляцию `$_SESSION` в setUp/tearDown.
- Методы: test_isauthenticated_returns_false_when_not_logged_in, test_isauthenticated_returns_true_when_session_set, test_isauthenticated_requires_user_id, test_logout_clears_session. (test_login_with_valid_credentials закомментирован — «требует тестовой БД».)
- Особенности: без БД и моков, чистая работа с сессией.

## `planrun-backend/tests/Feature/TrainingPlanControllerTest.php` (100 строк)

`TrainingPlanController`: что эндпоинты load/checkStatus игнорируют `user_id` из параметров и используют авторизованного пользователя календаря.
- Методы: test_load_returnsEmptyForNonExistentUser, test_load_ignores_user_id_param_and_uses_authorized_calendar_user, test_checkStatus_ignores_user_id_param_and_uses_authorized_calendar_user.
- Особенности: реальная БД (`getDBConnection()`); fake-сервис как анонимный класс, подменяется в private-поле контроллера через ReflectionProperty.

## `planrun-backend/tests/Unit/AiPlanGenerationEventLoggerTest.php` (348 строк)

`services/AiPlanGenerationEventLogger`: derive_cohort (healthy/pregnancy/injury/pain/illness/unrealistic goal), запись success/failure событий, выборка и агрегация метрик.
- Методы: test_derive_cohort_returns_healthy_for_clean_state, test_derive_cohort_prioritizes_pregnancy_over_others, test_derive_cohort_detects_return_after_injury_via_population_flags, test_derive_cohort_detects_return_after_injury_via_scenario_flags, test_derive_cohort_detects_pain_signal, test_derive_cohort_detects_illness_signal, test_derive_cohort_detects_unrealistic_goal, test_record_success_writes_row_with_metadata_fields, test_record_success_serializes_issue_and_repair_codes, test_record_failure_captures_error_code_and_message, test_get_recent_events_filters_by_user_id_and_status, test_get_metrics_summary_aggregates_by_cohort_and_model.
- Особенности: реальная БД (insert/select событий), пользователи `ai_smoke_%`.

## `planrun-backend/tests/Unit/AthleteSignalsServiceTest.php` (123 строки)

`services/AthleteSignalsService`: объединение фидбэка после тренировок с заметками дня/недели в сигналы атлета.
- Методы: test_getSignalsBetween_combines_feedback_with_day_and_week_note_signals.
- Особенности: реальная БД, создаёт пользователя `athlete_signals_%` и строки заметок/фидбэка.

## `planrun-backend/tests/Unit/BaseControllerGetParamTest.php` (45 строк)

`controllers/BaseController::getParam` — чтение параметров из JSON body, когда `$_POST` пуст.
- Методы: test_getParam_reads_json_body_when_post_is_empty.
- Особенности: тестовый наследник BaseController с публичным `readParam()`; без БД.

## `planrun-backend/tests/Unit/ChatServiceMessageNormalizationTest.php` (43 строки)

`services/ChatPromptBuilder` — нормализация истории сообщений к строгому чередованию ролей (merge подряд идущих ролей, сворачивание ведущего assistant).
- Методы: test_normalizeMessagesForStrictAlternation_merges_consecutive_roles_and_folds_leading_assistant.
- Особенности: реальная БД для конструирования зависимостей (ChatContextBuilder, ChatRepository), private-метод через Reflection.

## `planrun-backend/tests/Unit/CoachEventsServiceTest.php` (162 строки)

`services/CoachEventsService`: лента событий тренера — форматирование времени PR, лейблы дистанций/типов активности, форматирование км и деталей загрузки, сбор PR.
- Методы: test_getEvents_returns_empty_array_when_no_data, test_formatPrTime_under_hour, test_formatPrTime_over_hour, test_formatPrTime_zero_or_negative, test_prDistanceLabel_maps_known_keys, test_activityTypeLabel_known_and_default, test_formatKm_compact, test_formatUploadDetail_combines_parts, test_collectPRs_returns_empty_when_no_athletes, test_formatPrTime_boundary_values, test_formatKm_does_not_show_trailing_zero.
- Особенности: fake-mysqli классы в файле (CoachEventsFakeResult/CoachEventsFakeStmt/CoachEventsFakeDb), private-методы через Reflection; реальная БД не нужна.

## `planrun-backend/tests/Unit/CoachTemplateServiceTest.php` (164 строки)

`services/CoachTemplateService::bulkAssign` — массовое назначение шаблонов атлетам: валидации, права, конфликты.
- Методы: test_bulkAssign_returns_error_when_no_athletes, test_bulkAssign_throws_on_invalid_date_format, test_bulkAssign_returns_error_when_template_not_owned, test_bulkAssign_filters_athletes_without_edit_permission, test_bulkAssign_returns_conflicts_when_overwrite_false.
- Особенности: fake-mysqli (CoachFakeResult/CoachFakeStmt/CoachFakeDb с `whenPrepareContains` и транзакциями); без реальной БД.

## `planrun-backend/tests/Unit/DbConfigTest.php` (49 строк)

`db_config.php`: наличие констант, чтение env, существование `getDBConnection()`.
- Методы: test_db_config_constants_are_defined, test_db_config_uses_env_when_available, test_getdbconnection_returns_mysqli_or_null.
- Особенности: smoke-уровень, может реально коннектиться к БД (mysqli или null).

## `planrun-backend/tests/Unit/DeepSeekPlanPlannerPromptTest.php` (795 строк)

`planrun_ai/llm_planner/DeepSeekPlanPlanner`: системный/полный промпт планировщика, calendar weeks, race proximity, выбор модели (reasoner/auto-эскалация), complexity score, targeted retry/regenerate weeks.
- Методы: test_system_prompt_uses_coaching_method_diagnose_strategy_calendar, test_strip_macrocycle_precompute_removes_phase_a7_fields, test_strip_macrocycle_precompute_handles_null_policy, test_full_plan_prompt_describes_format_and_calendar_weeks_skeleton, test_full_plan_prompt_does_not_micromanage_compliance_or_sanity_floor, test_single_pass_macro_is_derived_from_detail_weeks, test_single_pass_week_target_is_aligned_to_calendar_total, test_calendar_weeks_include_days_to_race, test_race_proximity_marks_pre_taper_minus1_race_post1_post2, test_race_proximity_marks_post_race_day_2_and_null_after, test_planner_context_includes_pace_strategy_block, test_race_proximity_handles_intermediate_race, test_race_proximity_priority_pre_race_over_post_race_when_overlap, test_race_proximity_is_null_when_no_race_in_horizon, test_full_plan_prompt_passes_planning_scenario_and_goal_realism_via_facts_json, test_full_plan_prompt_passes_recent_compliance_summary_to_facts_json, test_full_plan_prompt_passes_season_and_best_races_via_facts_json, test_recent_long_effort_guard_detects_marathon_before_plan_start, test_compute_complexity_score_returns_zero_for_clean_state, test_compute_complexity_score_counts_scenario_flags, test_compute_complexity_score_counts_population_flags_and_goal_realism, test_resolve_model_selection_defaults_to_reasoner_with_thinking_always, test_resolve_model_selection_falls_back_to_auto_escalation_when_thinking_always_disabled, test_resolve_model_selection_escalates_to_reasoner_for_complex_scenario_when_thinking_always_off, test_resolve_model_selection_does_not_escalate_for_single_minor_risk_when_thinking_always_off, test_build_targeted_retry_prompt_focuses_on_requested_weeks, test_apply_regenerated_weeks_replaces_only_target_weeks, test_apply_regenerated_weeks_aligns_target_volume_to_day_sum, test_regenerate_weeks_rejects_empty_week_list, test_regenerate_weeks_rejects_too_many_weeks.
- Особенности: planner создаётся с реальным DB-коннектом, но тестируются private-методы через ReflectionMethod (без LLM-вызовов).

## `planrun-backend/tests/Unit/DeepSeekToolCallingTest.php` (319 строк)

Интеграция с DeepSeek API: tool calling в чате (LlmGateway + ChatToolRegistry) — реальные HTTP-вызовы LLM.
- Методы: test_model_calls_get_day_details_for_today_question, test_model_calls_get_workouts_for_history_question, test_multi_round_tool_call_with_result, test_model_does_not_call_tools_for_greeting, test_chat_tool_registry_produces_valid_openai_tools_format, test_full_tool_list_accepted_by_deepseek_api, test_tool_choice_auto_allows_text_only_response.
- Особенности: `markTestSkipped` без API-ключа (LLM_CHAT_API_KEY/PLAN_LLM_API_KEY); реальная БД для ChatToolRegistry; недетерминированный (skip, если модель не вызвала tool).

## `planrun-backend/tests/Unit/EnvLoaderTest.php` (116 строк)

`config/env_loader.php`: функция `env()` (default/окружение/$_ENV) и `loadEnv()` (файл, комментарии, кавычки, пустой/отсутствующий файл).
- Методы: test_env_function_returns_default_when_not_set, test_env_function_reads_from_environment, test_env_function_reads_from_env_array, test_loadenv_loads_from_file, test_loadenv_ignores_comments, test_loadenv_handles_quoted_values, test_loadenv_handles_empty_file, test_loadenv_handles_missing_file_gracefully.
- Особенности: временные файлы, без БД.

## `planrun-backend/tests/Unit/GoalRealismTrainingStateTest.php` (34 строки)

`planrun_ai/prompt_builder.php` — assessGoalRealism предпочитает VDOT из training state.
- Методы: test_assessGoalRealism_prefersTrainingStateVdot.
- Особенности: чистая функция, без БД.

## `planrun-backend/tests/Unit/GoldenPlanPolicyTest.php` (104 строки)

`services/PlanSkeletonBuilder::build` против 39 золотых кейсов: запрещённые дни бега, день long run и др. инварианты скелета.
- Методы: test_golden_plan_policy_cases (один метод × dataProvider goldenCases из Fixtures/golden_plan_policy_cases.php).
- Особенности: data-driven; без БД.

## `planrun-backend/tests/Unit/LlmGatewayTest.php` (151 строка)

`services/LlmGateway`: пул API-ключей (дедупликация, purpose-specific), fingerprint ключа, лимитер конкурентности.
- Методы: test_api_keys_accept_pool_and_deduplicate_values, test_headers_use_purpose_specific_pool, test_api_key_fingerprint_is_stable_without_exposing_secret, test_concurrency_limiter_rejects_when_global_pool_is_full.
- Особенности: env-переменные манипулируются в тесте; один тест берёт реальный DB-коннект; без HTTP-вызовов.

## `planrun-backend/tests/Unit/LoadTrainingPlanCacheTest.php` (106 строк)

`load_training_plan.php` — кэш плана: пустой план не кэшируется, протухший пустой кэш игнорируется при наличии плана.
- Методы: test_empty_plan_is_not_cached, test_stale_empty_cache_is_ignored_when_plan_exists.
- Особенности: реальная БД.

## `planrun-backend/tests/Unit/MetricsServiceTest.php` (82 строки)

`services/MetricsService::calculateACWR` — ходьба не учитывается в сигнале беговой перегрузки.
- Методы: test_calculateACWR_ignores_walking_load_for_running_overload_signal.
- Особенности: реальная БД, пользователь `metrics_%` с workout-строками.

## `planrun-backend/tests/Unit/NotificationSettingsServiceTest.php` (40 строк)

`services/NotificationSettingsService` — события proactive AI-коуча зарегистрированы в реестре настроек.
- Методы: test_aiCoachProactiveEventsAreRegistered.
- Особенности: без БД (статический реестр).

## `planrun-backend/tests/Unit/PlanGenerationProcessorServiceTest.php` (1041 строка)

`services/PlanGenerationProcessorService` — оркестратор генерации плана: режим quality gate (strict/permissive по сценарию/флагам/env), hard safety repairs, обогащение payload'ов recalc/next-plan, синхронизация снапшота плана, консистентность race day, сохранение recalc-плана.
- Методы: test_resolveQualityGateMode_returns_permissive_for_healthy_marathon_runner_in_auto_mode, test_resolveQualityGateMode_returns_permissive_for_healthy_half_marathon_runner_in_auto_mode, test_resolveQualityGateMode_returns_strict_for_marathon_with_return_after_injury, test_resolveQualityGateMode_does_not_force_strict_for_return_after_break_scenario, test_resolveQualityGateMode_returns_strict_for_return_after_injury_flag, test_resolveQualityGateMode_returns_strict_for_unrealistic_goal_realism, test_resolveQualityGateMode_returns_strict_for_protective_scenario_flags, test_resolveQualityGateMode_returns_permissive_for_healthy_runner_in_auto_mode, test_resolveQualityGateMode_respects_explicit_strict_env, test_resolveQualityGateMode_respects_explicit_permissive_env, test_applySinglePassHardSafetyRepairs_caps_late_marathon_long_runs, test_applySinglePassHardSafetyRepairs_caps_extreme_long_share, test_enrichRecalculatePayload_excludesWalkingAndManualCrossTrainingFromActualWeeklyKm, test_enrichRecalculatePayload_sets_mutable_from_date_to_tomorrow_when_running_workout_today_exists, test_enrichRecalculatePayload_aligns_cutoff_to_future_plan_start_and_includes_current_phase, test_enrichRecalculatePayload_builds_progression_counters_from_completed_key_days, test_enrichNextPlanPayload_uses_recent_non_race_weeks_before_new_start, test_attachGenerationExplanation_adds_explanation_metadata, test_buildQualityGateFailureMessage_prefers_blocking_errors_over_warnings, test_syncLatestTrainingPlanSnapshot_updates_latest_row_and_clears_error, test_syncLatestTrainingPlanSnapshot_keeps_hour_component_for_marathon_target, test_enforceRaceDayConsistency_restores_target_marathon_distance_and_pace, test_saveRecalculatedPlan_preserves_past_days_of_current_week_before_mutable_date, test_enforceRaceDayConsistency_preserves_intermediate_race_days, test_enforceRaceDayConsistency_clears_non_intermediate_non_main_race_days, test_buildRealismContextForReview_returns_null_for_health_state, test_buildRealismContextForReview_extracts_pace_strategy_fields, test_renderRealismFactLineForFallback_returns_empty_for_realistic_severity, test_renderRealismFactLineForFallback_lists_dry_facts_only, test_renderRealismFactLineForFallback_returns_empty_when_goal_equals_effective.
- Особенности: реальная БД (пользователи `recalc_proc_%`), почти все приватные методы через ReflectionMethod.

## `planrun-backend/tests/Unit/PlanGenerationQueueServiceTest.php` (193 строки)

`services/PlanGenerationQueueService` — очередь генерации: дедупликация активных задач, создание новой, поиск последней, восстановление зависших running-задач.
- Методы: test_enqueue_deduplicates_existing_active_job, test_enqueue_deduplicates_any_active_job_for_user, test_enqueue_creates_new_job_when_no_active_job_exists, test_findLatestJobForUser_returns_latest_job, test_recoverStaleRunningJobs_requeues_retryable_and_fails_exhausted_jobs.
- Особенности: fake-mysqli (QueueFakeResult/QueueFakeStmt/QueueFakeDb с whenPrepareContains/whenQueryContains); без реальной БД.

## `planrun-backend/tests/Unit/PlanGeneratorCorrectivePassTest.php` (187 строк)

`planrun_ai/plan_generator.php`: декодирование ответа генерации (короче/длиннее скелета) и корректирующая регенерация второго прохода.
- Методы: test_decodeGeneratedPlanResponse_throwsWhenPlanIsShorterThanSkeleton, test_decodeGeneratedPlanResponse_trimsWeeksLongerThanSkeleton, test_maybeApplyCorrectiveRegenerationToPlan_skipsSecondPassWithoutErrors, test_maybeApplyCorrectiveRegenerationToPlan_usesImprovedCorrectedPlan.
- Особенности: глобальные функции генератора, без LLM/БД.

## `planrun-backend/tests/Unit/PlanNormalizerTest.php` (1147 строк)

`planrun_ai/plan_normalizer.php` — нормализация сгенерированного плана: сохранение дистанций/структуры, дефолтные сегменты фартлека, перенос long run на предпочитаемый день, выравнивание quality-дней по скелету, pace/load/min-distance repairs по training state, fallback-структуры для tempo/control/fartlek/interval, тейпер.
- Методы: test_normalizeTrainingPlan_preserves_conservative_easy_and_tempo_distances, test_normalizeTrainingPlan_preserves_interval_structure_on_already_structured_day, test_normalizeTrainingPlan_adds_default_fartlek_segments_when_missing, test_normalizeTrainingPlan_converts_zero_long_run_to_rest, test_normalizeTrainingPlan_movesLongToLastPreferredWeekendDay, test_normalizeTrainingPlan_movesLongToLatestPreferredDayWhenNoWeekendSelected, test_normalizeTrainingPlan_keepsRaceOnPreferredLongDay, test_normalizeTrainingPlan_alignsQualityDaysToSkeletonAndRecomputesDates, test_normalizeTrainingPlan_enforcesLongAndRacePlacementFromSkeleton, test_normalizeTrainingPlan_coercesMissingSkeletonWorkoutType, test_applyTrainingStatePaceRepairs_clampsSimpleRunPacesToPolicy, test_updateSimpleRunDayAfterDistanceChange_drops_stale_numeric_easy_note, test_updateSimpleRunDayAfterDistanceChange_rebuilds_tempo_note_from_current_distance, test_applyControlWorkoutFallback_for_marathon_keeps_control_as_benchmark_not_mp_work, test_applyTrainingStatePaceRepairs_keeps_goal_specific_marathon_tempo_near_goal_pace, test_applyTrainingStatePaceRepairs_keeps_race_pace_tempo_at_goal_pace, test_applyTrainingStatePaceRepairs_repairs_race_pace_tempo_to_goal_pace, test_applyTrainingStateLoadRepairs_capsAggressiveSpikeWithoutBreakingLongRun, test_applyTrainingStateLoadRepairs_prefers_easy_and_long_cuts_before_interval, test_applyTrainingStateLoadRepairs_strengthensPreRaceAndRaceWeekTaper, test_applyTrainingStateLoadRepairs_can_retrim_easy_days_after_first_cutback_pass, test_applyTrainingStateMinimumDistanceRepairs_raises_short_easy_days_for_personal_override, test_applyTrainingStateWorkoutDetailFallbacks_expands_generic_tempo_into_meaningful_structure, test_applyTrainingStateWorkoutDetailFallbacks_enriches_generic_control_day, test_applyTrainingStateWorkoutDetailFallbacks_adds_segments_for_missing_fartlek_structure, test_applyTrainingStateWorkoutDetailFallbacks_adds_interval_structure_when_missing, test_applyTrainingStateWorkoutDetailFallbacks_enriches_generic_tempo_day, test_applyTrainingStateWorkoutDetailFallbacks_uses_week_contract_for_goal_specific_marathon_tempo, test_applyTrainingStateWorkoutDetailFallbacks_respects_recalculate_week_offset_in_contract, test_applyTrainingStateWorkoutDetailFallbacks_uses_shorter_interval_fallback_in_race_execution_week, test_normalizeTrainingPlan_repairs_adjacent_key_workouts_when_no_skeleton, test_applyTrainingStateLoadRepairs_keeps_race_week_day_types_for_model_to_decide, test_applyTrainingStateLoadRepairs_rebalances_long_share_after_volume_trim.
- Особенности: чистые функции над массивами плана; без БД/LLM.

## `planrun-backend/tests/Unit/PlanQualityGateTest.php` (1045 строк)

`services/PlanQualityGate::evaluate` — гейт качества плана: блокировки/послабления по tune-up неделям, taper-сценариям, race week, ложные spike/taper-срабатывания, детерминированные load repairs перед блокировкой, permissive vs strict режимы, контракт macro↔detail.
- Методы: test_workout_completeness_rejects_duration_only_fartlek, test_evaluate_blocks_tune_up_week_with_long_run_and_extra_quality, test_evaluate_passes_controlled_tune_up_week, test_evaluate_relaxes_required_run_day_contract_for_short_taper_scenarios, test_evaluate_relaxes_required_run_day_contract_for_fresh_long_effort_recovery_week, test_evaluate_relaxes_required_run_day_contract_for_race_week_run_day_cap, test_evaluate_relaxes_required_run_day_contract_for_any_race_week, test_evaluate_allows_conservative_low_volume_10k_progression_without_false_spike_or_taper_issue, test_evaluate_applies_deterministic_load_repairs_before_blocking_save, test_evaluate_does_not_flag_week_after_recovery_when_it_only_rebounds_to_normal_load, test_evaluate_does_not_flag_base_reentry_below_high_weekly_base, test_evaluate_allows_high_base_reentry_near_ninety_percent_of_base, test_evaluate_preserves_recovery_week_metadata_under_schedule_enforcement, test_evaluate_surfaces_goal_feasibility_warning_without_blocking_save, test_evaluate_downgrades_volume_spike_for_high_caution_scenario, test_evaluate_blocks_llm_planner_prompt_contract_violations, test_evaluate_downgrades_llm_planner_contract_violations_in_permissive_mode, test_evaluate_allows_short_race_long_run_to_exceed_race_distance, test_evaluate_accepts_detail_week_target_when_macro_is_revised_with_reason, test_evaluate_skips_macro_detail_contract_for_single_pass_planner.
- Особенности: чистая логика над массивами; без БД.

## `planrun-backend/tests/Unit/PlanReadinessCheckServiceTest.php` (128 строк)

`services/PlanReadinessCheckService` — pending-проверка готовности (после устаревшего сигнала боли + последующих пробежек), ответ снимает блокировку генерации.
- Методы: test_maybeCreatePendingCheck_asks_after_stale_pain_and_later_runs, test_submitAnswer_makes_same_source_no_longer_block_generation, test_maybeCreatePendingCheck_does_not_ask_without_later_run.
- Особенности: реальная БД (пользователи `plan_readiness_%`), зависимость PostWorkoutFollowupService.

## `planrun-backend/tests/Unit/PlanReviewGeneratorTest.php` (202 строки)

`planrun_ai/plan_review_generator.php` — генерация текстового обзора плана: summary по дням, санитизация формулировок про race day, замена англицизмов/taper-лексики, realism facts/directive, polish тона.
- Методы: test_buildPlanSummaryForReview_keeps_explicit_day_types_when_description_exists, test_sanitizePlanReviewContent_removes_sentences_that_describe_race_day_as_long_run_build_up, test_sanitizePlanReviewContent_removes_race_day_activation_framing, test_applyPlanReviewLanguageReplacements_translates_remaining_anglicisms, test_applyPlanReviewLanguageReplacements_replaces_taper_wording_with_human_russian, test_buildRealismFacts_lists_target_facts_without_interpretation, test_buildRealismDirective_is_neutral_and_not_a_template_phrase, test_buildRealismFacts_returns_empty_for_realistic_severity, test_buildRealismDirective_returns_empty_for_realistic_severity, test_buildRealismFacts_works_for_moderate_severity_too, test_polishPlanReviewTone_makes_text_shorter_and_less_bureaucratic.
- Особенности: чистые функции, без LLM/БД.

## `planrun-backend/tests/Unit/PlanScenarioResolverTest.php` (142 строки)

`services/PlanScenarioResolver::resolve` — выбор планировочного сценария: выравнивание anchor, приоритеты return_after_break / B-race перед A-race / overload recovery.
- Методы: test_resolve_aligns_schedule_anchor_and_extends_short_runway_race_week, test_resolve_prioritizes_return_after_break_over_generic_race_build, test_resolve_detects_b_race_before_a_race_and_downgrades_to_control, test_resolve_prioritizes_b_race_before_a_race_over_overload_recovery.
- Особенности: чистая логика; без БД.

## `planrun-backend/tests/Unit/PlanSkeletonBuilderTest.php` (330 строк)

`services/PlanSkeletonBuilder::build` — построение скелета плана: размещение long/race/quality-дней, защитные режимы для особых популяций, simplified quality, initial recovery week.
- Методы: test_build_places_long_on_last_preferred_weekend_day, test_build_assigns_quality_days_in_build_phase_without_breaking_long_day, test_build_places_race_on_exact_race_day_and_removes_long_from_that_week, test_build_suppresses_quality_for_conservative_special_population_flags, test_build_peak_marathon_phase_can_include_interval_as_second_quality, test_build_simplified_quality_mode_reduces_peak_week_to_single_milder_quality, test_build_base_phase_with_short_runway_allows_one_quality_session, test_build_low_base_novice_short_race_trims_race_and_post_race_weeks, test_build_forceInitialRecoveryWeek_keeps_first_week_without_quality, test_build_initialRecoveryRunDayCap_trims_first_recovery_week.
- Особенности: без БД.

## `planrun-backend/tests/Unit/PlanSkeletonBuilderTuneUpRaceTest.php` (99 строк)

`services/PlanSkeletonBuilder` — tune-up (промежуточные) старты: размещение на явный день, паттерн race week после контрольной.
- Методы: test_build_places_tune_up_event_on_explicit_day_and_removes_extra_quality, test_build_race_week_after_control_keeps_late_week_shakeout_pattern.
- Особенности: без БД.

## `planrun-backend/tests/Unit/PlanValidatorTest.php` (694 строки)

`planrun_ai/plan_validator.php` — валидация нормализованного плана: спайки объёма, коридоры темпов easy/tempo/interval/fartlek, структура tempo/control, предпочитаемые дни, тейпер марафона, послабления для return_after_injury.
- Методы: test_validateNormalizedPlanAgainstTrainingState_warnsOnAggressiveVolumeSpike, test_validateNormalizedPlanAgainstTrainingState_warnsOnEasyPaceOutsideCorridor, test_collectNormalizedPlanValidationIssues_marksLargeTempoDeviationAsError, test_collectNormalizedPlanValidationIssues_flagsTempoWithoutConcreteStructure, test_collectNormalizedPlanValidationIssues_allowsTempoWithConcreteNotesStructure, test_collectNormalizedPlanValidationIssues_allows_goal_specific_marathon_tempo_near_goal_pace, test_collectNormalizedPlanValidationIssues_allows_goal_specific_marathon_tempo_by_week_contract, test_collectNormalizedPlanValidationIssues_uses_threshold_for_marathon_context_without_goal_specific_tempo, test_collectNormalizedPlanValidationIssues_uses_goal_pace_for_race_pace_subtype, test_collectNormalizedPlanValidationIssues_flagsControlWithoutConcreteTask, test_collectNormalizedPlanValidationIssues_flagsTempoStimulusTooSmallRelativeToTrainingState, test_collectNormalizedPlanValidationIssues_flagsRunOutsidePreferredDays, test_collectNormalizedPlanValidationIssues_flags_invalid_week_day_count_without_php_warning, test_collectNormalizedPlanValidationIssues_flagsTooMuchIntensityForHealthGoal, test_collectNormalizedPlanValidationIssues_flagsWeakMarathonTaper, test_collectNormalizedPlanValidationIssues_doesNotWarnWhenShortRaceWeekMatchesSupplementaryCap, test_collectNormalizedPlanValidationIssues_allows_race_day_only_for_return_after_injury_race_week, test_collectNormalizedPlanValidationIssues_still_flags_extra_quality_for_return_after_injury, test_collectPaceValidationIssues_flagsIntervalPaceTooSlow, test_collectPaceValidationIssues_acceptsIntervalPaceWithinTolerance, test_collectPaceValidationIssues_flagsRacePaceTempoFarFromGoalPace, test_collectPaceValidationIssues_acceptsRacePaceTempoNearGoalPace, test_collectPaceValidationIssues_flagsFartlekFastSegmentWayTooSlow, test_collectPaceValidationIssues_ignoresFartlekRecoverySegments, test_collectPaceValidationIssues_flagsFartlekIntervalSegmentTooSlow.
- Особенности: чистые функции; без БД.

## `planrun-backend/tests/Unit/PostWorkoutFollowupServiceTest.php` (610 строк)

`services/PostWorkoutFollowupService` — пост-тренировочный чек-ин: планирование followup, обработка ответа пользователя (заметка дня, без LLM), аналитика фидбэка, состояние pending check-in, skip ходьбы, supersede старых followup, snooze.
- Методы: test_scheduleForWorkout_createsPendingFollowupForRecentManualWorkout, test_tryHandleUserReply_savesDayNoteAndCompletesFollowup, test_chatService_routesFirstReplyToPostWorkoutFollowupWithoutLlm, test_getRecentFeedbackAnalytics_computesStructuredMetricDeltasFromBaseline, test_getPendingCheckinState_returnsReadySentFollowupPayload, test_getPendingCheckinState_returnsUpcomingPendingFollowupBeforeDueTime, test_scheduleForWorkout_skipsWalkingActivities, test_scheduleForWorkout_supersedesOlderActiveFollowupsForSameUser, test_snoozeFollowup_persists_snoozed_until_and_hides_modal_until_due, test_tryHandleUserReply_treatsLocalizedOverloadWithoutPainAsFatigueSignal.
- Особенности: реальная БД (пользователи `post_followup_%`), интеграция с ChatService/ChatRepository.

## `planrun-backend/tests/Unit/PromptBuilderTrainingStateTest.php` (316 строк)

`planrun_ai/prompt_builder.php` — блоки промпта генерации плана: pace zones из training state, week skeleton block, special population flags, feedback/signals summary, schedule overrides из reason, flexible recalc prompt, макроцикл марафона.
- Методы: test_buildPaceZonesBlock_prefersTrainingStateOverLegacyFallback, test_buildTrainingPlanPrompt_includes_week_skeleton_block_when_present, test_buildTrainingStateBlock_includes_special_population_flags, test_buildTrainingStateBlock_includes_recent_feedback_analytics_summary, test_buildTrainingStateBlock_includes_athlete_signals_summary, test_applyScheduleOverridesToUserData_extracts_long_and_rest_days_from_reason, test_applyScheduleOverridesToUserData_extracts_benchmark_and_easy_floor_from_reason, test_buildRecalculationPrompt_flexible_mode_omits_training_state_and_week_skeleton, test_buildTrainingPlanPrompt_marathon_explains_control_is_not_regular_mp_work, test_buildRecalculationPrompt_flexible_mode_includes_workout_intent_block, test_computeMacrocycle_marathon_control_weeks_are_rare, test_computeMacrocycle_first_marathon_twenty_weeks_supports_peak_long_run.
- Особенности: глобальные функции; без БД.

## `planrun-backend/tests/Unit/RateLimiterActionBucketTest.php` (24 строки)

`config/RateLimiter.php` — маппинг actions на бакеты лимитов (default / plan_generation / chat).
- Методы: test_plan_notifications_use_default_bucket, test_plan_generation_actions_use_plan_generation_bucket, test_chat_send_actions_use_chat_bucket.
- Особенности: без БД.

## `planrun-backend/tests/Unit/RecalculationContextTest.php` (58 строк)

`planrun_ai/plan_generator.php` — контекст пересчёта: cutoff date (сегодня/завтра при наличии тренировки), фильтр running-relevant записей (отсев ходьбы, manual run без typed plan).
- Методы: test_resolveRecalculationCutoffDateValue_returns_today_when_no_workout_today, test_resolveRecalculationCutoffDateValue_returns_tomorrow_when_workout_today_exists, test_isRunningRelevantWorkoutEntry_acceptsRunningPlanTypes, test_isRunningRelevantWorkoutEntry_rejectsWalkingImports, test_isRunningRelevantWorkoutEntry_keepsManualCompletedRunEvenWithoutTypedPlan, test_resolveRecalculationCutoffDateValue_ignores_non_running_activity_for_today_rule.
- Особенности: чистые функции; без БД.

## `planrun-backend/tests/Unit/RegisterApiServiceTest.php` (41 строка)

`services/RegisterApiService` — делегация валидации RegistrationService, отказ невалидному email до внешних вызовов.
- Методы: test_validateField_delegates_to_registration_service, test_sendVerificationCode_rejects_invalid_email_before_external_calls.
- Особенности: fake RegistrationService как анонимный класс; без БД.

## `planrun-backend/tests/Unit/RegistrationServiceTest.php` (173 строки)

`services/RegistrationService` — валидация username, запрет регистрации (site settings), registerMinimal: ошибка верификации и успешное создание пользователя.
- Методы: test_validateField_rejects_short_username_before_db_lookup, test_prepareRegistrationIdentity_rejects_when_registration_disabled, test_registerMinimal_returns_verification_error_payload, test_registerMinimal_creates_user_and_returns_payload.
- Особенности: fake-mysqli (FakeResult/FakeStmt/FakeDb с whenPrepareContains/whenQueryContains) + 4 PHPUnit `createMock` (EmailVerificationService и пр.) — единственный файл с настоящими PHPUnit-моками; без реальной БД.

## `planrun-backend/tests/Unit/RepositoryTest.php` (54 строки)

Репозитории `TrainingPlanRepository`/`WorkoutRepository`/`StatsRepository` — поведение для несуществующего пользователя (null/empty/zero).
- Методы: test_trainingPlanRepository_getPlanByUserId_returnsNullForNonExistentUser, test_workoutRepository_getAllResults_returnsEmptyForNonExistentUser, test_statsRepository_getTotalDays_returnsZeroForNonExistentUser.
- Особенности: реальная БД (smoke-уровень).

## `planrun-backend/tests/Unit/StatsServiceTest.php` (49 строк)

`services/StatsService` — пустые результаты для несуществующего пользователя.
- Методы: test_getStats_returnsZeroForNonExistentUser, test_getAllWorkoutsSummary_returnsEmptyForNonExistentUser.
- Особенности: реальная БД (smoke-уровень).

## `planrun-backend/tests/Unit/TrainingPlanServiceTest.php` (65 строк)

`services/TrainingPlanService` — loadPlan/checkPlanStatus для несуществующего пользователя, clearPlanGenerationMessage.
- Методы: test_loadPlan_returnsEmptyForNonExistentUser, test_checkPlanStatus_returnsNoPlanForNonExistentUser, test_clearPlanGenerationMessage_works.
- Особенности: реальная БД (пользователи `plan_service_%`).

## `planrun-backend/tests/Unit/TrainingStateBuilderTest.php` (1131 строка)

`services/TrainingStateBuilder::buildForUser` — построение training state: special population flags, консервативные профили, benchmark overrides, субъективный фидбэк → readiness/pain/fatigue, заметки как сигналы, эрозия базы после перерыва, planning scenario, goal realism, pace strategy, feature-флаги, plan readiness check, recent compliance/workouts/season/best races; плюс compliance summary и peak volume floor.
- Методы: test_buildForUser_derives_special_population_flags_and_preferred_long_day, test_buildForUser_uses_conservative_repair_profile_for_low_base_first_long_race, test_buildForUser_enables_low_base_novice_short_race_protections, test_buildForUser_uses_reason_benchmark_override_and_personal_easy_floor, test_buildForUser_uses_recent_subjective_feedback_to_lower_readiness_and_add_pain_flag, test_buildForUser_uses_structured_load_spike_to_add_fatigue_flag_and_tighten_growth, test_buildForUser_does_not_double_penalize_single_moderate_feedback_via_overall_risk, test_buildForUser_uses_day_and_week_notes_as_additional_athlete_signals, test_buildForUser_reduces_effective_weekly_base_after_month_break, test_buildForUser_populates_planning_scenario_for_b_race_before_a_race, test_buildForUser_populates_goal_realism_for_unrealistic_marathon_target, test_buildForUser_omits_goal_realism_for_health_goal, test_buildForUser_pace_strategy_falls_back_to_realistic_target_for_major_severity, test_buildForUser_pace_strategy_uses_goal_target_for_realistic_severity, test_buildForUser_pace_strategy_omitted_for_health_goal, test_buildForUser_skips_scenario_fields_when_feature_flag_disabled, test_buildForUser_uses_clear_plan_readiness_check_to_unblock_stale_pain_signal, test_buildForUser_includes_recent_compliance_for_completed_workouts, test_buildForUser_includes_recent_workouts_detailed_with_rpe_hr_pace, test_buildForUser_includes_season_climate_context, test_buildForUser_flips_hemisphere_for_southern_timezone, test_buildForUser_includes_best_races_progression, test_buildForUser_skips_recent_context_when_feature_flag_disabled, test_compliance_summary_returns_empty_string_for_no_data, test_compliance_summary_describes_period_without_plan, test_compliance_summary_includes_planned_completed_and_keys, test_compliance_summary_splits_planned_and_unplanned_segments, test_compliance_summary_detects_overperforming_above_base, test_compliance_summary_detects_underperforming_below_base, test_compliance_summary_detects_volume_trend_decrease, test_peak_volume_floor_km_returns_max_actual, test_peak_volume_floor_km_excludes_outlier_race_week, test_peak_volume_floor_km_uses_base_when_actuals_low, test_peak_volume_floor_km_returns_null_for_no_data, test_buildForUser_attaches_recent_compliance_summary_and_peak_floor.
- Особенности: реальная БД (пользователи `training_state_%`, `feedback_state_%`, `workout_rating_%`), private-методы через Reflection.

## `planrun-backend/tests/Unit/VdotCalculationTest.php` (278 строк)

VDOT-расчёты (`planrun_ai/prompt_builder.php` + `services/TrainingStateBuilder`): estimateVDOT, predictRaceTime, training paces, приоритет источников (benchmark > свежий race > easy pace), коэффициент целевого времени 0.92, деградация confidence, assessGoalRealism, форматирование pace/time.
- Методы: test_estimateVDOT_known_values, test_estimateVDOT_clamped_to_bounds, test_predictRaceTime_roundtrips_with_estimateVDOT, test_getTrainingPaces_zones_order, test_source_priority_benchmark_override_wins, test_source_priority_fresh_last_race_over_easy_pace, test_source_stale_last_race_not_used_as_fresh, test_target_time_applies_092_coefficient, test_confidence_degrades_with_inactivity, test_assessGoalRealism_uses_training_state_vdot, test_assessGoalRealism_falls_back_to_last_race, test_assessGoalRealism_registration_context_is_advisory_not_blocking, test_predictAllRaceTimes_returns_all_distances, test_formatPaceSec, test_formatTimeSec, test_buildForUser_parses_time_formats_correctly.
- Особенности: TrainingStateBuilder создаётся с реальным DB-коннектом, но проверяется чистая математика.

## `planrun-backend/tests/Unit/WorkoutPlanRecalculationServiceTest.php` (151 строка)

`services/WorkoutPlanRecalculationService` — триггер пересчёта плана после тренировки: skip при малом числе будущих тренировок или малой дельте VDOT, постановка в очередь при значимой дельте.
- Методы: test_skips_when_too_few_future_workouts_remain, test_skips_when_vdot_change_is_too_small, test_queues_recalculation_when_future_plan_exists_and_delta_is_significant.
- Особенности: fake-mysqli (WorkoutPlanUpdateFakeResult/Stmt/Db); без реальной БД.

## `planrun-backend/tests/Unit/WorkoutServiceTest.php` (174 строки)

`services/WorkoutService` — результаты тренировок: пустые ответы для несуществующих сущностей, saveResult планирует post-workout followup и добавляет сообщение анализа при включённой настройке.
- Методы: test_getAllResults_returnsEmptyForNonExistentUser, test_getResult_returnsNullForNonExistentWorkout, test_saveResult_schedulesPostWorkoutFollowup, test_saveResult_addsPostWorkoutAnalysisMessageWhenEnabled.
- Особенности: реальная БД.
