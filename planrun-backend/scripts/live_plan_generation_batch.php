#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Batch live generation for synthetic registration users.
 *
 * Creates diverse users through RegistrationService::registerFull(), runs the
 * same processor used by the queue worker, then evaluates saved plans with
 * coach-facing heuristics.
 *
 * Usage:
 *   php scripts/live_plan_generation_batch.php --limit=50
 *   php scripts/live_plan_generation_batch.php --limit=5 --prefix=live50_smoke
 *   php scripts/live_plan_generation_batch.php --skip-generation=1 --prefix=live50_smoke
 */

set_time_limit(0);

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/RegistrationService.php';
require_once $baseDir . '/services/PlanGenerationQueueService.php';
require_once $baseDir . '/services/PlanGenerationProcessorService.php';

function liveBatchParseArgs(array $argv): array
{
    $args = [
        'limit' => '50',
        'prefix' => 'live50_' . gmdate('Ymd_His'),
        'start-date' => liveBatchNextMonday(gmdate('Y-m-d')),
        'save-dir' => dirname(__DIR__) . '/tmp/live_plan_generation',
        'skip-generation' => '0',
        'reuse-existing' => '1',
        'fast-llm-fallback' => '0',
    ];

    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0] ?? '';
        $value = $parts[1] ?? '1';
        if ($key !== '') {
            $args[$key] = $value;
        }
    }

    return $args;
}

function liveBatchBool(mixed $value): bool
{
    return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
}

function liveBatchNextMonday(string $date): string
{
    $dt = new DateTimeImmutable($date);
    $day = (int) $dt->format('N');
    $diff = $day === 1 ? 0 : (8 - $day);
    return $dt->modify("+{$diff} days")->format('Y-m-d');
}

function liveBatchDateAddWeeks(string $date, int $weeks, int $extraDays = 0): string
{
    return (new DateTimeImmutable($date))
        ->modify("+{$weeks} weeks")
        ->modify("+{$extraDays} days")
        ->format('Y-m-d');
}

