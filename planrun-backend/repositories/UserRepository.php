<?php
/**
 * UserRepository — единственный канонический способ читать данные из таблицы users.
 *
 * ВСЕ новые чтения из users ДОЛЖНЫ идти через этот класс.
 * Не пишите SELECT ... FROM users в сервисах, контроллерах и скриптах.
 *
 * Примеры использования:
 *   $userRepo = new UserRepository($db);
 *   $user     = $userRepo->getById(42);              // полный профиль (кешируется)
 *   $email    = $userRepo->getField(42, 'email');     // одно поле
 *   $planning = $userRepo->getForPlanning(42);        // только поля для генерации плана
 *   $users    = $userRepo->getActiveUsers();           // все незабаненные с onboarding
 */

require_once __DIR__ . '/BaseRepository.php';
require_once __DIR__ . '/../cache_config.php';

class UserRepository extends BaseRepository {

    // ── Полный профиль ──

    /** Стандартный набор полей (всё кроме password). */
    private const PROFILE_FIELDS = 'id, username, username_slug, email, role, goal_type,
        race_date, race_target_time, race_distance,
        target_marathon_date, target_marathon_time, training_start_date,
        weekly_base_km, experience_level, gender, birth_year, height_cm, weight_kg,
        timezone, telegram_id, created_at, updated_at, training_mode,
        ofp_preference, preferred_days, preferred_ofp_days, sessions_per_week,
        has_treadmill, training_time_pref, easy_pace_sec, running_experience,
        last_race_date, last_race_time, last_race_distance, last_race_distance_km,
        is_first_race_at_distance, weight_goal_kg, weight_goal_date,
        health_program, health_notes, current_running_level, health_plan_weeks,
        device_type, avatar_path, privacy_level, public_token,
        privacy_show_email, privacy_show_trainer, privacy_show_calendar,
        privacy_show_metrics, privacy_show_workouts,
        push_workouts_enabled, push_chat_enabled, push_workout_hour, push_workout_minute,
        coach_style, coach_bio, coach_specialization, coach_accepts,
        coach_prices_on_request, coach_experience_years, coach_philosophy,
        last_activity, max_hr, rest_hr, onboarding_completed, banned,
        planning_benchmark_distance, planning_benchmark_distance_km,
        planning_benchmark_time, planning_benchmark_date,
        planning_benchmark_type, planning_benchmark_effort, planning_easy_min_km';

