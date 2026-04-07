<?php
/**
 * Сервис регистрации: валидация полей и минимальная регистрация.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/EmailVerificationService.php';
require_once __DIR__ . '/PlanGenerationQueueService.php';

class RegistrationService extends BaseService {
    private EmailVerificationService $verificationService;
    private PlanGenerationQueueService $queueService;

    public function __construct($db, ?EmailVerificationService $verificationService = null) {
        parent::__construct($db);
        $this->verificationService = $verificationService ?? new EmailVerificationService($db);
        $this->queueService = new PlanGenerationQueueService($db);
    }

    public function validateField(string $field, string $value): array {
        $result = ['valid' => true, 'message' => ''];

        switch ($field) {
            case 'username':
                if ($value === '') {
                    return ['valid' => false, 'message' => 'Имя пользователя обязательно'];
                }
                if (strlen($value) < 3) {
                    return ['valid' => false, 'message' => 'Имя пользователя должно быть не менее 3 символов'];
                }
                if (strlen($value) > 50) {
                    return ['valid' => false, 'message' => 'Имя пользователя должно быть не более 50 символов'];
                }
                if (!preg_match('/^[a-zA-Z0-9_а-яА-ЯёЁ\s-]+$/u', $value)) {
                    return ['valid' => false, 'message' => 'Имя пользователя может содержать только буквы, цифры, пробелы, дефисы и подчеркивания'];
                }
                if ($this->userExistsByUsername($value)) {
                    return ['valid' => false, 'message' => 'Это имя пользователя уже занято'];
                }
                return $result;

            case 'email':
                $email = trim($value);
                if ($email === '') {
                    return ['valid' => false, 'message' => 'Email обязателен'];
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return ['valid' => false, 'message' => 'Некорректный формат email'];
                }
                if ($this->userExistsByEmail($email)) {
                    return ['valid' => false, 'message' => 'Этот email уже используется'];
                }
                return $result;

            default:
                return $result;
        }
    }

    public function registerMinimal(array $input): array {
        $username = trim((string) ($input['username'] ?? ''));
        $password = trim((string) ($input['password'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));

        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'Имя пользователя и пароль обязательны'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Пароль должен быть не менее 6 символов'];
        }
        if ($email === '') {
            return ['success' => false, 'error' => 'Email обязателен'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Некорректный формат email'];
        }

        $verificationResult = $this->verificationService->verifyCode(
            $email,
            (string) ($input['verification_code'] ?? '')
        );
        if (empty($verificationResult['success'])) {
            return $verificationResult;
        }

        $identity = $this->prepareRegistrationIdentity($username, $email);
        if (empty($identity['success'])) {
            return $identity;
        }

        $usernameSlug = (string) $identity['username_slug'];
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $emailVal = $identity['email'];
        $onboardingCompleted = 0;
        $trainingModePlaceholder = 'self';
        $goalTypeHealth = 'health';
        $genderMale = 'male';

        $stmt = $this->db->prepare(
            "INSERT INTO users (username, username_slug, password, email, onboarding_completed, training_mode, goal_type, gender) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Ошибка подготовки запроса: ' . $this->db->error, 500);
        }

        $stmt->bind_param(
            'ssssisss',
            $username,
            $usernameSlug,
            $hashedPassword,
            $emailVal,
            $onboardingCompleted,
            $trainingModePlaceholder,
            $goalTypeHealth,
            $genderMale
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Ошибка выполнения запроса: ' . $error, 500);
        }

        $userId = (int) $this->db->insert_id;
        $stmt->close();

        if ($userId < 1) {
            throw new RuntimeException('Не удалось получить ID нового пользователя', 500);
        }

        return [
            'success' => true,
            'message' => 'Регистрация успешна',
            'plan_message' => null,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'email' => $emailVal,
                'onboarding_completed' => 0,
            ],
        ];
    }

    public function registerFull(array $data): array {
        $username = trim((string) ($data['username'] ?? ''));
        $password = trim((string) ($data['password'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'Имя пользователя и пароль обязательны'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Пароль должен быть не менее 6 символов'];
        }

        $identity = $this->prepareRegistrationIdentity($username, $email);
        if (empty($identity['success'])) {
            return $identity;
        }

        $userId = $this->insertFullUser($data, $identity, $password);
        $this->createInitialTrainingPlan($userId, $data);
        $planGenerationMessage = $this->startPlanGeneration($userId, (string) ($data['training_mode'] ?? 'ai'));

        return [
            'success' => true,
            'message' => 'Регистрация успешна',
            'plan_message' => $planGenerationMessage,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'email' => $identity['email'],
            ],
        ];
    }

    public function prepareRegistrationIdentity(string $username, ?string $email): array {
        $username = trim($username);
        $email = trim((string) $email);

        if (!$this->isRegistrationEnabled()) {
            return ['success' => false, 'error' => 'Регистрация отключена администратором'];
        }
        if ($this->userExistsByUsername($username)) {
            return ['success' => false, 'error' => 'Пользователь с таким именем уже существует'];
        }
        if ($email !== '' && $this->userExistsByEmail($email)) {
            return ['success' => false, 'error' => 'Этот email уже используется'];
        }

        return [
            'success' => true,
            'username_slug' => $this->generateUniqueUsernameSlug($username),
            'email' => $email !== '' ? $email : null,
        ];
    }

    private function isRegistrationEnabled(): bool {
        $tableExists = @$this->db->query("SHOW TABLES LIKE 'site_settings'");
        if (!$tableExists || $tableExists->num_rows === 0) {
            return true;
        }

        $res = @$this->db->query("SELECT value FROM site_settings WHERE `key` = 'registration_enabled' LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        return !($row && isset($row['value']) && (string) $row['value'] === '0');
    }

    private function userExistsByUsername(string $username): bool {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE username = ?');
        if (!$stmt) {
            throw new RuntimeException('Ошибка проверки пользователя: ' . $this->db->error, 500);
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    private function userExistsByEmail(string $email): bool {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND email IS NOT NULL AND email != ""');
        if (!$stmt) {
            throw new RuntimeException('Ошибка проверки email: ' . $this->db->error, 500);
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    private function generateUniqueUsernameSlug(string $username): string {
        $slugBase = function_exists('mb_strtolower')
            ? mb_strtolower($username, 'UTF-8')
            : strtolower($username);
        $slugBase = preg_replace('/[^a-z0-9_]/', '_', $slugBase);
        $slugBase = preg_replace('/_+/', '_', (string) $slugBase);
        $slugBase = trim((string) $slugBase, '_');

        if ($slugBase === '') {
            $slugBase = 'user_' . substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
        }

        $slug = $slugBase;
        $counter = 1;
        while ($this->usernameSlugExists($slug)) {
            $slug = $slugBase . '_' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function usernameSlugExists(string $slug): bool {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE username_slug = ?');
        if (!$stmt) {
            throw new RuntimeException('Ошибка проверки username_slug: ' . $this->db->error, 500);
        }

        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    private function insertFullUser(array $data, array $identity, string $password): int {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $userData = [
            'username' => ['value' => trim((string) ($data['username'] ?? '')), 'type' => 's'],
            'username_slug' => ['value' => (string) $identity['username_slug'], 'type' => 's'],
            'password' => ['value' => $hashedPassword, 'type' => 's'],
            'email' => ['value' => $identity['email'], 'type' => 's'],
            'role' => ['value' => 'user', 'type' => 's'],
            'goal_type' => ['value' => $data['goal_type'] ?? null, 'type' => 's'],
            'race_distance' => ['value' => $data['race_distance'] ?? null, 'type' => 's'],
            'race_date' => ['value' => $data['race_date'] ?? null, 'type' => 's'],
            'race_target_time' => ['value' => $data['race_target_time'] ?? null, 'type' => 's'],
            'target_marathon_date' => ['value' => $data['target_marathon_date'] ?? null, 'type' => 's'],
            'target_marathon_time' => ['value' => $data['target_marathon_time'] ?? null, 'type' => 's'],
            'training_start_date' => ['value' => $data['training_start_date'] ?? null, 'type' => 's'],
            'gender' => ['value' => $data['gender'] ?? null, 'type' => 's'],
            'birth_year' => ['value' => $data['birth_year'] ?? null, 'type' => 'i'],
            'height_cm' => ['value' => $data['height_cm'] ?? null, 'type' => 'i'],
            'weight_kg' => ['value' => $data['weight_kg'] ?? null, 'type' => 'd'],
            'experience_level' => ['value' => $data['experience_level'] ?? null, 'type' => 's'],
            'weekly_base_km' => ['value' => $data['weekly_base_km'] ?? null, 'type' => 'd'],
            'sessions_per_week' => ['value' => $data['sessions_per_week'] ?? null, 'type' => 'i'],
            'preferred_days' => ['value' => $data['preferred_days'] ?? null, 'type' => 's'],
            'preferred_ofp_days' => ['value' => $data['preferred_ofp_days'] ?? null, 'type' => 's'],
            'has_treadmill' => ['value' => $data['has_treadmill'] ?? 0, 'type' => 'i'],
            'ofp_preference' => ['value' => $data['ofp_preference'] ?? null, 'type' => 's'],
            'training_time_pref' => ['value' => $data['training_time_pref'] ?? null, 'type' => 's'],
            'health_notes' => ['value' => $data['health_notes'] ?? null, 'type' => 's'],
            'device_type' => ['value' => $data['device_type'] ?? null, 'type' => 's'],
            'weight_goal_kg' => ['value' => $data['weight_goal_kg'] ?? null, 'type' => 'd'],
            'weight_goal_date' => ['value' => $data['weight_goal_date'] ?? null, 'type' => 's'],
            'health_program' => ['value' => $data['health_program'] ?? null, 'type' => 's'],
            'health_plan_weeks' => ['value' => $data['health_plan_weeks'] ?? null, 'type' => 'i'],
            'current_running_level' => ['value' => $data['current_running_level'] ?? null, 'type' => 's'],
            'running_experience' => ['value' => $data['running_experience'] ?? null, 'type' => 's'],
            'easy_pace_sec' => ['value' => $data['easy_pace_sec'] ?? null, 'type' => 'i'],
            'is_first_race_at_distance' => ['value' => $data['is_first_race_at_distance'] ?? null, 'type' => 'i'],
            'last_race_distance' => ['value' => $data['last_race_distance'] ?? null, 'type' => 's'],
            'last_race_distance_km' => ['value' => $data['last_race_distance_km'] ?? null, 'type' => 'd'],
            'last_race_time' => ['value' => $data['last_race_time'] ?? null, 'type' => 's'],
            'last_race_date' => ['value' => $data['last_race_date'] ?? null, 'type' => 's'],
            'planning_benchmark_distance' => ['value' => $data['planning_benchmark_distance'] ?? null, 'type' => 's'],
            'planning_benchmark_distance_km' => ['value' => $data['planning_benchmark_distance_km'] ?? null, 'type' => 'd'],
            'planning_benchmark_time' => ['value' => $data['planning_benchmark_time'] ?? null, 'type' => 's'],
            'planning_benchmark_date' => ['value' => $data['planning_benchmark_date'] ?? null, 'type' => 's'],
            'planning_benchmark_type' => ['value' => $data['planning_benchmark_type'] ?? null, 'type' => 's'],
            'planning_benchmark_effort' => ['value' => $data['planning_benchmark_effort'] ?? null, 'type' => 's'],
            'training_mode' => ['value' => $data['training_mode'] ?? 'ai', 'type' => 's'],
            'onboarding_completed' => ['value' => 1, 'type' => 'i'],
        ];

        $fields = array_keys($userData);
        $placeholders = array_fill(0, count($fields), '?');
        $types = '';
        foreach ($userData as $info) {
            $types .= $info['type'];
        }

        $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Ошибка подготовки запроса: ' . $this->db->error, 500);
        }

        $bindValues = [$types];
        foreach ($userData as $info) {
            $bindValues[] = $info['value'];
        }
        $refs = [];
        foreach ($bindValues as $key => $value) {
            $refs[$key] = &$bindValues[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Ошибка выполнения запроса: ' . $error, 500);
        }

        $userId = (int) $this->db->insert_id;
        $stmt->close();

        if ($userId < 1) {
            throw new RuntimeException('Не удалось получить ID нового пользователя', 500);
        }

        return $userId;
    }

    private function createInitialTrainingPlan(int $userId, array $data): void {
        $goalType = (string) ($data['goal_type'] ?? 'health');
        $planDate = null;
        $planTime = null;

        if ($goalType === 'race' || $goalType === 'time_improvement') {
            if (!empty($data['race_date'])) {
                $planDate = $data['race_date'];
                $planTime = $data['race_target_time'] ?: ($data['target_marathon_time'] ?? null);
            } elseif (!empty($data['target_marathon_date'])) {
                $planDate = $data['target_marathon_date'];
                $planTime = $data['target_marathon_time'] ?? null;
            }
        } elseif ($goalType === 'weight_loss') {
            $planDate = $data['weight_goal_date'] ?: ($data['target_marathon_date'] ?? null);
        } elseif ($goalType === 'health') {
            $planDate = $data['target_marathon_date'] ?? null;
            $planTime = $data['target_marathon_time'] ?? null;
        }

        $stmt = $this->db->prepare('
            INSERT INTO user_training_plans (user_id, start_date, marathon_date, target_time, is_active)
            VALUES (?, CURDATE(), ?, ?, FALSE)
        ');
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('iss', $userId, $planDate, $planTime);
        $stmt->execute();
        $stmt->close();
    }

    private function startPlanGeneration(int $userId, string $trainingMode): ?string {
        if ($trainingMode === 'self') {
            return 'Календарь готов. Добавляйте тренировки на любую дату.';
        }

        if ($trainingMode !== 'ai' && $trainingMode !== 'both') {
            return null;
        }

        try {
            $this->queueService->enqueue($userId, 'generate');
            return 'План тренировок генерируется через PlanRun AI. Это займет 3-5 минут.';
        } catch (Throwable $e) {
            $this->logError('Ошибка запуска генерации плана', ['error' => $e->getMessage(), 'user_id' => $userId]);
            return 'План будет сгенерирован автоматически.';
        }
    }
}