function liveBatchJsonDays(array $days): string
{
    return json_encode(array_values($days), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function liveBatchUsername(string $prefix, int $number, string $code): string
{
    $username = sprintf('%s_%02d_%s', $prefix, $number, $code);
    return strlen($username) <= 50 ? $username : substr($username, 0, 50);
}

function liveBatchBaseProfile(string $startDate, array $overrides): array
{
    $preferredDays = $overrides['preferred_days'] ?? ['mon', 'wed', 'fri'];
    $preferredOfpDays = $overrides['preferred_ofp_days'] ?? [];

    $base = [
        'password' => 'PlanrunLive50!2026',
        'training_mode' => 'ai',
        'training_start_date' => $startDate,
        'gender' => 'male',
        'birth_year' => 1992,
        'height_cm' => 176,
        'weight_kg' => 72.0,
        'goal_type' => 'health',
        'experience_level' => 'novice',
        'weekly_base_km' => 10.0,
        'sessions_per_week' => count($preferredDays),
        'preferred_days' => liveBatchJsonDays($preferredDays),
        'preferred_ofp_days' => liveBatchJsonDays($preferredOfpDays),
        'has_treadmill' => 0,
        'ofp_preference' => !empty($preferredOfpDays) ? 'home' : null,
        'training_time_pref' => 'morning',
        'health_notes' => null,
        'device_type' => 'garmin',
        'health_program' => 'regular_running',
        'health_plan_weeks' => 8,
        'current_running_level' => 'basic',
        'running_experience' => '6_12m',
        'easy_pace_sec' => 390,
        'is_first_race_at_distance' => null,
        'last_race_distance' => null,
        'last_race_distance_km' => null,
        'last_race_time' => null,
        'last_race_date' => null,
    ];

    $profile = array_merge($base, $overrides);
    $profile['preferred_days'] = is_array($profile['preferred_days'])
        ? liveBatchJsonDays($profile['preferred_days'])
        : $profile['preferred_days'];
    $profile['preferred_ofp_days'] = is_array($profile['preferred_ofp_days'])
        ? liveBatchJsonDays($profile['preferred_ofp_days'])
        : $profile['preferred_ofp_days'];
    $decodedDays = json_decode((string) $profile['preferred_days'], true);
    if (is_array($decodedDays) && !empty($decodedDays)) {
        $profile['sessions_per_week'] = count($decodedDays);
    }

    return $profile;
}

function liveBatchBuildProfiles(string $prefix, string $startDate): array
{
    $cases = [
        ['novice_couch_5k_3d', ['goal_type' => 'health', 'gender' => 'female', 'birth_year' => 1998, 'height_cm' => 164, 'weight_kg' => 72, 'experience_level' => 'novice', 'weekly_base_km' => 0, 'preferred_days' => ['mon', 'wed', 'sat'], 'health_program' => 'couch_to_5k', 'health_plan_weeks' => 10, 'current_running_level' => 'zero', 'running_experience' => 'less_3m', 'easy_pace_sec' => 450]],
        ['start_running_zero_2d', ['goal_type' => 'health', 'gender' => 'male', 'birth_year' => 1989, 'height_cm' => 182, 'weight_kg' => 94, 'experience_level' => 'novice', 'weekly_base_km' => 0, 'preferred_days' => ['tue', 'sat'], 'health_program' => 'start_running', 'health_plan_weeks' => 8, 'current_running_level' => 'zero', 'running_experience' => 'less_3m', 'easy_pace_sec' => 480]],
        ['health_basic_3d_home_ofp', ['goal_type' => 'health', 'gender' => 'female', 'birth_year' => 1985, 'height_cm' => 169, 'weight_kg' => 67, 'experience_level' => 'beginner', 'weekly_base_km' => 9, 'preferred_days' => ['mon', 'thu', 'sun'], 'preferred_ofp_days' => ['wed'], 'health_program' => 'regular_running', 'health_plan_weeks' => 8, 'current_running_level' => 'basic', 'running_experience' => '3_6m', 'easy_pace_sec' => 430]],
        ['health_regular_4d', ['goal_type' => 'health', 'gender' => 'male', 'birth_year' => 1977, 'height_cm' => 178, 'weight_kg' => 81, 'experience_level' => 'intermediate', 'weekly_base_km' => 22, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'preferred_ofp_days' => ['tue'], 'health_program' => 'regular_running', 'health_plan_weeks' => 10, 'current_running_level' => 'comfortable', 'running_experience' => '1_2y', 'easy_pace_sec' => 375]],
        ['health_older_adult_4d', ['goal_type' => 'health', 'gender' => 'male', 'birth_year' => 1958, 'height_cm' => 173, 'weight_kg' => 76, 'experience_level' => 'novice', 'weekly_base_km' => 12, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'preferred_ofp_days' => ['tue'], 'health_program' => 'regular_running', 'health_plan_weeks' => 8, 'health_notes' => '65+, цель - регулярный бег без перегруза', 'current_running_level' => 'basic', 'running_experience' => '6_12m', 'easy_pace_sec' => 430]],
        ['health_postpartum_3d', ['goal_type' => 'health', 'gender' => 'female', 'birth_year' => 1991, 'height_cm' => 166, 'weight_kg' => 69, 'experience_level' => 'intermediate', 'weekly_base_km' => 14, 'preferred_days' => ['tue', 'thu', 'sun'], 'health_program' => 'regular_running', 'health_plan_weeks' => 6, 'health_notes' => 'Послеродовое восстановление, нужен щадящий режим', 'current_running_level' => 'basic', 'running_experience' => 'more_2y', 'easy_pace_sec' => 410]],
        ['health_treadmill_evening', ['goal_type' => 'health', 'gender' => 'female', 'birth_year' => 1982, 'height_cm' => 171, 'weight_kg' => 74, 'experience_level' => 'beginner', 'weekly_base_km' => 16, 'preferred_days' => ['mon', 'wed', 'sat'], 'has_treadmill' => 1, 'training_time_pref' => 'evening', 'health_program' => 'regular_running', 'health_plan_weeks' => 8, 'easy_pace_sec' => 405]],
        ['health_custom_12w_5d', ['goal_type' => 'health', 'gender' => 'male', 'birth_year' => 1990, 'height_cm' => 180, 'weight_kg' => 78, 'experience_level' => 'intermediate', 'weekly_base_km' => 30, 'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'], 'preferred_ofp_days' => ['wed'], 'health_program' => 'custom', 'health_plan_weeks' => 12, 'current_running_level' => 'comfortable', 'running_experience' => 'more_2y', 'easy_pace_sec' => 360]],
        ['health_low_back_care', ['goal_type' => 'health', 'gender' => 'male', 'birth_year' => 1972, 'height_cm' => 176, 'weight_kg' => 86, 'experience_level' => 'beginner', 'weekly_base_km' => 8, 'preferred_days' => ['tue', 'fri', 'sun'], 'preferred_ofp_days' => ['thu'], 'health_program' => 'regular_running', 'health_plan_weeks' => 8, 'health_notes' => 'Иногда болит поясница, избегать резких ускорений', 'easy_pace_sec' => 435]],
        ['health_speed_light', ['goal_type' => 'health', 'gender' => 'female', 'birth_year' => 1995, 'height_cm' => 160, 'weight_kg' => 55, 'experience_level' => 'intermediate', 'weekly_base_km' => 26, 'preferred_days' => ['mon', 'wed', 'fri', 'sat'], 'health_program' => 'regular_running', 'health_plan_weeks' => 10, 'current_running_level' => 'comfortable', 'running_experience' => '1_2y', 'easy_pace_sec' => 355]],

        ['weight_loss_low_base_4d', ['goal_type' => 'weight_loss', 'gender' => 'female', 'birth_year' => 1988, 'height_cm' => 168, 'weight_kg' => 92, 'weight_goal_kg' => 84, 'weight_goal_date' => liveBatchDateAddWeeks($startDate, 12), 'experience_level' => 'beginner', 'weekly_base_km' => 8, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'preferred_ofp_days' => ['tue'], 'health_program' => null, 'easy_pace_sec' => 430]],
        ['weight_loss_obese_3d', ['goal_type' => 'weight_loss', 'gender' => 'male', 'birth_year' => 1983, 'height_cm' => 181, 'weight_kg' => 108, 'weight_goal_kg' => 99, 'weight_goal_date' => liveBatchDateAddWeeks($startDate, 14), 'experience_level' => 'novice', 'weekly_base_km' => 4, 'preferred_days' => ['tue', 'thu', 'sat'], 'health_notes' => 'Большой вес, нужна осторожная прогрессия', 'health_program' => null, 'easy_pace_sec' => 480]],
        ['weight_loss_intermediate_5d', ['goal_type' => 'weight_loss', 'gender' => 'male', 'birth_year' => 1991, 'height_cm' => 177, 'weight_kg' => 88, 'weight_goal_kg' => 80, 'weight_goal_date' => liveBatchDateAddWeeks($startDate, 16), 'experience_level' => 'intermediate', 'weekly_base_km' => 28, 'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'], 'preferred_ofp_days' => ['wed'], 'health_program' => null, 'easy_pace_sec' => 375]],
        ['weight_loss_knee_note', ['goal_type' => 'weight_loss', 'gender' => 'female', 'birth_year' => 1979, 'height_cm' => 165, 'weight_kg' => 86, 'weight_goal_kg' => 78, 'weight_goal_date' => liveBatchDateAddWeeks($startDate, 10), 'experience_level' => 'beginner', 'weekly_base_km' => 10, 'preferred_days' => ['mon', 'thu', 'sun'], 'health_notes' => 'Иногда беспокоит колено, не давать резких интервалов', 'health_program' => null, 'easy_pace_sec' => 440]],
        ['weight_loss_treadmill_4d', ['goal_type' => 'weight_loss', 'gender' => 'female', 'birth_year' => 1994, 'height_cm' => 172, 'weight_kg' => 82, 'weight_goal_kg' => 75, 'weight_goal_date' => liveBatchDateAddWeeks($startDate, 12), 'experience_level' => 'beginner', 'weekly_base_km' => 14, 'preferred_days' => ['tue', 'thu', 'sat', 'sun'], 'has_treadmill' => 1, 'training_time_pref' => 'evening', 'health_program' => null, 'easy_pace_sec' => 420]],
        ['weight_loss_older_4d', ['goal_type' => 'weight_loss', 'gender' => 'male', 'birth_year' => 1962, 'height_cm' => 174, 'weight_kg' => 91, 'weight_goal_kg' => 84, 'weight_goal_date' => liveBatchDateAddWeeks($startDate, 14), 'experience_level' => 'novice', 'weekly_base_km' => 9, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'health_notes' => '60+, контроль веса и здоровья', 'health_program' => null, 'easy_pace_sec' => 455]],
        ['weight_loss_ex_runner', ['goal_type' => 'weight_loss', 'gender' => 'female', 'birth_year' => 1986, 'height_cm' => 170, 'weight_kg' => 79, 'weight_goal_kg' => 72, 'weight_goal_date' => liveBatchDateAddWeeks($startDate, 12), 'experience_level' => 'intermediate', 'weekly_base_km' => 24, 'preferred_days' => ['mon', 'wed', 'fri', 'sat'], 'last_race_distance' => '10k', 'last_race_time' => '00:56:30', 'last_race_date' => '2025-10-05', 'health_program' => null, 'easy_pace_sec' => 395]],
        ['weight_loss_high_base', ['goal_type' => 'weight_loss', 'gender' => 'male', 'birth_year' => 1975, 'height_cm' => 183, 'weight_kg' => 96, 'weight_goal_kg' => 88, 'weight_goal_date' => liveBatchDateAddWeeks($startDate, 18), 'experience_level' => 'advanced', 'weekly_base_km' => 42, 'preferred_days' => ['mon', 'tue', 'thu', 'fri', 'sun'], 'preferred_ofp_days' => ['sat'], 'health_program' => null, 'easy_pace_sec' => 345]],

        ['race_5k_first_6w', ['goal_type' => 'race', 'race_distance' => '5k', 'race_date' => liveBatchDateAddWeeks($startDate, 6, 6), 'race_target_time' => '00:32:00', 'gender' => 'female', 'birth_year' => 1997, 'height_cm' => 162, 'weight_kg' => 60, 'experience_level' => 'novice', 'weekly_base_km' => 8, 'preferred_days' => ['mon', 'wed', 'sat'], 'is_first_race_at_distance' => 1, 'health_program' => null, 'easy_pace_sec' => 430]],
        ['race_5k_sub25_10w', ['goal_type' => 'race', 'race_distance' => '5k', 'race_date' => liveBatchDateAddWeeks($startDate, 10, 6), 'race_target_time' => '00:25:00', 'gender' => 'male', 'birth_year' => 1993, 'height_cm' => 175, 'weight_kg' => 70, 'experience_level' => 'intermediate', 'weekly_base_km' => 25, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'last_race_distance' => '5k', 'last_race_time' => '00:26:40', 'last_race_date' => '2026-02-15', 'health_program' => null, 'easy_pace_sec' => 375]],
        ['race_5k_advanced_6w', ['goal_type' => 'race', 'race_distance' => '5k', 'race_date' => liveBatchDateAddWeeks($startDate, 6, 6), 'race_target_time' => '00:18:45', 'gender' => 'male', 'birth_year' => 1999, 'height_cm' => 178, 'weight_kg' => 64, 'experience_level' => 'advanced', 'weekly_base_km' => 48, 'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'], 'last_race_distance' => '5k', 'last_race_time' => '00:19:20', 'last_race_date' => '2026-03-08', 'health_program' => null, 'easy_pace_sec' => 330]],
        ['race_10k_weekday_8w', ['goal_type' => 'race', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 8, 6), 'race_target_time' => '00:55:00', 'gender' => 'female', 'birth_year' => 1984, 'height_cm' => 167, 'weight_kg' => 66, 'experience_level' => 'beginner', 'weekly_base_km' => 18, 'preferred_days' => ['mon', 'wed', 'fri'], 'is_first_race_at_distance' => 1, 'health_program' => null, 'easy_pace_sec' => 410]],
        ['race_10k_intermediate_12w', ['goal_type' => 'race', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 12, 6), 'race_target_time' => '00:47:00', 'gender' => 'male', 'birth_year' => 1987, 'height_cm' => 180, 'weight_kg' => 74, 'experience_level' => 'intermediate', 'weekly_base_km' => 32, 'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'], 'last_race_distance' => '5k', 'last_race_time' => '00:22:20', 'last_race_date' => '2026-01-18', 'health_program' => null, 'easy_pace_sec' => 360]],
        ['race_10k_short_runway', ['goal_type' => 'race', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 3, 6), 'race_target_time' => '00:52:00', 'gender' => 'male', 'birth_year' => 1981, 'height_cm' => 176, 'weight_kg' => 78, 'experience_level' => 'intermediate', 'weekly_base_km' => 30, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'last_race_distance' => '10k', 'last_race_time' => '00:54:10', 'last_race_date' => '2025-11-10', 'health_program' => null, 'easy_pace_sec' => 390]],
        ['race_10k_after_injury', ['goal_type' => 'race', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 10, 6), 'race_target_time' => '00:50:00', 'gender' => 'female', 'birth_year' => 1990, 'height_cm' => 170, 'weight_kg' => 63, 'experience_level' => 'intermediate', 'weekly_base_km' => 16, 'preferred_days' => ['mon', 'wed', 'sat', 'sun'], 'health_notes' => 'Возвращение после травмы ахилла', 'last_race_distance' => '5k', 'last_race_time' => '00:23:50', 'last_race_date' => '2025-08-12', 'health_program' => null, 'easy_pace_sec' => 395]],
        ['race_half_first_low_base', ['goal_type' => 'race', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 10, 6), 'race_target_time' => '02:15:00', 'gender' => 'female', 'birth_year' => 1996, 'height_cm' => 165, 'weight_kg' => 64, 'experience_level' => 'beginner', 'weekly_base_km' => 14, 'preferred_days' => ['mon', 'wed', 'sat', 'sun'], 'is_first_race_at_distance' => 1, 'health_program' => null, 'easy_pace_sec' => 420]],
        ['race_half_intermediate_16w', ['goal_type' => 'race', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 16, 6), 'race_target_time' => '01:45:00', 'gender' => 'male', 'birth_year' => 1986, 'height_cm' => 181, 'weight_kg' => 73, 'experience_level' => 'intermediate', 'weekly_base_km' => 38, 'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'], 'last_race_distance' => '10k', 'last_race_time' => '00:47:30', 'last_race_date' => '2026-02-08', 'health_program' => null, 'easy_pace_sec' => 360]],
        ['race_half_advanced_12w', ['goal_type' => 'race', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 12, 6), 'race_target_time' => '01:24:00', 'gender' => 'male', 'birth_year' => 1994, 'height_cm' => 174, 'weight_kg' => 62, 'experience_level' => 'advanced', 'weekly_base_km' => 62, 'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'], 'last_race_distance' => 'half', 'last_race_time' => '01:27:10', 'last_race_date' => '2025-12-01', 'health_program' => null, 'easy_pace_sec' => 320]],
        ['race_half_masters_4d', ['goal_type' => 'race', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 14, 6), 'race_target_time' => '01:58:00', 'gender' => 'male', 'birth_year' => 1968, 'height_cm' => 172, 'weight_kg' => 70, 'experience_level' => 'intermediate', 'weekly_base_km' => 30, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'health_notes' => 'Возраст 55+, беречь восстановление', 'last_race_distance' => '10k', 'last_race_time' => '00:52:20', 'last_race_date' => '2026-01-28', 'health_program' => null, 'easy_pace_sec' => 385]],
        ['race_marathon_first_20w', ['goal_type' => 'race', 'race_distance' => 'marathon', 'race_date' => liveBatchDateAddWeeks($startDate, 20, 6), 'race_target_time' => '04:20:00', 'gender' => 'female', 'birth_year' => 1989, 'height_cm' => 168, 'weight_kg' => 61, 'experience_level' => 'intermediate', 'weekly_base_km' => 34, 'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'], 'is_first_race_at_distance' => 1, 'last_race_distance' => 'half', 'last_race_time' => '02:02:00', 'last_race_date' => '2025-11-02', 'health_program' => null, 'easy_pace_sec' => 385]],
        ['race_marathon_advanced_24w', ['goal_type' => 'race', 'race_distance' => 'marathon', 'race_date' => liveBatchDateAddWeeks($startDate, 24, 6), 'race_target_time' => '03:05:00', 'gender' => 'male', 'birth_year' => 1988, 'height_cm' => 179, 'weight_kg' => 67, 'experience_level' => 'advanced', 'weekly_base_km' => 72, 'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'last_race_distance' => 'marathon', 'last_race_time' => '03:12:30', 'last_race_date' => '2025-10-19', 'health_program' => null, 'easy_pace_sec' => 315]],
        ['race_marathon_low_base_risky', ['goal_type' => 'race', 'race_distance' => 'marathon', 'race_date' => liveBatchDateAddWeeks($startDate, 14, 6), 'race_target_time' => '04:45:00', 'gender' => 'male', 'birth_year' => 1979, 'height_cm' => 182, 'weight_kg' => 84, 'experience_level' => 'beginner', 'weekly_base_km' => 20, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'is_first_race_at_distance' => 1, 'health_notes' => 'Первый марафон, база низкая', 'health_program' => null, 'easy_pace_sec' => 420]],
        ['race_marathon_short_runway', ['goal_type' => 'race', 'race_distance' => 'marathon', 'race_date' => liveBatchDateAddWeeks($startDate, 7, 6), 'race_target_time' => '04:00:00', 'gender' => 'female', 'birth_year' => 1984, 'height_cm' => 170, 'weight_kg' => 58, 'experience_level' => 'advanced', 'weekly_base_km' => 58, 'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'], 'last_race_distance' => 'half', 'last_race_time' => '01:48:40', 'last_race_date' => '2026-02-01', 'health_program' => null, 'easy_pace_sec' => 350]],
        ['race_trail_like_10k_hills', ['goal_type' => 'race', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 9, 6), 'race_target_time' => '00:49:00', 'gender' => 'male', 'birth_year' => 1992, 'height_cm' => 177, 'weight_kg' => 75, 'experience_level' => 'intermediate', 'weekly_base_km' => 36, 'preferred_days' => ['tue', 'thu', 'sat', 'sun'], 'health_notes' => 'Забег с набором высоты, часть тренировок по холмам', 'last_race_distance' => '10k', 'last_race_time' => '00:50:30', 'last_race_date' => '2026-03-01', 'health_program' => null, 'easy_pace_sec' => 370]],
        ['race_half_weekend_only', ['goal_type' => 'race', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 12, 6), 'race_target_time' => '02:05:00', 'gender' => 'female', 'birth_year' => 1980, 'height_cm' => 166, 'weight_kg' => 70, 'experience_level' => 'beginner', 'weekly_base_km' => 18, 'preferred_days' => ['fri', 'sat', 'sun'], 'is_first_race_at_distance' => 1, 'health_program' => null, 'easy_pace_sec' => 420]],
        ['race_5k_two_days', ['goal_type' => 'race', 'race_distance' => '5k', 'race_date' => liveBatchDateAddWeeks($startDate, 8, 6), 'race_target_time' => '00:29:00', 'gender' => 'female', 'birth_year' => 2001, 'height_cm' => 158, 'weight_kg' => 52, 'experience_level' => 'novice', 'weekly_base_km' => 6, 'preferred_days' => ['wed', 'sun'], 'is_first_race_at_distance' => 1, 'health_program' => null, 'easy_pace_sec' => 445]],
        ['race_10k_six_days_fast', ['goal_type' => 'race', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 10, 6), 'race_target_time' => '00:39:30', 'gender' => 'male', 'birth_year' => 1996, 'height_cm' => 183, 'weight_kg' => 69, 'experience_level' => 'advanced', 'weekly_base_km' => 58, 'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'], 'last_race_distance' => '10k', 'last_race_time' => '00:40:45', 'last_race_date' => '2026-03-15', 'health_program' => null, 'easy_pace_sec' => 325]],

        ['time_5k_sub20', ['goal_type' => 'time_improvement', 'race_distance' => '5k', 'race_date' => liveBatchDateAddWeeks($startDate, 8, 6), 'race_target_time' => '00:19:59', 'gender' => 'male', 'birth_year' => 1995, 'height_cm' => 176, 'weight_kg' => 66, 'experience_level' => 'advanced', 'weekly_base_km' => 44, 'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'], 'last_race_distance' => '5k', 'last_race_time' => '00:20:45', 'last_race_date' => '2026-03-01', 'health_program' => null, 'easy_pace_sec' => 335]],
        ['time_5k_beginner_pr', ['goal_type' => 'time_improvement', 'race_distance' => '5k', 'race_date' => liveBatchDateAddWeeks($startDate, 10, 6), 'race_target_time' => '00:27:30', 'gender' => 'female', 'birth_year' => 1993, 'height_cm' => 163, 'weight_kg' => 59, 'experience_level' => 'beginner', 'weekly_base_km' => 16, 'preferred_days' => ['mon', 'wed', 'sat'], 'last_race_distance' => '5k', 'last_race_time' => '00:30:10', 'last_race_date' => '2026-02-21', 'health_program' => null, 'easy_pace_sec' => 405]],
        ['time_10k_sub45', ['goal_type' => 'time_improvement', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 12, 6), 'race_target_time' => '00:44:30', 'gender' => 'male', 'birth_year' => 1982, 'height_cm' => 178, 'weight_kg' => 72, 'experience_level' => 'intermediate', 'weekly_base_km' => 36, 'preferred_days' => ['mon', 'wed', 'fri', 'sat', 'sun'], 'last_race_distance' => '10k', 'last_race_time' => '00:46:50', 'last_race_date' => '2026-01-12', 'health_program' => null, 'easy_pace_sec' => 360]],
        ['time_10k_return_break', ['goal_type' => 'time_improvement', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 10, 6), 'race_target_time' => '00:49:00', 'gender' => 'female', 'birth_year' => 1987, 'height_cm' => 171, 'weight_kg' => 65, 'experience_level' => 'intermediate', 'weekly_base_km' => 18, 'preferred_days' => ['tue', 'thu', 'sat', 'sun'], 'health_notes' => 'Перерыв 6 недель зимой, возвращается аккуратно', 'last_race_distance' => '10k', 'last_race_time' => '00:48:20', 'last_race_date' => '2025-07-05', 'health_program' => null, 'easy_pace_sec' => 410]],
        ['time_half_sub145', ['goal_type' => 'time_improvement', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 14, 6), 'race_target_time' => '01:45:00', 'gender' => 'female', 'birth_year' => 1990, 'height_cm' => 168, 'weight_kg' => 57, 'experience_level' => 'advanced', 'weekly_base_km' => 54, 'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'], 'last_race_distance' => 'half', 'last_race_time' => '01:48:20', 'last_race_date' => '2025-11-23', 'health_program' => null, 'easy_pace_sec' => 345]],
        ['time_half_sub2', ['goal_type' => 'time_improvement', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 12, 6), 'race_target_time' => '01:59:00', 'gender' => 'male', 'birth_year' => 1974, 'height_cm' => 174, 'weight_kg' => 79, 'experience_level' => 'intermediate', 'weekly_base_km' => 28, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'last_race_distance' => 'half', 'last_race_time' => '02:06:10', 'last_race_date' => '2025-10-12', 'health_program' => null, 'easy_pace_sec' => 395]],
        ['time_marathon_bq_like', ['goal_type' => 'time_improvement', 'race_distance' => 'marathon', 'race_date' => liveBatchDateAddWeeks($startDate, 22, 6), 'race_target_time' => '03:20:00', 'gender' => 'male', 'birth_year' => 1981, 'height_cm' => 180, 'weight_kg' => 68, 'experience_level' => 'advanced', 'weekly_base_km' => 68, 'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'], 'last_race_distance' => 'marathon', 'last_race_time' => '03:28:00', 'last_race_date' => '2025-09-28', 'health_program' => null, 'easy_pace_sec' => 325]],
        ['time_marathon_realistic', ['goal_type' => 'time_improvement', 'race_distance' => 'marathon', 'race_date' => liveBatchDateAddWeeks($startDate, 20, 6), 'race_target_time' => '03:55:00', 'gender' => 'female', 'birth_year' => 1983, 'height_cm' => 169, 'weight_kg' => 62, 'experience_level' => 'intermediate', 'weekly_base_km' => 45, 'preferred_days' => ['mon', 'wed', 'thu', 'sat', 'sun'], 'last_race_distance' => 'marathon', 'last_race_time' => '04:05:10', 'last_race_date' => '2025-10-05', 'health_program' => null, 'easy_pace_sec' => 365]],
        ['time_10k_aggressive_lowbase', ['goal_type' => 'time_improvement', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 8, 6), 'race_target_time' => '00:42:00', 'gender' => 'male', 'birth_year' => 1998, 'height_cm' => 175, 'weight_kg' => 71, 'experience_level' => 'beginner', 'weekly_base_km' => 14, 'preferred_days' => ['mon', 'wed', 'sat'], 'last_race_distance' => '10k', 'last_race_time' => '00:55:00', 'last_race_date' => '2026-02-18', 'health_program' => null, 'easy_pace_sec' => 420]],
        ['time_5k_masters', ['goal_type' => 'time_improvement', 'race_distance' => '5k', 'race_date' => liveBatchDateAddWeeks($startDate, 9, 6), 'race_target_time' => '00:23:00', 'gender' => 'female', 'birth_year' => 1969, 'height_cm' => 164, 'weight_kg' => 58, 'experience_level' => 'intermediate', 'weekly_base_km' => 24, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'health_notes' => 'Возраст 55+, нужна хорошая разминка и восстановление', 'last_race_distance' => '5k', 'last_race_time' => '00:24:10', 'last_race_date' => '2026-02-01', 'health_program' => null, 'easy_pace_sec' => 385]],

        ['edge_two_day_half', ['goal_type' => 'race', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 16, 6), 'race_target_time' => '02:20:00', 'gender' => 'male', 'birth_year' => 1991, 'height_cm' => 184, 'weight_kg' => 88, 'experience_level' => 'beginner', 'weekly_base_km' => 12, 'preferred_days' => ['wed', 'sun'], 'is_first_race_at_distance' => 1, 'health_program' => null, 'easy_pace_sec' => 435]],
        ['edge_seven_day_no_rest_10k', ['goal_type' => 'race', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 12, 6), 'race_target_time' => '00:43:00', 'gender' => 'male', 'birth_year' => 1990, 'height_cm' => 178, 'weight_kg' => 69, 'experience_level' => 'advanced', 'weekly_base_km' => 55, 'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'last_race_distance' => '10k', 'last_race_time' => '00:44:20', 'last_race_date' => '2026-02-09', 'health_program' => null, 'easy_pace_sec' => 335]],
        ['edge_no_weekend_5d', ['goal_type' => 'race', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 15, 6), 'race_target_time' => '01:52:00', 'gender' => 'female', 'birth_year' => 1988, 'height_cm' => 167, 'weight_kg' => 60, 'experience_level' => 'intermediate', 'weekly_base_km' => 34, 'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'last_race_distance' => '10k', 'last_race_time' => '00:50:00', 'last_race_date' => '2026-03-08', 'health_program' => null, 'easy_pace_sec' => 375]],
        ['edge_late_race_sunday', ['goal_type' => 'race', 'race_distance' => '5k', 'race_date' => liveBatchDateAddWeeks($startDate, 4, 6), 'race_target_time' => '00:31:00', 'gender' => 'female', 'birth_year' => 1976, 'height_cm' => 159, 'weight_kg' => 67, 'experience_level' => 'novice', 'weekly_base_km' => 7, 'preferred_days' => ['mon', 'thu', 'sun'], 'is_first_race_at_distance' => 1, 'health_program' => null, 'easy_pace_sec' => 455]],
        ['edge_high_weight_half', ['goal_type' => 'race', 'race_distance' => 'half', 'race_date' => liveBatchDateAddWeeks($startDate, 18, 6), 'race_target_time' => '02:30:00', 'gender' => 'male', 'birth_year' => 1985, 'height_cm' => 179, 'weight_kg' => 105, 'experience_level' => 'beginner', 'weekly_base_km' => 18, 'preferred_days' => ['mon', 'wed', 'fri', 'sun'], 'is_first_race_at_distance' => 1, 'health_notes' => 'Высокий вес, нужна защита суставов', 'health_program' => null, 'easy_pace_sec' => 450]],
        ['edge_very_fast_female_10k', ['goal_type' => 'time_improvement', 'race_distance' => '10k', 'race_date' => liveBatchDateAddWeeks($startDate, 10, 6), 'race_target_time' => '00:38:30', 'gender' => 'female', 'birth_year' => 1997, 'height_cm' => 169, 'weight_kg' => 53, 'experience_level' => 'advanced', 'weekly_base_km' => 60, 'preferred_days' => ['mon', 'tue', 'thu', 'fri', 'sun'], 'last_race_distance' => '10k', 'last_race_time' => '00:39:40', 'last_race_date' => '2026-03-10', 'health_program' => null, 'easy_pace_sec' => 320]],
        ['edge_health_custom_4w', ['goal_type' => 'health', 'gender' => 'male', 'birth_year' => 2000, 'height_cm' => 181, 'weight_kg' => 76, 'experience_level' => 'beginner', 'weekly_base_km' => 12, 'preferred_days' => ['mon', 'wed', 'fri'], 'health_program' => 'custom', 'health_plan_weeks' => 4, 'current_running_level' => 'basic', 'running_experience' => '3_6m', 'easy_pace_sec' => 420]],
        ['edge_weight_loss_6w', ['goal_type' => 'weight_loss', 'gender' => 'female', 'birth_year' => 1992, 'height_cm' => 175, 'weight_kg' => 97, 'weight_goal_kg' => 92, 'weight_goal_date' => liveBatchDateAddWeeks($startDate, 6), 'experience_level' => 'novice', 'weekly_base_km' => 5, 'preferred_days' => ['tue', 'sat'], 'health_notes' => 'Короткий срок, высокий вес, только мягкая нагрузка', 'health_program' => null, 'easy_pace_sec' => 480]],
        ['edge_marathon_older_5d', ['goal_type' => 'race', 'race_distance' => 'marathon', 'race_date' => liveBatchDateAddWeeks($startDate, 22, 6), 'race_target_time' => '04:10:00', 'gender' => 'male', 'birth_year' => 1965, 'height_cm' => 171, 'weight_kg' => 68, 'experience_level' => 'advanced', 'weekly_base_km' => 52, 'preferred_days' => ['mon', 'wed', 'thu', 'sat', 'sun'], 'health_notes' => 'Возраст 60+, марафонский опыт есть', 'last_race_distance' => 'marathon', 'last_race_time' => '04:18:00', 'last_race_date' => '2025-10-26', 'health_program' => null, 'easy_pace_sec' => 360]],
        ['edge_health_stress_sleep', ['goal_type' => 'health', 'gender' => 'female', 'birth_year' => 1981, 'height_cm' => 163, 'weight_kg' => 62, 'experience_level' => 'intermediate', 'weekly_base_km' => 20, 'preferred_days' => ['mon', 'wed', 'sat'], 'health_program' => 'regular_running', 'health_plan_weeks' => 8, 'health_notes' => 'Много стресса и плохой сон, нужен запас восстановления', 'easy_pace_sec' => 400]],
    ];

    $profiles = [];
    foreach ($cases as $index => [$code, $overrides]) {
        $number = $index + 1;
        $username = liveBatchUsername($prefix, $number, $code);
        $profile = liveBatchBaseProfile($startDate, array_merge($overrides, [
            'username' => $username,
            'email' => $username . '@planrun-live.test',
        ]));
        $profile['_case_code'] = $code;
        $profiles[] = $profile;
    }

    return $profiles;
}