    /**
     * Полный профиль пользователя по ID (кешируется на 30 мин).
     * Используй этот метод по умолчанию.
     */
    public function getById(int $userId, bool $useCache = true): ?array {
        if ($userId <= 0) return null;

        $cacheKey = "user_repo_{$userId}";
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) return $cached;
        }

        $row = $this->fetchOne(
            "SELECT " . self::PROFILE_FIELDS . " FROM users WHERE id = ? LIMIT 1",
            [$userId], 'i'
        );

        if ($row && $useCache) {
            Cache::set($cacheKey, $row, 1800);
        }
        return $row;
    }

    /**
     * Инвалидировать кеш пользователя (вызывать после UPDATE users).
     */
    public function invalidateCache(int $userId): void {
        Cache::delete("user_repo_{$userId}");
        Cache::delete("user_data_{$userId}"); // legacy getUserData() cache
    }

    // ── Одно поле ──

    /**
     * Получить одно поле пользователя. Не кешируется — для редких / точечных запросов.
     * Пример: $userRepo->getField(42, 'email')
     */
    public function getField(int $userId, string $field): mixed {
        // Белый список: защита от SQL-инъекции через имя поля
        $allowed = [
            'id','username','username_slug','email','role','timezone','avatar_path',
            'telegram_id','telegram_link_code','telegram_link_code_expires',
            'public_token','privacy_level','max_hr','rest_hr','birth_year',
            'push_workouts_enabled','push_chat_enabled','push_workout_hour','push_workout_minute',
            'training_start_date','preferred_days','preferred_ofp_days','onboarding_completed',
            'goal_type','race_date','race_target_time','race_distance',
            'target_marathon_date','target_marathon_time','training_mode',
            'coach_accepts','coach_bio','coach_specialization','coach_philosophy',
            'coach_experience_years','coach_prices_on_request','last_activity','banned',
        ];
        if (!in_array($field, $allowed, true)) {
            throw new \InvalidArgumentException("UserRepository::getField — disallowed field: {$field}");
        }

        $row = $this->fetchOne(
            "SELECT {$field} FROM users WHERE id = ? LIMIT 1",
            [$userId], 'i'
        );
        return $row[$field] ?? null;
    }

    // ── Специализированные проекции ──

    /**
     * Поля, нужные для генерации / пересчёта плана.
     * Используется в PlanGenerationProcessorService, TrainingStateBuilder, prompt_builder.
     */
    public function getForPlanning(int $userId): ?array {
        return $this->fetchOne(
            "SELECT id, username, goal_type, race_date, race_target_time, race_distance,
                    target_marathon_date, target_marathon_time, training_start_date,
                    weekly_base_km, experience_level, gender, birth_year, height_cm, weight_kg,
                    sessions_per_week, preferred_days, preferred_ofp_days,
                    has_treadmill, ofp_preference, training_time_pref,
                    easy_pace_sec, running_experience, health_notes, health_program,
                    current_running_level, health_plan_weeks, device_type, coach_style,
                    last_race_date, last_race_time, last_race_distance, last_race_distance_km,
                    is_first_race_at_distance, weight_goal_kg, weight_goal_date,
                    max_hr, rest_hr, training_mode, timezone,
                    planning_benchmark_distance, planning_benchmark_distance_km,
                    planning_benchmark_time, planning_benchmark_date,
                    planning_benchmark_type, planning_benchmark_effort, planning_easy_min_km
             FROM users WHERE id = ? LIMIT 1",
            [$userId], 'i'
        );
    }

    /**
     * Минимальные поля для авторизации / JWT refresh.
     * Используется в AuthController, JwtService.
     */
    public function getForAuth(int $userId): ?array {
        return $this->fetchOne(
            "SELECT id, username, username_slug, email, password, role,
                    avatar_path, onboarding_completed, timezone, training_mode
             FROM users WHERE id = ? LIMIT 1",
            [$userId], 'i'
        );
    }

    /**
     * Поля для публичного профиля / календаря.
     * Используется в api_v2.php (public profile), calendar_access.php.
     */
    public function getForPublicProfile(int $userId): ?array {
        return $this->fetchOne(
            "SELECT id, username, username_slug, email, avatar_path,
                    privacy_level, public_token, goal_type, race_date, race_distance, race_target_time,
                    target_marathon_date, target_marathon_time, training_mode,
                    privacy_show_email, privacy_show_trainer, privacy_show_calendar,
                    privacy_show_metrics, privacy_show_workouts, role,
                    coach_bio, coach_specialization, coach_accepts,
                    coach_prices_on_request, coach_experience_years, coach_philosophy,
                    telegram_id, telegram_link_code, telegram_link_code_expires
             FROM users WHERE id = ? LIMIT 1",
            [$userId], 'i'
        );
    }

    /**
     * Поля для HR / нагрузки (TrainingLoadService, StatsService).
     */
    public function getForHrCalculation(int $userId): ?array {
        return $this->fetchOne(
            "SELECT id, max_hr, rest_hr, birth_year FROM users WHERE id = ? LIMIT 1",
            [$userId], 'i'
        );
    }

    /**
     * Поля для уведомлений (push, email, telegram).
     */
    public function getForNotifications(int $userId): ?array {
        return $this->fetchOne(
            "SELECT id, username, username_slug, email, telegram_id, timezone,
                    push_workouts_enabled, push_chat_enabled,
                    push_workout_hour, push_workout_minute
             FROM users WHERE id = ? LIMIT 1",
            [$userId], 'i'
        );
    }

    // ── Поиск / lookup ──

    /**
     * Найти пользователя по username или email (для логина).
     */
    public function findByLogin(string $login): ?array {
        return $this->fetchOne(
            "SELECT id, username, email, password, role
             FROM users
             WHERE username = ? OR (email IS NOT NULL AND email != '' AND email = ?)
             LIMIT 1",
            [$login, $login], 'ss'
        );
    }

    /**
     * Найти пользователя по username_slug.
     */
    public function findBySlug(string $slug): ?array {
        return $this->fetchOne(
            "SELECT " . self::PROFILE_FIELDS . " FROM users WHERE username_slug = ? LIMIT 1",
            [$slug], 's'
        );
    }

    /**
     * Найти пользователя по telegram_id.
     */
    public function findByTelegramId(int $telegramId): ?array {
        return $this->fetchOne(
            "SELECT " . self::PROFILE_FIELDS . " FROM users WHERE telegram_id = ? LIMIT 1",
            [$telegramId], 'i'
        );
    }

    /**
     * Проверить уникальность username / email / slug.
     * Возвращает id если занято, null если свободно.
     */
    public function findIdByUsername(string $username, ?int $excludeId = null): ?int {
        $sql = "SELECT id FROM users WHERE username = ?";
        $params = [$username];
        $types = 's';
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= 'i';
        }
        $row = $this->fetchOne($sql . " LIMIT 1", $params, $types);
        return $row ? (int) $row['id'] : null;
    }

    public function findIdByEmail(string $email, ?int $excludeId = null): ?int {
        $sql = "SELECT id FROM users WHERE email = ? AND email IS NOT NULL AND email != ''";
        $params = [$email];
        $types = 's';
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= 'i';
        }
        $row = $this->fetchOne($sql . " LIMIT 1", $params, $types);
        return $row ? (int) $row['id'] : null;
    }

    public function findIdBySlug(string $slug, ?int $excludeId = null): ?int {
        $sql = "SELECT id FROM users WHERE username_slug = ?";
        $params = [$slug];
        $types = 's';
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= 'i';
        }
        $row = $this->fetchOne($sql . " LIMIT 1", $params, $types);
        return $row ? (int) $row['id'] : null;
    }

    // ── Массовые выборки ──

    /**
     * Все активные пользователи (onboarding пройден, не забанен).
     * Для cron-скриптов, массовой обработки.
     */
    public function getActiveUserIds(): array {
        $rows = $this->fetchAll(
            "SELECT id FROM users WHERE onboarding_completed = 1 AND banned = 0 ORDER BY id"
        );
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    /**
     * Активные пользователи с тренировочным планом.
     * Для ProactiveCoachService, weekly_ai_review.
     */
    public function getActiveUsersWithPlan(): array {
        return $this->fetchAll(
            "SELECT u.* FROM users u
             WHERE u.onboarding_completed = 1 AND u.banned = 0
               AND EXISTS (SELECT 1 FROM training_plan_weeks w WHERE w.user_id = u.id)
             ORDER BY u.id"
        );
    }

    /**
     * Все user_id для рассылки (кроме забаненных и excludeId).
     */
    public function getAllIdsForBroadcast(?int $excludeId = null): array {
        $sql = "SELECT id FROM users WHERE banned = 0";
        $params = [];
        $types = '';
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types = 'i';
        }
        $rows = $this->fetchAll($sql . " ORDER BY id", $params, $types);
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    /**
     * Все admin-ы.
     */
    public function getAdminIds(): array {
        $rows = $this->fetchAll("SELECT id FROM users WHERE role = 'admin'");
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    // ── Листинги с пагинацией ──

    /**
     * Каталог тренеров с фильтрами и пагинацией.
     * Используется в CoachService::listCoaches().
     *
     * @return array{rows: array, total: int}
     */
    public function listCoaches(array $filters, int $limit, int $offset): array {
        $where = ["u.role = 'coach'"];
        $params = [];
        $types = '';

        if (!empty($filters['specialization'])) {
            $where[] = "JSON_CONTAINS(u.coach_specialization, ?)";
            $params[] = '"' . $filters['specialization'] . '"';
            $types .= 's';
        }
        if (isset($filters['accepts_new']) && $filters['accepts_new'] !== '') {
            $where[] = "u.coach_accepts = ?";
            $params[] = (int)$filters['accepts_new'];
            $types .= 'i';
        }

        $whereClause = implode(' AND ', $where);

        $countRow = $this->fetchOne(
            "SELECT COUNT(*) as total FROM users u WHERE $whereClause",
            $params, $types
        );
        $total = (int)($countRow['total'] ?? 0);

        $allParams = array_merge($params, [$limit, $offset]);
        $allTypes = $types . 'ii';

        $rows = $this->fetchAll(
            "SELECT u.id, u.username, u.username_slug, u.avatar_path, u.coach_bio,
                    u.coach_specialization, u.coach_accepts, u.coach_prices_on_request,
                    u.coach_experience_years, u.coach_philosophy, u.last_activity
             FROM users u
             WHERE $whereClause
             ORDER BY u.last_activity DESC
             LIMIT ? OFFSET ?",
            $allParams, $allTypes
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Список пользователей с поиском и пагинацией (для админки).
     * Используется в AdminService::listUsers().
     *
     * @return array{rows: array, total: int}
     */
    public function searchUsers(string $search, int $limit, int $offset): array {
        $where = '1=1';
        $params = [];
        $types = '';

        if ($search !== '') {
            $where .= ' AND (username LIKE ? OR email LIKE ?)';
            $term = '%' . $search . '%';
            $params = [$term, $term];
            $types = 'ss';
        }

        $countRow = $this->fetchOne(
            "SELECT COUNT(*) AS total FROM users WHERE $where",
            $params, $types
        );
        $total = (int)($countRow['total'] ?? 0);

        $allParams = array_merge($params, [$limit, $offset]);
        $allTypes = $types . 'ii';

        $rows = $this->fetchAll(
            "SELECT id, username, email, role, created_at, training_mode, goal_type
             FROM users WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?",
            $allParams, $allTypes
        );

        return ['rows' => $rows, 'total' => $total];
    }

    // ── Write helpers ──

    /**
     * Обновить произвольные поля пользователя.
     * Белый список защищает от SQL-инъекции через имя поля.
     * После обновления автоматически инвалидирует кеш.
     */
    public function update(int $userId, array $data): void {
        if (empty($data) || $userId <= 0) return;

        $allowed = [
            'username','username_slug','email','role','timezone','avatar_path',
            'telegram_id','telegram_link_code','telegram_link_code_expires',
            'public_token','privacy_level','max_hr','rest_hr','birth_year',
            'push_workouts_enabled','push_chat_enabled','push_workout_hour','push_workout_minute',
            'training_start_date','preferred_days','preferred_ofp_days','onboarding_completed',
            'goal_type','race_date','race_target_time','race_distance',
            'target_marathon_date','target_marathon_time','training_mode',
            'coach_accepts','coach_bio','coach_specialization','coach_philosophy',
            'coach_experience_years','coach_prices_on_request',
            'last_activity','banned','password','weekly_base_km',
            'last_race_date','last_race_time','last_race_distance','last_race_distance_km',
            'privacy_show_email','privacy_show_trainer','privacy_show_calendar',
            'privacy_show_metrics','privacy_show_workouts',
            'weight_kg','height_cm','gender','experience_level',
            'sessions_per_week','has_treadmill','ofp_preference','training_time_pref',
            'easy_pace_sec','running_experience','health_notes','health_program',
            'current_running_level','health_plan_weeks','device_type','coach_style',
            'is_first_race_at_distance','weight_goal_kg','weight_goal_date',
            'planning_benchmark_distance','planning_benchmark_distance_km',
            'planning_benchmark_time','planning_benchmark_date',
            'planning_benchmark_type','planning_benchmark_effort','planning_easy_min_km',
        ];

        $sets = [];
        $params = [];
        $types = '';

        foreach ($data as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("UserRepository::update — disallowed field: {$field}");
            }
            $sets[] = "{$field} = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
        }

        $params[] = $userId;
        $types .= 'i';

        $this->execute(
            "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?",
            $params, $types
        );

        $this->invalidateCache($userId);
    }

    /**
     * Обновить last_activity.
     */
    public function touchActivity(int $userId): void {
        $this->execute(
            "UPDATE users SET last_activity = NOW() WHERE id = ?",
            [$userId], 'i'
        );
    }
}