function liveBatchFetchUserByUsername(mysqli $db, string $username): ?array
{
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function liveBatchRegisterOrReuse(mysqli $db, RegistrationService $registration, array $profile, bool $reuseExisting): array
{
    $existing = liveBatchFetchUserByUsername($db, (string) $profile['username']);
    if ($existing && $reuseExisting) {
        return ['id' => (int) $existing['id'], 'created' => false, 'row' => $existing];
    }
    if ($existing) {
        throw new RuntimeException('User already exists: ' . $profile['username']);
    }

    $data = $profile;
    unset($data['_case_code']);
    $result = $registration->registerFull($data);
    if (empty($result['success']) || empty($result['user']['id'])) {
        throw new RuntimeException('Registration failed for ' . $profile['username'] . ': ' . ($result['error'] ?? 'unknown'));
    }

    return ['id' => (int) $result['user']['id'], 'created' => true, 'row' => liveBatchFetchUserByUsername($db, (string) $profile['username'])];
}

function liveBatchRunGeneration(mysqli $db, PlanGenerationQueueService $queue, PlanGenerationProcessorService $processor, int $userId): array
{
    $job = null;
    try {
        $job = $queue->findLatestActiveJobForUser($userId);
    } catch (Throwable) {
        $job = null;
    }

    $jobType = 'generate';
    $payload = [];
    if ($job) {
        $jobType = (string) ($job['job_type'] ?? 'generate');
        if (!empty($job['payload_json'])) {
            $payload = json_decode((string) $job['payload_json'], true) ?: [];
        }
    }

    try {
        $started = microtime(true);
        $result = $processor->process($userId, $jobType, $payload);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        if ($job) {
            $queue->markCompleted((int) $job['id'], $result);
        }
        return [
            'ok' => true,
            'job_id' => $job['id'] ?? null,
            'job_type' => $jobType,
            'duration_ms' => $durationMs,
            'result' => $result,
        ];
    } catch (Throwable $e) {
        $processor->persistFailure($userId, 'Ошибка генерации плана: ' . $e->getMessage());
        if ($job) {
            $maxAttempts = (int) ($job['max_attempts'] ?? 3);
            $queue->markFailed((int) $job['id'], 'Ошибка генерации плана: ' . $e->getMessage(), $maxAttempts, $maxAttempts);
        }
        return [
            'ok' => false,
            'job_id' => $job['id'] ?? null,
            'job_type' => $jobType,
            'error' => $e->getMessage(),
        ];
    }
}

function liveBatchFetchSavedPlan(mysqli $db, int $userId): array
{
    $weeksStmt = $db->prepare(
        'SELECT id, week_number, start_date, total_volume
         FROM training_plan_weeks
         WHERE user_id = ?
         ORDER BY week_number ASC, start_date ASC'
    );
    if (!$weeksStmt) {
        throw new RuntimeException('Prepare weeks failed: ' . $db->error);
    }
    $weeksStmt->bind_param('i', $userId);
    $weeksStmt->execute();
    $weekRows = $weeksStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $weeksStmt->close();

    $weeks = [];
    foreach ($weekRows as $week) {
        $weekId = (int) $week['id'];
        $weeks[$weekId] = [
            'id' => $weekId,
            'week_number' => (int) $week['week_number'],
            'start_date' => (string) $week['start_date'],
            'total_volume' => round((float) $week['total_volume'], 1),
            'days' => [],
        ];
    }

    if ($weeks === []) {
        return [];
    }

    $dayStmt = $db->prepare(
        "SELECT d.id,
                d.week_id,
                d.day_of_week,
                d.date,
                d.type,
                d.description,
                d.is_key_workout,
                COALESCE(SUM(CASE WHEN e.category = 'run' THEN e.distance_m ELSE 0 END), 0) AS distance_m,
                COALESCE(SUM(CASE WHEN e.category = 'run' THEN e.duration_sec ELSE 0 END), 0) AS duration_sec,
                MAX(CASE WHEN e.category = 'run' THEN e.pace END) AS pace
         FROM training_plan_days d
         LEFT JOIN training_day_exercises e ON e.plan_day_id = d.id
         WHERE d.user_id = ?
         GROUP BY d.id, d.week_id, d.day_of_week, d.date, d.type, d.description, d.is_key_workout
         ORDER BY d.date ASC, d.day_of_week ASC"
    );
    if (!$dayStmt) {
        throw new RuntimeException('Prepare days failed: ' . $db->error);
    }
    $dayStmt->bind_param('i', $userId);
    $dayStmt->execute();
    $dayRows = $dayStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dayStmt->close();

    foreach ($dayRows as $day) {
        $weekId = (int) $day['week_id'];
        if (!isset($weeks[$weekId])) {
            continue;
        }
        $weeks[$weekId]['days'][] = [
            'id' => (int) $day['id'],
            'day_of_week' => (int) $day['day_of_week'],
            'date' => (string) $day['date'],
            'type' => (string) $day['type'],
            'description' => trim((string) $day['description']),
            'is_key_workout' => !empty($day['is_key_workout']),
            'distance_km' => round(((int) $day['distance_m']) / 1000, 1),
            'duration_sec' => (int) $day['duration_sec'],
            'pace' => $day['pace'] !== null ? (string) $day['pace'] : null,
        ];
    }

    return array_values($weeks);
}

function liveBatchIssue(string $severity, string $code, string $message, array $context = []): array
{
    return [
        'severity' => $severity,
        'code' => $code,
        'message' => $message,
        'context' => $context,
    ];
}

function liveBatchDecodeDays(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map('strval', $raw)));
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
}

function liveBatchDayCodeToNumber(string $code): ?int
{
    $map = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    return $map[$code] ?? null;
}

function liveBatchDistanceKm(?string $distance): float
{
    $value = trim((string) $distance);
    return match ($value) {
        '5k' => 5.0,
        '10k' => 10.0,
        'half', '21.1k' => 21.1,
        'marathon', '42.2k' => 42.2,
        default => is_numeric($value) ? (float) $value : 0.0,
    };
}

function liveBatchExpectedWeeks(array $user): ?int
{
    $start = !empty($user['training_start_date']) ? strtotime((string) $user['training_start_date']) : false;
    if (!$start) {
        return null;
    }

    $goal = (string) ($user['goal_type'] ?? '');
    if ($goal === 'health') {
        if (!empty($user['health_plan_weeks'])) {
            return (int) $user['health_plan_weeks'];
        }
        if (($user['health_program'] ?? '') === 'start_running') {
            return 8;
        }
        if (($user['health_program'] ?? '') === 'couch_to_5k') {
            return 10;
        }
        return 12;
    }

    $end = $user['weight_goal_date'] ?? $user['race_date'] ?? $user['target_marathon_date'] ?? null;
    if (!empty($end) && strtotime((string) $end) > $start) {
        return (int) ceil((strtotime((string) $end) - $start) / (7 * 86400));
    }

    return $goal === 'weight_loss' ? 12 : null;
}

function liveBatchEvaluatePlan(array $user, array $weeks, array $generation = []): array
{
    $issues = [];
    $goal = (string) ($user['goal_type'] ?? '');
    $raceDistanceKm = liveBatchDistanceKm($user['race_distance'] ?? null);
    $experience = mb_strtolower((string) ($user['experience_level'] ?? ''), 'UTF-8');
    $isNovice = in_array($experience, ['novice', 'beginner'], true);
    $weeklyBase = (float) ($user['weekly_base_km'] ?? 0.0);
    $sessions = max(1, (int) ($user['sessions_per_week'] ?? 3));
    $preferredDayNums = array_values(array_filter(array_map('liveBatchDayCodeToNumber', liveBatchDecodeDays($user['preferred_days'] ?? null))));
    $runTypes = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'race', 'walking'];
    $qualityTypes = ['tempo', 'interval', 'fartlek', 'control'];
    $hardTypes = ['long', 'tempo', 'interval', 'fartlek', 'control', 'race'];
    $notes = mb_strtolower((string) ($user['health_notes'] ?? ''), 'UTF-8');
    $isConservative = str_contains($notes, 'травм')
        || str_contains($notes, 'ахилл')
        || str_contains($notes, 'колен')
        || str_contains($notes, 'беремен')
        || str_contains($notes, 'послерод')
        || str_contains($notes, 'стресс')
        || str_contains($notes, 'сон')
        || (int) ($user['birth_year'] ?? 3000) <= 1966;

    if ($weeks === []) {
        return [
            'issues' => [liveBatchIssue('error', 'empty_plan', 'План не сохранил ни одной недели.')],
            'summary' => ['weeks' => 0, 'max_volume' => 0, 'peak_long_km' => 0],
        ];
    }

    $expectedWeeks = liveBatchExpectedWeeks($user);
    if ($expectedWeeks !== null && abs(count($weeks) - $expectedWeeks) > 1) {
        $issues[] = liveBatchIssue('warning', 'weeks_count_mismatch', "Длина плана " . count($weeks) . " нед., ожидаемо около {$expectedWeeks}.", [
            'actual' => count($weeks),
            'expected' => $expectedWeeks,
        ]);
    }

    $firstStart = (string) ($weeks[0]['start_date'] ?? '');
    if ($firstStart !== '' && (int) (new DateTimeImmutable($firstStart))->format('N') !== 1) {
        $issues[] = liveBatchIssue('error', 'start_not_monday', "План начинается не с понедельника: {$firstStart}.");
    }

    $volumes = [];
    $recoveryByWeek = [];
    $peakLong = 0.0;
    $maxVolume = 0.0;
    $raceDay = null;
    $allHardDates = [];

    foreach ($weeks as $weekIndex => $week) {
        $weekNumber = (int) ($week['week_number'] ?? ($weekIndex + 1));
        $days = (array) ($week['days'] ?? []);
        $volume = (float) ($week['total_volume'] ?? 0.0);
        $volumes[$weekNumber] = $volume;
        $recoveryByWeek[$weekNumber] = !empty($week['is_recovery']);
        $maxVolume = max($maxVolume, $volume);

        $runDays = 0;
        $qualityDays = 0;
        $longDays = [];
        $hardDays = [];
        foreach ($days as $day) {
            $type = (string) ($day['type'] ?? 'rest');
            $dow = (int) ($day['day_of_week'] ?? 0);
            $date = (string) ($day['date'] ?? '');
            $distance = (float) ($day['distance_km'] ?? 0.0);
            $isRun = in_array($type, $runTypes, true) && $type !== 'rest';
            if ($isRun) {
                $runDays++;
                if ($preferredDayNums !== [] && $type !== 'race' && !in_array($dow, $preferredDayNums, true)) {
                    $issues[] = liveBatchIssue('error', 'run_outside_preferred_days', "Бег {$type} стоит вне preferred_days: {$date}.", [
                        'week' => $weekNumber,
                        'date' => $date,
                        'day_of_week' => $dow,
                    ]);
                }
            }
            if (in_array($type, $qualityTypes, true)) {
                $qualityDays++;
                if ($isNovice && $weekNumber <= 2 && in_array($type, ['tempo', 'interval'], true)) {
                    $issues[] = liveBatchIssue('warning', 'novice_quality_too_early', "У новичка интенсивная тренировка {$type} уже на {$weekNumber}-й неделе.", [
                        'week' => $weekNumber,
                        'date' => $date,
                    ]);
                }
                if ($isConservative && in_array($type, ['interval', 'tempo'], true) && $weekNumber <= 3) {
                    $issues[] = liveBatchIssue('warning', 'conservative_quality_too_early', "При ограничениях по здоровью {$type} слишком рано: {$date}.", [
                        'week' => $weekNumber,
                        'date' => $date,
                    ]);
                }
                if ($type !== 'fartlek' && !liveBatchHasExplicitQualityPace($day)) {
                    $issues[] = liveBatchIssue('info', 'quality_without_pace', "Ключевая {$type} без явного темпа: {$date}.", [
                        'week' => $weekNumber,
                        'date' => $date,
                    ]);
                }
            }
            if ($type === 'long') {
                $longDays[] = $day;
                $peakLong = max($peakLong, $distance);
                $share = $volume > 0 ? $distance / $volume : 0.0;
                [$limit, $errorLimit] = liveBatchLongShareLimits($sessions, $isNovice, $isConservative, $raceDistanceKm);
                if ($share > $limit && $distance > 0 && liveBatchIsLongShareMaterial($distance, $volume, $sessions)) {
                    $severity = $share > $errorLimit ? 'error' : 'warning';
                    $issues[] = liveBatchIssue($severity, 'long_run_share_high', "Длительная {$distance} км занимает " . round($share * 100) . "% недельного объёма.", [
                        'week' => $weekNumber,
                        'date' => $date,
                        'share' => round($share, 2),
                    ]);
                }
            }
            if ($type === 'race') {
                $raceDay = $day;
            }
            if (in_array($type, $hardTypes, true)) {
                $hardDays[] = $day;
                $allHardDates[] = $day;
            }
        }

        if (count($longDays) > 1) {
            $issues[] = liveBatchIssue('error', 'multiple_long_runs_week', "В неделе {$weekNumber} больше одной длительной.", [
                'week' => $weekNumber,
            ]);
        }
        if ($qualityDays > 2 || ($isNovice && $qualityDays > 1) || ($isConservative && $qualityDays > 1)) {
            $issues[] = liveBatchIssue('warning', 'too_many_quality_days', "Слишком много интенсивных дней в неделе {$weekNumber}: {$qualityDays}.", [
                'week' => $weekNumber,
                'quality_days' => $qualityDays,
            ]);
        }
        if ($runDays > $sessions + 1) {
            $issues[] = liveBatchIssue('error', 'too_many_run_days', "Беговых дней больше заявленного режима: {$runDays} при {$sessions}/нед.", [
                'week' => $weekNumber,
            ]);
        }
        if ($runDays < max(1, $sessions - 2) && !in_array($goal, ['health'], true) && $weekNumber < count($weeks)) {
            $issues[] = liveBatchIssue('info', 'few_run_days', "Беговых дней заметно меньше режима: {$runDays} при {$sessions}/нед.", [
                'week' => $weekNumber,
            ]);
        }

        usort($hardDays, static fn(array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));
        for ($i = 1; $i < count($hardDays); $i++) {
            $prev = new DateTimeImmutable((string) $hardDays[$i - 1]['date']);
            $cur = new DateTimeImmutable((string) $hardDays[$i]['date']);
            $diff = (int) $prev->diff($cur)->format('%a');
            if ($diff <= 1) {
                $issues[] = liveBatchIssue('warning', 'hard_days_adjacent', "Ключевые нагрузки стоят слишком близко: {$hardDays[$i - 1]['date']} и {$hardDays[$i]['date']}.", [
                    'week' => $weekNumber,
                    'prev_type' => $hardDays[$i - 1]['type'] ?? '',
                    'type' => $hardDays[$i]['type'] ?? '',
                ]);
            }
        }
    }

    $firstVolume = (float) ($volumes[1] ?? $weeks[0]['total_volume'] ?? 0.0);
    if ($weeklyBase > 0) {
        $startCap = $weeklyBase * ($isNovice || $isConservative ? 1.10 : 1.20) + 2.0;
        if ($firstVolume > $startCap) {
            $issues[] = liveBatchIssue('warning', 'first_week_above_base', "Первая неделя {$firstVolume} км выше базы {$weeklyBase} км/нед.", [
                'first_week_km' => $firstVolume,
                'weekly_base_km' => $weeklyBase,
            ]);
        }
    } elseif ($firstVolume > 10.0 && ($user['health_program'] ?? '') !== 'regular_running') {
        $issues[] = liveBatchIssue('warning', 'zero_base_starts_too_high', "Пользователь с нулевой базой начинает с {$firstVolume} км.");
    }

    $prevVolume = null;
    $recoveryByWeek = array_replace($recoveryByWeek, liveBatchInferRecoveryWeeks($volumes));
    $prevWasRecovery = false;
    $lastNormalVolume = null;
    $growthStreak = 0;
    foreach ($volumes as $weekNumber => $volume) {
        if ($prevVolume !== null && $prevVolume > 0.0 && $volume > 0.0) {
            $referenceVolume = ($prevWasRecovery && $lastNormalVolume !== null && $lastNormalVolume > 0.0)
                ? $lastNormalVolume
                : $prevVolume;
            $ratio = $volume / $referenceVolume;
            $limit = ($isNovice || $isConservative) ? 1.12 : 1.18;
            $deltaKm = $volume - $referenceVolume;
            if ($ratio > $limit && liveBatchIsGrowthMaterial($referenceVolume, $deltaKm, $isNovice || $isConservative)) {
                $issues[] = liveBatchIssue('warning', 'weekly_growth_high', "Рост объёма с недели " . ($weekNumber - 1) . " на {$weekNumber}: +" . round(($ratio - 1) * 100) . '%.', [
                    'week' => $weekNumber,
                    'ratio' => round($ratio, 2),
                    'delta_km' => round($deltaKm, 1),
                ]);
            }
            if ($ratio > 1.03) {
                $growthStreak++;
                if ($growthStreak >= 4) {
                    $issues[] = liveBatchIssue('info', 'long_growth_without_cutback', "Четыре недели подряд идёт рост объёма без явной разгрузки.", [
                        'week' => $weekNumber,
                    ]);
                }
            } else {
                $growthStreak = 0;
            }
        }
        if (empty($recoveryByWeek[$weekNumber])) {
            $lastNormalVolume = $volume;
        }
        $prevWasRecovery = !empty($recoveryByWeek[$weekNumber]);
        $prevVolume = $volume;
    }

    if (in_array($goal, ['race', 'time_improvement'], true)) {
        $raceDate = (string) ($user['race_date'] ?? '');
        if ($raceDate !== '') {
            if (!$raceDay) {
                $issues[] = liveBatchIssue('error', 'missing_race_day', "В плане нет race-дня для целевой гонки {$raceDate}.");
            } elseif ((string) ($raceDay['date'] ?? '') !== $raceDate) {
                $issues[] = liveBatchIssue('error', 'race_day_wrong_date', "Race-день стоит {$raceDay['date']} вместо {$raceDate}.", [
                    'actual' => $raceDay['date'] ?? null,
                    'expected' => $raceDate,
                ]);
            }

            foreach ($allHardDates as $hardDay) {
                if (($hardDay['type'] ?? '') === 'race') {
                    continue;
                }
                $hardDate = new DateTimeImmutable((string) ($hardDay['date'] ?? ''));
                $target = new DateTimeImmutable($raceDate);
                $daysBefore = (int) $hardDate->diff($target)->format('%r%a');
                if ($daysBefore > 0 && $daysBefore <= 2) {
                    $issues[] = liveBatchIssue('error', 'hard_workout_too_close_to_race', "Ключевая {$hardDay['type']} стоит за {$daysBefore} дн. до гонки.", [
                        'date' => $hardDay['date'] ?? null,
                        'race_date' => $raceDate,
                    ]);
                }
            }
        }

        if ($raceDistanceKm > 0.0) {
            $minPeak = match (true) {
                $raceDistanceKm <= 5.1 => 5.0,
                $raceDistanceKm <= 10.1 => 9.0,
                $raceDistanceKm <= 21.2 => count($weeks) <= 8 ? 13.0 : 16.0,
                default => count($weeks) <= 10 ? 20.0 : 26.0,
            };
            $maxPeak = match (true) {
                $raceDistanceKm <= 5.1 => 14.0,
                $raceDistanceKm <= 10.1 => 20.0,
                $raceDistanceKm <= 21.2 => 25.0,
                default => 35.0,
            };
            if ($peakLong > 0.0 && $peakLong < $minPeak && count($weeks) >= 6) {
                $issues[] = liveBatchIssue('warning', 'peak_long_too_short', "Пиковая длительная {$peakLong} км коротковата для дистанции {$user['race_distance']}.", [
                    'peak_long_km' => $peakLong,
                    'min_expected' => $minPeak,
                ]);
            }
            if ($peakLong > $maxPeak) {
                $issues[] = liveBatchIssue('warning', 'peak_long_too_long', "Пиковая длительная {$peakLong} км слишком велика для дистанции {$user['race_distance']}.", [
                    'peak_long_km' => $peakLong,
                    'max_expected' => $maxPeak,
                ]);
            }
        }
    }

    if ($goal === 'health' && in_array(($user['health_program'] ?? ''), ['start_running', 'couch_to_5k'], true)) {
        foreach (array_slice($weeks, 0, 4) as $week) {
            foreach ((array) ($week['days'] ?? []) as $day) {
                if (in_array((string) ($day['type'] ?? ''), ['tempo', 'interval', 'control', 'race'], true)) {
                    $issues[] = liveBatchIssue('error', 'start_program_intensity_too_early', "В стартовой программе слишком рано появилась {$day['type']}: {$day['date']}.", [
                        'week' => $week['week_number'] ?? null,
                    ]);
                }
            }
        }
    }

    $severityRank = ['error' => 3, 'warning' => 2, 'info' => 1];
    usort($issues, static function (array $a, array $b) use ($severityRank): int {
        $rank = ($severityRank[$b['severity'] ?? 'info'] ?? 0) <=> ($severityRank[$a['severity'] ?? 'info'] ?? 0);
        return $rank !== 0 ? $rank : strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
    });

    $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
    $codeCounts = [];
    foreach ($issues as $issue) {
        $severity = (string) ($issue['severity'] ?? 'info');
        $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        $code = (string) ($issue['code'] ?? 'unknown');
        $codeCounts[$code] = ($codeCounts[$code] ?? 0) + 1;
    }

    return [
        'issues' => $issues,
        'summary' => [
            'weeks' => count($weeks),
            'first_week_km' => round($firstVolume, 1),
            'max_volume_km' => round($maxVolume, 1),
            'peak_long_km' => round($peakLong, 1),
            'issue_counts' => $counts,
            'issue_code_counts' => $codeCounts,
            'generator' => $generation['result']['generation_metadata']['generator'] ?? null,
            'quality_gate' => $generation['result']['generation_metadata']['quality_gate'] ?? null,
        ],
    ];
}

function liveBatchHasExplicitQualityPace(array $day): bool
{
    if (!empty($day['pace'])) {
        return true;
    }

    $description = mb_strtolower((string) ($day['description'] ?? ''), 'UTF-8');
    if ($description === '') {
        return false;
    }

    return str_contains($description, 'темп')
        || str_contains($description, 'мин/км')
        || preg_match('/\b\d{1,2}:\d{2}\b/u', $description) === 1;
}

function liveBatchInferRecoveryWeeks(array $volumes): array
{
    ksort($volumes);
    $weekNumbers = array_values(array_keys($volumes));
    $inferred = [];

    foreach ($weekNumbers as $index => $weekNumber) {
        if ($index === 0 || !isset($weekNumbers[$index + 1])) {
            continue;
        }

        $previous = (float) ($volumes[$weekNumbers[$index - 1]] ?? 0.0);
        $current = (float) ($volumes[$weekNumber] ?? 0.0);
        $next = (float) ($volumes[$weekNumbers[$index + 1]] ?? 0.0);

        if ($previous <= 0.0 || $current <= 0.0 || $next <= 0.0) {
            continue;
        }

        if ($current <= $previous * 0.92 && $next >= $current * 1.08) {
            $inferred[(int) $weekNumber] = true;
        }
    }

    return $inferred;
}

function liveBatchLongShareLimits(int $sessions, bool $isNovice, bool $isConservative, float $raceDistanceKm = 0.0): array
{
    if ($sessions <= 2) {
        return [0.60, 0.72];
    }

    if ($raceDistanceKm >= 42.0) {
        return [$sessions <= 4 ? 0.52 : 0.50, 0.62];
    }

    if ($raceDistanceKm >= 21.0) {
        return [$sessions <= 4 ? 0.50 : 0.48, 0.60];
    }

    if ($sessions === 3) {
        return [($isNovice || $isConservative) ? 0.43 : 0.45, 0.60];
    }

    return [($isNovice || $isConservative) ? 0.40 : 0.45, 0.55];
}

function liveBatchIsLongShareMaterial(float $longDistanceKm, float $weekVolumeKm, int $sessions): bool
{
    if ($longDistanceKm <= 0.0 || $weekVolumeKm <= 0.0) {
        return false;
    }

    if ($weekVolumeKm <= 12.0 && $longDistanceKm <= 5.5) {
        return false;
    }

    if ($sessions <= 2 && $weekVolumeKm <= 16.0 && $longDistanceKm <= 8.0) {
        return false;
    }

    return true;
}

function liveBatchIsGrowthMaterial(float $referenceVolumeKm, float $deltaKm, bool $isCautious): bool
{
    if ($deltaKm <= 0.0) {
        return false;
    }

    $lowVolumeDeltaLimit = $isCautious ? 3.5 : 4.0;
    if ($referenceVolumeKm < 12.0 && $deltaKm <= $lowVolumeDeltaLimit) {
        return false;
    }

    if ($referenceVolumeKm < 20.0 && $deltaKm <= 2.0) {
        return false;
    }

    return true;
}

function liveBatchBuildMarkdown(array $report): string
{
    $lines = [];
    $lines[] = '# Live Plan Generation Batch';
    $lines[] = '';
    $lines[] = '- Generated at: ' . gmdate('Y-m-d H:i:s') . ' UTC';
    $lines[] = '- Prefix: `' . ($report['context']['prefix'] ?? '') . '`';
    $lines[] = '- Start date: `' . ($report['context']['start_date'] ?? '') . '`';
    $lines[] = '- Users: ' . count($report['users']);
    $lines[] = '- Live LLM path: ' . (!empty($report['context']['fast_llm_fallback']) ? 'fallback/algorithmic' : 'real configured LLM if available');
    $lines[] = '';

    $summary = $report['summary'];
    $lines[] = '## Summary';
    $lines[] = '';
    $lines[] = '- Created users: ' . $summary['created_users'];
    $lines[] = '- Reused users: ' . $summary['reused_users'];
    $lines[] = '- Generation ok: ' . $summary['generation_ok'];
    $lines[] = '- Generation failed: ' . $summary['generation_failed'];
    $lines[] = '- Trainer issues: errors=' . $summary['issue_counts']['error'] . ', warnings=' . $summary['issue_counts']['warning'] . ', info=' . $summary['issue_counts']['info'];
    $lines[] = '';

    $lines[] = '## Top Issue Codes';
    $lines[] = '';
    $lines[] = '| Code | Count |';
    $lines[] = '| --- | ---: |';
    foreach ($summary['top_issue_codes'] as $code => $count) {
        $lines[] = '| `' . $code . '` | ' . $count . ' |';
    }
    $lines[] = '';

    $lines[] = '## Users';
    $lines[] = '';
    $lines[] = '| # | User ID | Case | Goal | Weeks | First km | Peak long | E/W/I |';
    $lines[] = '| ---: | ---: | --- | --- | ---: | ---: | ---: | --- |';
    foreach ($report['users'] as $i => $item) {
        $eval = $item['evaluation']['summary'] ?? [];
        $counts = $eval['issue_counts'] ?? ['error' => 0, 'warning' => 0, 'info' => 0];
        $profile = $item['profile'];
        $lines[] = sprintf(
            '| %d | %d | `%s` | %s %s | %d | %.1f | %.1f | %d/%d/%d |',
            $i + 1,
            (int) $item['user_id'],
            (string) ($profile['_case_code'] ?? ''),
            (string) ($profile['goal_type'] ?? ''),
            (string) ($profile['race_distance'] ?? ''),
            (int) ($eval['weeks'] ?? 0),
            (float) ($eval['first_week_km'] ?? 0.0),
            (float) ($eval['peak_long_km'] ?? 0.0),
            (int) ($counts['error'] ?? 0),
            (int) ($counts['warning'] ?? 0),
            (int) ($counts['info'] ?? 0)
        );
    }
    $lines[] = '';

    $lines[] = '## Coach Review: Problems To Fix';
    $lines[] = '';
    foreach ($report['users'] as $item) {
        $issues = array_values(array_filter(
            (array) ($item['evaluation']['issues'] ?? []),
            static fn(array $issue): bool => in_array((string) ($issue['severity'] ?? ''), ['error', 'warning'], true)
        ));
        if ($issues === []) {
            continue;
        }
        $profile = $item['profile'];
        $lines[] = '### ' . $profile['_case_code'] . ' (`user_id=' . $item['user_id'] . '`)';
        $lines[] = '';
        $lines[] = '- Goal: ' . ($profile['goal_type'] ?? '') . ' ' . ($profile['race_distance'] ?? '') . ', base=' . ($profile['weekly_base_km'] ?? '') . ' km/w, sessions=' . ($profile['sessions_per_week'] ?? '');
        foreach (array_slice($issues, 0, 8) as $issue) {
            $lines[] = '- ' . strtoupper((string) $issue['severity']) . ' `' . $issue['code'] . '`: ' . $issue['message'];
        }
        if (count($issues) > 8) {
            $lines[] = '- ...and ' . (count($issues) - 8) . ' more.';
        }
        $lines[] = '';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

$args = liveBatchParseArgs($argv);
if (liveBatchBool($args['fast-llm-fallback'] ?? '0')) {
    $_ENV['LLM_CHAT_BASE_URL'] = 'http://127.0.0.1:1/v1';
    $_SERVER['LLM_CHAT_BASE_URL'] = 'http://127.0.0.1:1/v1';
    putenv('LLM_CHAT_BASE_URL=http://127.0.0.1:1/v1');
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$limit = max(1, min(50, (int) ($args['limit'] ?? 50)));
$prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) ($args['prefix'] ?? 'live50'));
$startDate = (string) ($args['start-date'] ?? liveBatchNextMonday(gmdate('Y-m-d')));
$saveDir = rtrim((string) ($args['save-dir'] ?? ($baseDir . '/tmp/live_plan_generation')), '/');
if (!is_dir($saveDir) && !mkdir($saveDir, 0775, true) && !is_dir($saveDir)) {
    fwrite(STDERR, "Cannot create save dir: {$saveDir}\n");
    exit(1);
}

$profiles = array_slice(liveBatchBuildProfiles($prefix, $startDate), 0, $limit);
$registration = new RegistrationService($db);
$queue = new PlanGenerationQueueService($db);
$processor = new PlanGenerationProcessorService($db);
$skipGeneration = liveBatchBool($args['skip-generation'] ?? '0');
$reuseExisting = liveBatchBool($args['reuse-existing'] ?? '1');

$report = [
    'context' => [
        'prefix' => $prefix,
        'start_date' => $startDate,
        'limit' => $limit,
        'skip_generation' => $skipGeneration,
        'reuse_existing' => $reuseExisting,
        'fast_llm_fallback' => liveBatchBool($args['fast-llm-fallback'] ?? '0'),
        'generated_at_utc' => gmdate('Y-m-d H:i:s'),
    ],
    'summary' => [
        'created_users' => 0,
        'reused_users' => 0,
        'generation_ok' => 0,
        'generation_failed' => 0,
        'issue_counts' => ['error' => 0, 'warning' => 0, 'info' => 0],
        'top_issue_codes' => [],
    ],
    'users' => [],
];

foreach ($profiles as $index => $profile) {
    $linePrefix = sprintf('[%02d/%02d] %s', $index + 1, $limit, (string) $profile['_case_code']);
    fwrite(STDOUT, "{$linePrefix}: register/reuse...\n");

    try {
        $registrationResult = liveBatchRegisterOrReuse($db, $registration, $profile, $reuseExisting);
        $userId = (int) $registrationResult['id'];
        if (!empty($registrationResult['created'])) {
            $report['summary']['created_users']++;
        } else {
            $report['summary']['reused_users']++;
        }

        $generation = ['ok' => null, 'skipped' => true];
        if (!$skipGeneration) {
            fwrite(STDOUT, "{$linePrefix}: generate user_id={$userId}...\n");
            $generation = liveBatchRunGeneration($db, $queue, $processor, $userId);
            if (!empty($generation['ok'])) {
                $report['summary']['generation_ok']++;
            } else {
                $report['summary']['generation_failed']++;
            }
        }

        $userRow = liveBatchFetchUserByUsername($db, (string) $profile['username']) ?: [];
        $plan = liveBatchFetchSavedPlan($db, $userId);
        $evaluation = liveBatchEvaluatePlan($userRow, $plan, $generation);
        foreach (($evaluation['summary']['issue_counts'] ?? []) as $severity => $count) {
            $report['summary']['issue_counts'][$severity] = ($report['summary']['issue_counts'][$severity] ?? 0) + (int) $count;
        }
        foreach (($evaluation['summary']['issue_code_counts'] ?? []) as $code => $count) {
            $report['summary']['top_issue_codes'][$code] = ($report['summary']['top_issue_codes'][$code] ?? 0) + (int) $count;
        }

        $report['users'][] = [
            'user_id' => $userId,
            'created' => !empty($registrationResult['created']),
            'profile' => $profile,
            'generation' => $generation,
            'evaluation' => $evaluation,
        ];
    } catch (Throwable $e) {
        fwrite(STDERR, "{$linePrefix}: ERROR {$e->getMessage()}\n");
        $report['summary']['generation_failed']++;
        $report['users'][] = [
            'user_id' => null,
            'created' => false,
            'profile' => $profile,
            'generation' => ['ok' => false, 'error' => $e->getMessage()],
            'evaluation' => ['issues' => [liveBatchIssue('error', 'batch_failure', $e->getMessage())], 'summary' => ['issue_counts' => ['error' => 1, 'warning' => 0, 'info' => 0]]],
        ];
    }
}

arsort($report['summary']['top_issue_codes']);
$report['summary']['top_issue_codes'] = array_slice($report['summary']['top_issue_codes'], 0, 20, true);

$artifactBase = $saveDir . '/' . $prefix . '_' . gmdate('Ymd_His');
$jsonPath = $artifactBase . '.json';
$mdPath = $artifactBase . '.md';
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($mdPath, liveBatchBuildMarkdown($report));

fwrite(STDOUT, "JSON: {$jsonPath}\n");
fwrite(STDOUT, "Markdown: {$mdPath}\n");
fwrite(STDOUT, "Summary: " . json_encode($report['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

exit($report['summary']['generation_failed'] > 0 ? 1 : 0);
