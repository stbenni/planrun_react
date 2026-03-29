<?php

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/TelegramLoginService.php';

class NotificationSettingsService extends BaseService {
    private static bool $schemaEnsured = false;
    private array $settingsCache = [];

    public const CHANNEL_KEYS = ['mobile_push', 'web_push', 'telegram', 'email'];

    public function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        $queries = [
            "CREATE TABLE IF NOT EXISTS notification_channel_settings (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                mobile_push_enabled TINYINT(1) NOT NULL DEFAULT 1,
                web_push_enabled TINYINT(1) NOT NULL DEFAULT 1,
                telegram_enabled TINYINT(1) NOT NULL DEFAULT 1,
                email_enabled TINYINT(1) NOT NULL DEFAULT 1,
                quiet_hours_enabled TINYINT(1) NOT NULL DEFAULT 0,
                quiet_hours_start TIME NOT NULL DEFAULT '22:00:00',
                quiet_hours_end TIME NOT NULL DEFAULT '07:00:00',
                workout_today_hour TINYINT NOT NULL DEFAULT 8,
                workout_today_minute TINYINT NOT NULL DEFAULT 0,
                workout_tomorrow_hour TINYINT NOT NULL DEFAULT 20,
                workout_tomorrow_minute TINYINT NOT NULL DEFAULT 0,
                email_digest_mode VARCHAR(16) NOT NULL DEFAULT 'instant',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_notification_channel_settings_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS notification_preferences (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                event_key VARCHAR(96) NOT NULL,
                mobile_push_enabled TINYINT(1) NOT NULL DEFAULT 0,
                web_push_enabled TINYINT(1) NOT NULL DEFAULT 0,
                telegram_enabled TINYINT(1) NOT NULL DEFAULT 0,
                email_enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_notification_pref_user_event (user_id, event_key),
                INDEX idx_notification_pref_user (user_id),
                INDEX idx_notification_pref_event (event_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS web_push_subscriptions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                endpoint VARCHAR(512) NOT NULL,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                user_agent VARCHAR(255) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_web_push_endpoint (endpoint(191)),
                INDEX idx_web_push_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS notification_deliveries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                event_key VARCHAR(96) NOT NULL,
                channel VARCHAR(24) NOT NULL,
                status VARCHAR(32) NOT NULL,
                title VARCHAR(255) NULL DEFAULT NULL,
                body TEXT NULL,
                entity_type VARCHAR(64) NULL DEFAULT NULL,
                entity_id VARCHAR(64) NULL DEFAULT NULL,
                error_text VARCHAR(255) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notification_deliveries_user (user_id),
                INDEX idx_notification_deliveries_event (event_key),
                INDEX idx_notification_deliveries_channel (channel),
                INDEX idx_notification_deliveries_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS notification_dispatch_guards (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                event_key VARCHAR(96) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(128) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'processing',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                sent_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uk_notification_dispatch_guard (user_id, event_key, entity_type, entity_id),
                INDEX idx_notification_dispatch_guard_status (status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS notification_delivery_queue (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                event_key VARCHAR(96) NOT NULL,
                channel VARCHAR(24) NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NULL,
                link VARCHAR(512) NULL DEFAULT NULL,
                push_data_json MEDIUMTEXT NULL,
                email_action_label VARCHAR(128) NULL DEFAULT NULL,
                entity_type VARCHAR(64) NULL DEFAULT NULL,
                entity_id VARCHAR(128) NULL DEFAULT NULL,
                deliver_after DATETIME NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                last_error VARCHAR(255) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_notification_delivery_queue_due (status, deliver_after),
                INDEX idx_notification_delivery_queue_user (user_id),
                INDEX idx_notification_delivery_queue_event (event_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS notification_email_digest_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                event_key VARCHAR(96) NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NULL,
                link VARCHAR(512) NULL DEFAULT NULL,
                entity_type VARCHAR(64) NULL DEFAULT NULL,
                entity_id VARCHAR(128) NULL DEFAULT NULL,
                digest_after DATETIME NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_notification_email_digest_due (status, digest_after),
                INDEX idx_notification_email_digest_user (user_id),
                INDEX idx_notification_email_digest_event (event_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($queries as $sql) {
            if (!$this->db->query($sql)) {
                $this->throwException('Не удалось подготовить схему настроек уведомлений', 500, [
                    'error' => $this->db->error,
                ]);
            }
        }

        self::$schemaEnsured = true;
    }

    public static function getEventCatalog(): array {
        return [
            [
                'key' => 'workouts',
                'label' => 'Тренировки',
                'description' => 'Напоминания о ближайших тренировках',
                'events' => [
                    [
                        'event_key' => 'workout.reminder.today',
                        'label' => 'Сегодняшняя тренировка',
                        'description' => 'Напоминание в день тренировки',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'workout.reminder.tomorrow',
                        'label' => 'Завтрашняя тренировка',
                        'description' => 'Напоминание накануне тренировки',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                ],
            ],
            [
                'key' => 'chat',
                'label' => 'Чат',
                'description' => 'Сообщения от пользователей, администрации и AI',
                'events' => [
                    [
                        'event_key' => 'chat.admin_message',
                        'label' => 'Сообщение от администрации',
                        'description' => 'Когда администратор пишет вам в чат',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'chat.direct_message',
                        'label' => 'Сообщение от пользователя',
                        'description' => 'Когда другой пользователь пишет вам напрямую',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'chat.ai_message',
                        'label' => 'Сообщение от AI-тренера',
                        'description' => 'Когда AI присылает новый ответ вне открытого чата',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'admin.new_user_message',
                        'label' => 'Новое сообщение в admin-чате',
                        'description' => 'Когда пользователь пишет администрации',
                        'channels' => self::CHANNEL_KEYS,
                        'roles' => ['admin'],
                    ],
                ],
            ],
            [
                'key' => 'plan',
                'label' => 'План и адаптация',
                'description' => 'Изменения плана, заметки и AI-обзоры',
                'events' => [
                    [
                        'event_key' => 'plan.coach_updated',
                        'label' => 'Тренер обновил план',
                        'description' => 'Изменение тренировки или копирование плана',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'plan.coach_note_added',
                        'label' => 'Тренер оставил заметку',
                        'description' => 'Новая заметка к дню или неделе',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'coach.athlete_result_logged',
                        'label' => 'Атлет внёс результат',
                        'description' => 'Результат тренировки, доступный тренеру',
                        'channels' => self::CHANNEL_KEYS,
                        'roles' => ['coach', 'admin'],
                    ],
                    [
                        'event_key' => 'plan.weekly_review',
                        'label' => 'Недельный AI-обзор',
                        'description' => 'Еженедельное сообщение с разбором плана',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'plan.weekly_adaptation',
                        'label' => 'Недельная адаптация',
                        'description' => 'AI адаптировал план на следующую неделю',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'plan.generated',
                        'label' => 'План сгенерирован',
                        'description' => 'Готов новый тренировочный план',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'plan.recalculated',
                        'label' => 'План пересчитан',
                        'description' => 'AI завершил пересчёт плана',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'plan.next_generated',
                        'label' => 'Следующий план готов',
                        'description' => 'Сформирован следующий цикл тренировок',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                    [
                        'event_key' => 'performance.vdot_updated',
                        'label' => 'VDOT обновлён',
                        'description' => 'После контрольной тренировки или забега',
                        'channels' => self::CHANNEL_KEYS,
                    ],
                ],
            ],
            [
                'key' => 'system',
                'label' => 'Системные',
                'description' => 'Сервисные письма, которые нельзя отключить',
                'events' => [
                    [
                        'event_key' => 'system.auth_verification_code',
                        'label' => 'Код подтверждения email',
                        'description' => 'Письмо при регистрации',
                        'channels' => ['email'],
                        'locked' => true,
                    ],
                    [
                        'event_key' => 'system.password_reset',
                        'label' => 'Сброс пароля',
                        'description' => 'Письмо для восстановления доступа',
                        'channels' => ['email'],
                        'locked' => true,
                    ],
                ],
            ],
        ];
    }

    public static function getEventDefinitions(): array {
        $definitions = [];
        foreach (self::getEventCatalog() as $group) {
            foreach (($group['events'] ?? []) as $event) {
                $eventKey = (string) ($event['event_key'] ?? '');
                if ($eventKey === '') {
                    continue;
                }
                $definitions[$eventKey] = $event + [
                    'group_key' => $group['key'] ?? 'other',
                    'group_label' => $group['label'] ?? 'Прочее',
                ];
            }
        }
        return $definitions;
    }

    public function getSettings(int $userId): array {
        $this->ensureSchema();

        if (isset($this->settingsCache[$userId])) {
            return $this->settingsCache[$userId];
        }

        $user = $this->getUserContext($userId);
        if (!$user) {
            $this->throwNotFoundException('Пользователь не найден');
        }

        $defaults = $this->buildDefaultSettings($user);
        $channelRow = $this->getChannelSettingsRow($userId);
        $preferenceRows = $this->getPreferenceRows($userId);
        $webPushSubscriptionItems = $this->getWebPushSubscriptionItems($userId);

        $channels = $defaults['channels'];
        foreach (self::CHANNEL_KEYS as $channel) {
            $enabledKey = $channel . '_enabled';
            if (isset($channelRow[$enabledKey])) {
                $channels[$channel]['enabled'] = ((int) $channelRow[$enabledKey]) === 1;
            }
        }
        $channels['web_push']['subscription_items'] = $webPushSubscriptionItems;

        $schedule = $defaults['schedule'];
        if ($channelRow) {
            $schedule['workout_today_time'] = sprintf('%02d:%02d', (int) ($channelRow['workout_today_hour'] ?? 8), (int) ($channelRow['workout_today_minute'] ?? 0));
            $schedule['workout_tomorrow_time'] = sprintf('%02d:%02d', (int) ($channelRow['workout_tomorrow_hour'] ?? 20), (int) ($channelRow['workout_tomorrow_minute'] ?? 0));
        }

        $quietHours = $defaults['quiet_hours'];
        if ($channelRow) {
            $quietHours['enabled'] = ((int) ($channelRow['quiet_hours_enabled'] ?? 0)) === 1;
            $quietHours['start'] = $this->normalizeStoredTime((string) ($channelRow['quiet_hours_start'] ?? '22:00:00'), '22:00');
            $quietHours['end'] = $this->normalizeStoredTime((string) ($channelRow['quiet_hours_end'] ?? '07:00:00'), '07:00');
            $channels['email']['digest_mode'] = $this->normalizeEmailDigestMode($channelRow['email_digest_mode'] ?? $channels['email']['digest_mode'] ?? 'instant');
        }

        $preferences = $defaults['preferences'];
        foreach ($preferenceRows as $eventKey => $row) {
            if (!isset($preferences[$eventKey])) {
                continue;
            }
            foreach (self::CHANNEL_KEYS as $channel) {
                $field = $channel . '_enabled';
                $preferences[$eventKey][$field] = ((int) ($row[$field] ?? 0)) === 1;
            }
        }

        $settings = [
            'version' => 1,
            'timezone' => $user['timezone'],
            'channels' => $channels,
            'schedule' => $schedule,
            'quiet_hours' => $quietHours,
            'preferences' => $preferences,
            'catalog' => self::getEventCatalog(),
        ];

        $this->settingsCache[$userId] = $settings;
        return $settings;
    }

    public function saveSettings(int $userId, array $payload): array {
        $this->ensureSchema();

        $user = $this->getUserContext($userId);
        if (!$user) {
            $this->throwNotFoundException('Пользователь не найден');
        }

        $current = $this->getSettings($userId);
        $eventDefinitions = self::getEventDefinitions();

        $channelInput = is_array($payload['channels'] ?? null) ? $payload['channels'] : [];
        $scheduleInput = is_array($payload['schedule'] ?? null) ? $payload['schedule'] : [];
        $quietHoursInput = is_array($payload['quiet_hours'] ?? null) ? $payload['quiet_hours'] : [];
        $preferencesInput = is_array($payload['preferences'] ?? null) ? $payload['preferences'] : [];

        $channelRow = [
            'mobile_push_enabled' => $this->extractChannelEnabled($channelInput, 'mobile_push', $current['channels']['mobile_push']['enabled']),
            'web_push_enabled' => $this->extractChannelEnabled($channelInput, 'web_push', $current['channels']['web_push']['enabled']),
            'telegram_enabled' => $this->extractChannelEnabled($channelInput, 'telegram', $current['channels']['telegram']['enabled']),
            'email_enabled' => $this->extractChannelEnabled($channelInput, 'email', $current['channels']['email']['enabled']),
            'quiet_hours_enabled' => $this->normalizeBool($quietHoursInput['enabled'] ?? $current['quiet_hours']['enabled']),
            'quiet_hours_start' => $this->normalizeTime($quietHoursInput['start'] ?? $current['quiet_hours']['start'], '22:00'),
            'quiet_hours_end' => $this->normalizeTime($quietHoursInput['end'] ?? $current['quiet_hours']['end'], '07:00'),
            'workout_today_hour' => (int) substr($this->normalizeTime($scheduleInput['workout_today_time'] ?? $current['schedule']['workout_today_time'], '08:00'), 0, 2),
            'workout_today_minute' => (int) substr($this->normalizeTime($scheduleInput['workout_today_time'] ?? $current['schedule']['workout_today_time'], '08:00'), 3, 2),
            'workout_tomorrow_hour' => (int) substr($this->normalizeTime($scheduleInput['workout_tomorrow_time'] ?? $current['schedule']['workout_tomorrow_time'], '20:00'), 0, 2),
            'workout_tomorrow_minute' => (int) substr($this->normalizeTime($scheduleInput['workout_tomorrow_time'] ?? $current['schedule']['workout_tomorrow_time'], '20:00'), 3, 2),
            'email_digest_mode' => $this->normalizeEmailDigestMode(
                is_array($channelInput['email'] ?? null)
                    ? ($channelInput['email']['digest_mode'] ?? ($current['channels']['email']['digest_mode'] ?? 'instant'))
                    : ($current['channels']['email']['digest_mode'] ?? 'instant')
            ),
        ];

        $stmt = $this->db->prepare("INSERT INTO notification_channel_settings (
                user_id,
                mobile_push_enabled,
                web_push_enabled,
                telegram_enabled,
                email_enabled,
                quiet_hours_enabled,
                quiet_hours_start,
                quiet_hours_end,
                workout_today_hour,
                workout_today_minute,
                workout_tomorrow_hour,
                workout_tomorrow_minute,
                email_digest_mode
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                mobile_push_enabled = VALUES(mobile_push_enabled),
                web_push_enabled = VALUES(web_push_enabled),
                telegram_enabled = VALUES(telegram_enabled),
                email_enabled = VALUES(email_enabled),
                quiet_hours_enabled = VALUES(quiet_hours_enabled),
                quiet_hours_start = VALUES(quiet_hours_start),
                quiet_hours_end = VALUES(quiet_hours_end),
                workout_today_hour = VALUES(workout_today_hour),
                workout_today_minute = VALUES(workout_today_minute),
                workout_tomorrow_hour = VALUES(workout_tomorrow_hour),
                workout_tomorrow_minute = VALUES(workout_tomorrow_minute),
                email_digest_mode = VALUES(email_digest_mode)");
        if (!$stmt) {
            $this->throwException('Не удалось сохранить настройки каналов', 500, ['error' => $this->db->error]);
        }
        $stmt->bind_param(
            'iiiiiissiiiis',
            $userId,
            $channelRow['mobile_push_enabled'],
            $channelRow['web_push_enabled'],
            $channelRow['telegram_enabled'],
            $channelRow['email_enabled'],
            $channelRow['quiet_hours_enabled'],
            $channelRow['quiet_hours_start'],
            $channelRow['quiet_hours_end'],
            $channelRow['workout_today_hour'],
            $channelRow['workout_today_minute'],
            $channelRow['workout_tomorrow_hour'],
            $channelRow['workout_tomorrow_minute'],
            $channelRow['email_digest_mode']
        );
        $stmt->execute();
        if ($stmt->error) {
            $error = $stmt->error;
            $stmt->close();
            $this->throwException('Не удалось сохранить настройки каналов', 500, ['error' => $error]);
        }
        $stmt->close();

        $prefStmt = $this->db->prepare("INSERT INTO notification_preferences (
                user_id,
                event_key,
                mobile_push_enabled,
                web_push_enabled,
                telegram_enabled,
                email_enabled
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                mobile_push_enabled = VALUES(mobile_push_enabled),
                web_push_enabled = VALUES(web_push_enabled),
                telegram_enabled = VALUES(telegram_enabled),
                email_enabled = VALUES(email_enabled)");
        if (!$prefStmt) {
            $this->throwException('Не удалось сохранить настройки событий', 500, ['error' => $this->db->error]);
        }

        foreach ($eventDefinitions as $eventKey => $definition) {
            $currentPref = $current['preferences'][$eventKey] ?? [
                'mobile_push_enabled' => false,
                'web_push_enabled' => false,
                'telegram_enabled' => false,
                'email_enabled' => false,
            ];
            $inputPref = is_array($preferencesInput[$eventKey] ?? null) ? $preferencesInput[$eventKey] : [];
            $channels = $definition['channels'] ?? [];
            $locked = !empty($definition['locked']);

            $mobilePushEnabled = in_array('mobile_push', $channels, true) && !$locked
                ? $this->normalizeBool($inputPref['mobile_push_enabled'] ?? $currentPref['mobile_push_enabled'])
                : 0;
            $webPushEnabled = in_array('web_push', $channels, true) && !$locked
                ? $this->normalizeBool($inputPref['web_push_enabled'] ?? $currentPref['web_push_enabled'])
                : 0;
            $telegramEnabled = in_array('telegram', $channels, true) && !$locked
                ? $this->normalizeBool($inputPref['telegram_enabled'] ?? $currentPref['telegram_enabled'])
                : 0;
            $emailEnabled = in_array('email', $channels, true)
                ? ($locked ? 1 : $this->normalizeBool($inputPref['email_enabled'] ?? $currentPref['email_enabled']))
                : 0;

            $prefStmt->bind_param(
                'isiiii',
                $userId,
                $eventKey,
                $mobilePushEnabled,
                $webPushEnabled,
                $telegramEnabled,
                $emailEnabled
            );
            $prefStmt->execute();
            if ($prefStmt->error) {
                $error = $prefStmt->error;
                $prefStmt->close();
                $this->throwException('Не удалось сохранить настройки событий', 500, [
                    'error' => $error,
                    'event_key' => $eventKey,
                ]);
            }
        }
        $prefStmt->close();

        unset($this->settingsCache[$userId]);
        $this->syncLegacyUserFlags($userId);
        unset($this->settingsCache[$userId]);

        return $this->getSettings($userId);
    }

    public function canDeliver(int $userId, string $channel, string $eventKey, bool $ignoreQuietHours = false): array {
        $settings = $this->getSettings($userId);
        $definitions = self::getEventDefinitions();
        $definition = $definitions[$eventKey] ?? null;
        if (!$definition) {
            return ['allowed' => false, 'reason' => 'unknown_event'];
        }

        $channelData = $settings['channels'][$channel] ?? null;
        if ($channelData === null) {
            return ['allowed' => false, 'reason' => 'unknown_channel'];
        }

        $supportedChannels = $definition['channels'] ?? [];
        if (!in_array($channel, $supportedChannels, true)) {
            return ['allowed' => false, 'reason' => 'unsupported_channel'];
        }

        if (!empty($definition['locked'])) {
            return [
                'allowed' => $channel === 'email' && !empty($channelData['available']),
                'reason' => $channel === 'email' ? 'ok' : 'locked',
            ];
        }

        if (empty($channelData['enabled'])) {
            return ['allowed' => false, 'reason' => 'channel_disabled'];
        }
        if (empty($channelData['available'])) {
            return ['allowed' => false, 'reason' => 'channel_unavailable'];
        }
        if ($channel === 'web_push' && empty($channelData['delivery_ready'])) {
            return ['allowed' => false, 'reason' => 'not_implemented'];
        }

        $preference = $settings['preferences'][$eventKey] ?? null;
        if (!$preference || empty($preference[$channel . '_enabled'])) {
            return ['allowed' => false, 'reason' => 'event_disabled'];
        }

        if (!$ignoreQuietHours && $this->isInQuietHoursFromSettings($settings)) {
            return ['allowed' => false, 'reason' => 'quiet_hours'];
        }

        return ['allowed' => true, 'reason' => 'ok'];
    }

    public function hasAnyDeliverableChannel(int $userId, string $eventKey, bool $ignoreQuietHours = false, array $channels = self::CHANNEL_KEYS): bool {
        foreach ($channels as $channel) {
            $guard = $this->canDeliver($userId, $channel, $eventKey, $ignoreQuietHours);
            if (!empty($guard['allowed'])) {
                return true;
            }
        }
        return false;
    }

    public function getWorkoutReminderSchedule(int $userId, string $scope): array {
        $settings = $this->getSettings($userId);
        $time = $scope === 'today'
            ? ($settings['schedule']['workout_today_time'] ?? '08:00')
            : ($settings['schedule']['workout_tomorrow_time'] ?? '20:00');
        return [
            'event_key' => $scope === 'today' ? 'workout.reminder.today' : 'workout.reminder.tomorrow',
            'time' => $time,
            'hour' => (int) substr($time, 0, 2),
            'minute' => (int) substr($time, 3, 2),
        ];
    }

    public function logDelivery(int $userId, string $eventKey, string $channel, string $status, array $payload = []): void {
        $this->ensureSchema();

        $title = isset($payload['title']) ? mb_substr((string) $payload['title'], 0, 255) : null;
        $body = isset($payload['body']) ? (string) $payload['body'] : null;
        $entityType = isset($payload['entity_type']) ? mb_substr((string) $payload['entity_type'], 0, 64) : null;
        $entityId = isset($payload['entity_id']) ? mb_substr((string) $payload['entity_id'], 0, 64) : null;
        $errorText = isset($payload['error_text']) ? mb_substr((string) $payload['error_text'], 0, 255) : null;

        $stmt = $this->db->prepare("INSERT INTO notification_deliveries (
                user_id, event_key, channel, status, title, body, entity_type, entity_id, error_text
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('issssssss', $userId, $eventKey, $channel, $status, $title, $body, $entityType, $entityId, $errorText);
        $stmt->execute();
        $stmt->close();
    }

    public function getQuietHoursResumeAt(int $userId, ?DateTimeImmutable $referenceUtc = null): ?string {
        $settings = $this->getSettings($userId);
        $quietHours = $settings['quiet_hours'] ?? [];
        if (empty($quietHours['enabled'])) {
            return null;
        }

        $start = $this->normalizeTime($quietHours['start'] ?? '22:00', '22:00');
        $end = $this->normalizeTime($quietHours['end'] ?? '07:00', '07:00');
        if ($start === $end) {
            return null;
        }

        try {
            $userTimezone = new DateTimeZone((string) ($settings['timezone'] ?? 'Europe/Moscow'));
        } catch (Exception $e) {
            $userTimezone = new DateTimeZone('Europe/Moscow');
        }

        $referenceUtc = $referenceUtc ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $localReference = $referenceUtc->setTimezone($userTimezone);
        if (!$this->isInQuietHoursAtTime($localReference, $start, $end)) {
            return $referenceUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $resumeLocal = $this->calculateQuietHoursResumeLocal($localReference, $start, $end);
        return $resumeLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function isInQuietHours(int $userId, ?DateTimeImmutable $referenceUtc = null): bool {
        $settings = $this->getSettings($userId);
        $quietHours = $settings['quiet_hours'] ?? [];
        if (empty($quietHours['enabled'])) {
            return false;
        }

        try {
            $userTimezone = new DateTimeZone((string) ($settings['timezone'] ?? 'Europe/Moscow'));
        } catch (Exception $e) {
            $userTimezone = new DateTimeZone('Europe/Moscow');
        }

        $referenceUtc = $referenceUtc ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $localReference = $referenceUtc->setTimezone($userTimezone);
        $start = $this->normalizeTime($quietHours['start'] ?? '22:00', '22:00');
        $end = $this->normalizeTime($quietHours['end'] ?? '07:00', '07:00');

        return $this->isInQuietHoursAtTime($localReference, $start, $end);
    }

    public function getEmailDigestMode(int $userId): string {
        $settings = $this->getSettings($userId);
        return $this->normalizeEmailDigestMode($settings['channels']['email']['digest_mode'] ?? 'instant');
    }

    public function getNextEmailDigestAt(int $userId, ?DateTimeImmutable $referenceUtc = null): ?string {
        $settings = $this->getSettings($userId);
        $emailChannel = $settings['channels']['email'] ?? [];
        if (empty($emailChannel['enabled']) || empty($emailChannel['available'])) {
            return null;
        }

        try {
            $userTimezone = new DateTimeZone((string) ($settings['timezone'] ?? 'Europe/Moscow'));
        } catch (Exception $e) {
            $userTimezone = new DateTimeZone('Europe/Moscow');
        }

        $referenceUtc = $referenceUtc ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $localReference = $referenceUtc->setTimezone($userTimezone);
        $candidate = $localReference->setTime(9, 0, 0);
        if ($localReference >= $candidate) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function queueDelivery(
        int $userId,
        string $eventKey,
        string $channel,
        string $title,
        string $body,
        array $payload = [],
        ?string $deliverAfterUtc = null
    ): int {
        $this->ensureSchema();

        $deliverAfterUtc = $deliverAfterUtc ?: gmdate('Y-m-d H:i:s');
        $safeTitle = mb_substr($title, 0, 255);
        $safeBody = $body !== '' ? $body : null;
        $link = isset($payload['link']) ? mb_substr((string) $payload['link'], 0, 512) : null;
        $pushData = is_array($payload['push_data'] ?? null) ? $payload['push_data'] : [];
        $pushDataJson = !empty($pushData) ? json_encode($pushData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $emailActionLabel = isset($payload['email_action_label']) ? mb_substr((string) $payload['email_action_label'], 0, 128) : null;
        $entityType = isset($payload['entity_type']) ? $this->normalizeGuardValue((string) $payload['entity_type'], 64) : null;
        $entityId = isset($payload['entity_id']) ? $this->normalizeGuardValue((string) $payload['entity_id'], 128) : null;

        $stmt = $this->db->prepare("INSERT INTO notification_delivery_queue (
                user_id, event_key, channel, title, body, link, push_data_json, email_action_label, entity_type, entity_id, deliver_after
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param(
            'issssssssss',
            $userId,
            $eventKey,
            $channel,
            $safeTitle,
            $safeBody,
            $link,
            $pushDataJson,
            $emailActionLabel,
            $entityType,
            $entityId,
            $deliverAfterUtc
        );
        $stmt->execute();
        $insertId = $stmt->insert_id ? (int) $stmt->insert_id : 0;
        $stmt->close();

        return $insertId;
    }

    public function queueEmailDigestItem(
        int $userId,
        string $eventKey,
        string $title,
        string $body,
        array $payload = [],
        ?string $digestAfterUtc = null
    ): int {
        $this->ensureSchema();

        $digestAfterUtc = $digestAfterUtc ?: $this->getNextEmailDigestAt($userId) ?: gmdate('Y-m-d H:i:s');
        $safeTitle = mb_substr($title, 0, 255);
        $safeBody = $body !== '' ? $body : null;
        $link = isset($payload['link']) ? mb_substr((string) $payload['link'], 0, 512) : null;
        $entityType = isset($payload['entity_type']) ? $this->normalizeGuardValue((string) $payload['entity_type'], 64) : null;
        $entityId = isset($payload['entity_id']) ? $this->normalizeGuardValue((string) $payload['entity_id'], 128) : null;

        $stmt = $this->db->prepare("INSERT INTO notification_email_digest_items (
                user_id, event_key, title, body, link, entity_type, entity_id, digest_after
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param(
            'isssssss',
            $userId,
            $eventKey,
            $safeTitle,
            $safeBody,
            $link,
            $entityType,
            $entityId,
            $digestAfterUtc
        );
        $stmt->execute();
        $insertId = $stmt->insert_id ? (int) $stmt->insert_id : 0;
        $stmt->close();

        return $insertId;
    }

    public function reserveDueEmailDigestUsers(int $limit = 25): array {
        $this->ensureSchema();

        $safeLimit = max(1, min($limit, 100));
        $this->db->query("UPDATE notification_email_digest_items
            SET status = 'pending',
                updated_at = NOW()
            WHERE status = 'processing'
              AND updated_at < (UTC_TIMESTAMP() - INTERVAL 30 MINUTE)");
        $result = $this->db->query("SELECT user_id
            FROM notification_email_digest_items
            WHERE status = 'pending'
              AND digest_after <= UTC_TIMESTAMP()
            GROUP BY user_id
            ORDER BY MIN(digest_after) ASC
            LIMIT {$safeLimit}");
        if (!$result) {
            return [];
        }

        $userIds = [];
        while ($row = $result->fetch_assoc()) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0) {
                $userIds[] = $userId;
            }
        }
        return $userIds;
    }

    public function reserveDueEmailDigestItemsForUser(int $userId): array {
        $this->ensureSchema();

        $stmt = $this->db->prepare("UPDATE notification_email_digest_items
            SET status = 'processing',
                updated_at = NOW()
            WHERE user_id = ?
              AND status = 'pending'
              AND digest_after <= UTC_TIMESTAMP()");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $reservedCount = $stmt->affected_rows;
        $stmt->close();

        if ($reservedCount <= 0) {
            return [];
        }

        $fetchStmt = $this->db->prepare("SELECT
                id,
                event_key,
                title,
                body,
                link,
                entity_type,
                entity_id,
                digest_after,
                created_at
            FROM notification_email_digest_items
            WHERE user_id = ?
              AND status = 'processing'
            ORDER BY created_at ASC, id ASC");
        if (!$fetchStmt) {
            return [];
        }
        $fetchStmt->bind_param('i', $userId);
        $fetchStmt->execute();
        $result = $fetchStmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'event_key' => (string) ($row['event_key'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'body' => (string) ($row['body'] ?? ''),
                'link' => (string) ($row['link'] ?? ''),
                'entity_type' => (string) ($row['entity_type'] ?? ''),
                'entity_id' => (string) ($row['entity_id'] ?? ''),
                'digest_after' => (string) ($row['digest_after'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        $fetchStmt->close();

        return $items;
    }

    public function markEmailDigestItemsCompleted(array $itemIds, string $status = 'sent', ?string $errorText = null): void {
        $this->ensureSchema();

        $safeStatus = in_array($status, ['sent', 'failed', 'skipped'], true) ? $status : 'sent';
        $safeIds = $this->sanitizeIdList($itemIds);
        if (empty($safeIds)) {
            return;
        }

        $idList = implode(',', $safeIds);
        $statusSql = "'" . $this->db->real_escape_string($safeStatus) . "'";
        $this->db->query("UPDATE notification_email_digest_items
            SET status = {$statusSql},
                updated_at = NOW()
            WHERE id IN ({$idList})");
    }

    public function rescheduleEmailDigestItems(array $itemIds, string $digestAfterUtc, ?string $errorText = null): void {
        $this->ensureSchema();

        $safeIds = $this->sanitizeIdList($itemIds);
        if (empty($safeIds)) {
            return;
        }

        $idList = implode(',', $safeIds);
        $afterSql = "'" . $this->db->real_escape_string($digestAfterUtc) . "'";
        $this->db->query("UPDATE notification_email_digest_items
            SET status = 'pending',
                digest_after = {$afterSql},
                updated_at = NOW()
            WHERE id IN ({$idList})");
    }

    public function reserveDueQueuedDeliveries(int $limit = 50): array {
        $this->ensureSchema();

        $safeLimit = max(1, min($limit, 100));
        $this->db->query("UPDATE notification_delivery_queue
            SET status = 'pending',
                updated_at = NOW()
            WHERE status = 'processing'
              AND updated_at < (UTC_TIMESTAMP() - INTERVAL 30 MINUTE)");
        $idsResult = $this->db->query("SELECT id
            FROM notification_delivery_queue
            WHERE status = 'pending'
              AND deliver_after <= UTC_TIMESTAMP()
            ORDER BY deliver_after ASC, id ASC
            LIMIT {$safeLimit}");
        if (!$idsResult) {
            return [];
        }

        $items = [];
        while ($row = $idsResult->fetch_assoc()) {
            $queueId = (int) ($row['id'] ?? 0);
            if ($queueId <= 0) {
                continue;
            }

            $reserveStmt = $this->db->prepare("UPDATE notification_delivery_queue
                SET status = 'processing',
                    attempts = attempts + 1,
                    updated_at = NOW()
                WHERE id = ?
                  AND status = 'pending'");
            if (!$reserveStmt) {
                continue;
            }
            $reserveStmt->bind_param('i', $queueId);
            $reserveStmt->execute();
            $reserved = $reserveStmt->affected_rows > 0;
            $reserveStmt->close();

            if (!$reserved) {
                continue;
            }

            $item = $this->getQueuedDeliveryById($queueId);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function markQueuedDeliveryCompleted(int $queueId, string $status = 'sent', ?string $errorText = null): void {
        $this->ensureSchema();

        $safeStatus = in_array($status, ['sent', 'failed', 'skipped'], true) ? $status : 'sent';
        $safeError = $errorText !== null ? mb_substr($errorText, 0, 255) : null;

        $stmt = $this->db->prepare("UPDATE notification_delivery_queue
            SET status = ?,
                last_error = ?,
                updated_at = NOW()
            WHERE id = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ssi', $safeStatus, $safeError, $queueId);
        $stmt->execute();
        $stmt->close();
    }

    public function rescheduleQueuedDelivery(int $queueId, string $deliverAfterUtc, ?string $errorText = null): void {
        $this->ensureSchema();

        $safeError = $errorText !== null ? mb_substr($errorText, 0, 255) : null;
        $stmt = $this->db->prepare("UPDATE notification_delivery_queue
            SET status = 'pending',
                deliver_after = ?,
                last_error = ?,
                updated_at = NOW()
            WHERE id = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ssi', $deliverAfterUtc, $safeError, $queueId);
        $stmt->execute();
        $stmt->close();
    }

    public function getDeliveryLog(int $userId, int $limit = 12): array {
        $this->ensureSchema();

        $safeLimit = max(1, min($limit, 50));
        $definitions = self::getEventDefinitions();
        $channelLabels = $this->getChannelLabels();
        $statusLabels = $this->getStatusLabels();

        $stmt = $this->db->prepare("SELECT
                id,
                event_key,
                channel,
                status,
                title,
                body,
                entity_type,
                entity_id,
                error_text,
                created_at
            FROM notification_deliveries
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT ?");
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $userId, $safeLimit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $eventKey = (string) ($row['event_key'] ?? '');
            $channel = (string) ($row['channel'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $definition = $definitions[$eventKey] ?? [];
            if (empty($definition) && $eventKey === 'system.email_digest') {
                $definition = [
                    'label' => 'Email-дайджест',
                    'group_label' => 'Системные',
                ];
            }

            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'event_key' => $eventKey,
                'event_label' => (string) ($definition['label'] ?? $eventKey),
                'group_label' => (string) ($definition['group_label'] ?? 'Прочее'),
                'channel' => $channel,
                'channel_label' => (string) ($channelLabels[$channel] ?? $channel),
                'status' => $status,
                'status_label' => (string) ($statusLabels[$status] ?? $status),
                'title' => (string) ($row['title'] ?? ''),
                'body' => (string) ($row['body'] ?? ''),
                'entity_type' => (string) ($row['entity_type'] ?? ''),
                'entity_id' => (string) ($row['entity_id'] ?? ''),
                'error_text' => (string) ($row['error_text'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        $stmt->close();

        return $rows;
    }

    public function acquireDispatchGuard(int $userId, string $eventKey, string $entityType, string $entityId, int $staleAfterSeconds = 1800): bool {
        $this->ensureSchema();

        $entityType = $this->normalizeGuardValue($entityType, 64);
        $entityId = $this->normalizeGuardValue($entityId, 128);
        if ($entityType === '' || $entityId === '') {
            return true;
        }

        $staleThreshold = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . max(60, $staleAfterSeconds) . 'S'))
            ->format('Y-m-d H:i:s');

        $deleteStmt = $this->db->prepare("DELETE FROM notification_dispatch_guards
            WHERE user_id = ?
              AND event_key = ?
              AND entity_type = ?
              AND entity_id = ?
              AND status = 'processing'
              AND updated_at < ?");
        if ($deleteStmt) {
            $deleteStmt->bind_param('issss', $userId, $eventKey, $entityType, $entityId, $staleThreshold);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $insertStmt = $this->db->prepare("INSERT IGNORE INTO notification_dispatch_guards (
                user_id, event_key, entity_type, entity_id, status
            ) VALUES (?, ?, ?, ?, 'processing')");
        if (!$insertStmt) {
            return true;
        }

        $insertStmt->bind_param('isss', $userId, $eventKey, $entityType, $entityId);
        $insertStmt->execute();
        $acquired = $insertStmt->affected_rows > 0;
        $insertStmt->close();

        return $acquired;
    }

    public function markDispatchGuardSent(int $userId, string $eventKey, string $entityType, string $entityId): void {
        $this->ensureSchema();

        $entityType = $this->normalizeGuardValue($entityType, 64);
        $entityId = $this->normalizeGuardValue($entityId, 128);
        if ($entityType === '' || $entityId === '') {
            return;
        }

        $stmt = $this->db->prepare("UPDATE notification_dispatch_guards
            SET status = 'sent',
                sent_at = NOW()
            WHERE user_id = ?
              AND event_key = ?
              AND entity_type = ?
              AND entity_id = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isss', $userId, $eventKey, $entityType, $entityId);
        $stmt->execute();
        $stmt->close();
    }

    public function releaseDispatchGuard(int $userId, string $eventKey, string $entityType, string $entityId): void {
        $this->ensureSchema();

        $entityType = $this->normalizeGuardValue($entityType, 64);
        $entityId = $this->normalizeGuardValue($entityId, 128);
        if ($entityType === '' || $entityId === '') {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM notification_dispatch_guards
            WHERE user_id = ?
              AND event_key = ?
              AND entity_type = ?
              AND entity_id = ?
              AND status = 'processing'");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isss', $userId, $eventKey, $entityType, $entityId);
        $stmt->execute();
        $stmt->close();
    }

    private function buildDefaultSettings(array $user): array {
        $pushTokens = (int) ($user['push_token_count'] ?? 0);
        $webPushSubscriptions = (int) ($user['web_push_subscription_count'] ?? 0);
        $hasTelegram = (int) ($user['telegram_id'] ?? 0) > 0;
        $hasEmail = trim((string) ($user['email'] ?? '')) !== '';
        $telegramDeliveryReady = (new TelegramLoginService($this->db))->isBotConfigured();
        $webPushPublicKey = trim((string) env('WEB_PUSH_VAPID_PUBLIC_KEY', ''));
        $webPushPrivateKey = trim((string) env('WEB_PUSH_VAPID_PRIVATE_KEY', ''));
        $webPushConfigured = $webPushPublicKey !== '' && $webPushPrivateKey !== '';
        $legacyWorkoutEnabled = ((int) ($user['push_workouts_enabled'] ?? 1)) === 1;
        $legacyChatEnabled = ((int) ($user['push_chat_enabled'] ?? 1)) === 1;
        $legacyWorkoutHour = isset($user['push_workout_hour']) ? (int) $user['push_workout_hour'] : 20;
        $legacyWorkoutMinute = isset($user['push_workout_minute']) ? (int) $user['push_workout_minute'] : 0;

        return [
            'channels' => [
                'mobile_push' => [
                    'enabled' => true,
                    'available' => $pushTokens > 0,
                    'connected_devices' => $pushTokens,
                    'delivery_ready' => true,
                ],
                'web_push' => [
                    'enabled' => true,
                    'available' => $webPushSubscriptions > 0,
                    'subscriptions' => $webPushSubscriptions,
                    'subscription_items' => [],
                    'delivery_ready' => $webPushConfigured,
                    'public_key' => $webPushConfigured ? $webPushPublicKey : '',
                ],
                'telegram' => [
                    'enabled' => true,
                    'available' => $hasTelegram,
                    'linked' => $hasTelegram,
                    'delivery_ready' => $telegramDeliveryReady,
                ],
                'email' => [
                    'enabled' => true,
                    'available' => $hasEmail,
                    'delivery_ready' => true,
                    'digest_mode' => 'instant',
                ],
            ],
            'schedule' => [
                'workout_today_time' => '08:00',
                'workout_tomorrow_time' => sprintf('%02d:%02d', $legacyWorkoutHour, $legacyWorkoutMinute),
            ],
            'quiet_hours' => [
                'enabled' => false,
                'start' => '22:00',
                'end' => '07:00',
            ],
            'preferences' => [
                'workout.reminder.today' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'workout.reminder.tomorrow' => [
                    'mobile_push_enabled' => $legacyWorkoutEnabled,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'chat.admin_message' => [
                    'mobile_push_enabled' => $legacyChatEnabled,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'chat.direct_message' => [
                    'mobile_push_enabled' => $legacyChatEnabled,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'chat.ai_message' => [
                    'mobile_push_enabled' => $legacyChatEnabled,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'admin.new_user_message' => [
                    'mobile_push_enabled' => true,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'plan.coach_updated' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'plan.coach_note_added' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'coach.athlete_result_logged' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'plan.weekly_review' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'plan.weekly_adaptation' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'plan.generated' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'plan.recalculated' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'plan.next_generated' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'performance.vdot_updated' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => false,
                ],
                'system.auth_verification_code' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => true,
                ],
                'system.password_reset' => [
                    'mobile_push_enabled' => false,
                    'web_push_enabled' => false,
                    'telegram_enabled' => false,
                    'email_enabled' => true,
                ],
            ],
        ];
    }

    private function getUserContext(int $userId): ?array {
        $stmt = $this->db->prepare("SELECT
                u.id,
                u.role,
                COALESCE(u.email, '') AS email,
                COALESCE(u.telegram_id, 0) AS telegram_id,
                COALESCE(u.timezone, 'Europe/Moscow') AS timezone,
                COALESCE(u.push_workouts_enabled, 1) AS push_workouts_enabled,
                COALESCE(u.push_chat_enabled, 1) AS push_chat_enabled,
                COALESCE(u.push_workout_hour, 20) AS push_workout_hour,
                COALESCE(u.push_workout_minute, 0) AS push_workout_minute,
                (
                    SELECT COUNT(*)
                    FROM push_tokens pt
                    WHERE pt.user_id = u.id
                ) AS push_token_count,
                (
                    SELECT COUNT(*)
                    FROM web_push_subscriptions wps
                    WHERE wps.user_id = u.id
                ) AS web_push_subscription_count
            FROM users u
            WHERE u.id = ?
            LIMIT 1");
        if (!$stmt) {
            $this->throwException('Не удалось загрузить данные пользователя', 500, ['error' => $this->db->error]);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function getChannelSettingsRow(int $userId): ?array {
        $stmt = $this->db->prepare("SELECT
                mobile_push_enabled,
                web_push_enabled,
                telegram_enabled,
                email_enabled,
                quiet_hours_enabled,
                quiet_hours_start,
                quiet_hours_end,
                workout_today_hour,
                workout_today_minute,
                workout_tomorrow_hour,
                workout_tomorrow_minute,
                email_digest_mode
            FROM notification_channel_settings
            WHERE user_id = ?
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function getPreferenceRows(int $userId): array {
        $stmt = $this->db->prepare("SELECT
                event_key,
                mobile_push_enabled,
                web_push_enabled,
                telegram_enabled,
                email_enabled
            FROM notification_preferences
            WHERE user_id = ?");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(string) $row['event_key']] = $row;
        }
        $stmt->close();
        return $rows;
    }

    private function getWebPushSubscriptionItems(int $userId): array {
        $stmt = $this->db->prepare("SELECT
                endpoint,
                COALESCE(user_agent, '') AS user_agent,
                created_at,
                last_seen_at
            FROM web_push_subscriptions
            WHERE user_id = ?
            ORDER BY last_seen_at DESC, id DESC
            LIMIT 12");
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $endpoint = trim((string) ($row['endpoint'] ?? ''));
            if ($endpoint === '') {
                continue;
            }

            $rows[] = [
                'endpoint' => $endpoint,
                'user_agent' => (string) ($row['user_agent'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
            ];
        }
        $stmt->close();

        return $rows;
    }

    private function syncLegacyUserFlags(int $userId): void {
        $settings = $this->getSettings($userId);

        $hasWorkoutPush = !empty($settings['channels']['mobile_push']['enabled'])
            && (
                !empty($settings['preferences']['workout.reminder.today']['mobile_push_enabled'])
                || !empty($settings['preferences']['workout.reminder.tomorrow']['mobile_push_enabled'])
            );

        $hasChatPush = !empty($settings['channels']['mobile_push']['enabled'])
            && (
                !empty($settings['preferences']['chat.admin_message']['mobile_push_enabled'])
                || !empty($settings['preferences']['chat.direct_message']['mobile_push_enabled'])
                || !empty($settings['preferences']['chat.ai_message']['mobile_push_enabled'])
                || !empty($settings['preferences']['admin.new_user_message']['mobile_push_enabled'])
            );

        $tomorrowTime = $this->normalizeTime($settings['schedule']['workout_tomorrow_time'] ?? '20:00', '20:00');
        $pushWorkoutHour = (int) substr($tomorrowTime, 0, 2);
        $pushWorkoutMinute = (int) substr($tomorrowTime, 3, 2);

        $stmt = $this->db->prepare("UPDATE users
            SET push_workouts_enabled = ?,
                push_chat_enabled = ?,
                push_workout_hour = ?,
                push_workout_minute = ?,
                updated_at = NOW()
            WHERE id = ?");
        if (!$stmt) {
            return;
        }

        $pushWorkoutsEnabled = $hasWorkoutPush ? 1 : 0;
        $pushChatEnabled = $hasChatPush ? 1 : 0;
        $stmt->bind_param('iiiii', $pushWorkoutsEnabled, $pushChatEnabled, $pushWorkoutHour, $pushWorkoutMinute, $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function extractChannelEnabled(array $channelInput, string $channel, bool $default): int {
        if (is_array($channelInput[$channel] ?? null)) {
            return $this->normalizeBool($channelInput[$channel]['enabled'] ?? $default);
        }
        if (array_key_exists($channel, $channelInput)) {
            return $this->normalizeBool($channelInput[$channel]);
        }
        return $default ? 1 : 0;
    }

    private function normalizeBool($value): int {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1 ? 1 : 0;
        }
        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return 1;
            }
        }
        return 0;
    }

    private function normalizeTime($value, string $fallback): string {
        $time = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
                return sprintf('%02d:%02d', $hours, $minutes);
            }
        }
        return $fallback;
    }

    private function normalizeStoredTime(string $value, string $fallback): string {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
                return sprintf('%02d:%02d', $hours, $minutes);
            }
        }
        return $fallback;
    }

    private function normalizeGuardValue(string $value, int $limit): string {
        return mb_substr(trim($value), 0, $limit);
    }

    private function getQueuedDeliveryById(int $queueId): ?array {
        $stmt = $this->db->prepare("SELECT
                id,
                user_id,
                event_key,
                channel,
                title,
                body,
                link,
                push_data_json,
                email_action_label,
                entity_type,
                entity_id,
                deliver_after,
                status,
                attempts,
                last_error,
                created_at,
                updated_at
            FROM notification_delivery_queue
            WHERE id = ?
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $pushData = json_decode((string) ($row['push_data_json'] ?? ''), true);
        if (!is_array($pushData)) {
            $pushData = [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'event_key' => (string) ($row['event_key'] ?? ''),
            'channel' => (string) ($row['channel'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'link' => (string) ($row['link'] ?? ''),
            'push_data' => $pushData,
            'email_action_label' => (string) ($row['email_action_label'] ?? ''),
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'deliver_after' => (string) ($row['deliver_after'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'last_error' => (string) ($row['last_error'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function getChannelLabels(): array {
        return [
            'mobile_push' => 'Push на телефон',
            'web_push' => 'Браузер',
            'telegram' => 'Telegram',
            'email' => 'Email',
        ];
    }

    private function getStatusLabels(): array {
        return [
            'sent' => 'Отправлено',
            'failed' => 'Ошибка',
            'skipped' => 'Пропущено',
            'deferred' => 'Отложено',
            'digest' => 'В дайджесте',
        ];
    }

    private function sanitizeIdList(array $ids): array {
        $safeIds = [];
        foreach ($ids as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $safeIds[] = $intId;
            }
        }
        return array_values(array_unique($safeIds));
    }

    private function normalizeEmailDigestMode($value, string $fallback = 'instant'): string {
        $mode = is_string($value) ? trim(mb_strtolower($value)) : '';
        return in_array($mode, ['instant', 'daily'], true) ? $mode : $fallback;
    }

    private function isInQuietHoursAtTime(DateTimeImmutable $localTime, string $start, string $end): bool {
        $currentMinutes = ((int) $localTime->format('G')) * 60 + (int) $localTime->format('i');
        $startMinutes = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
        $endMinutes = ((int) substr($end, 0, 2)) * 60 + (int) substr($end, 3, 2);

        if ($startMinutes === $endMinutes) {
            return false;
        }
        if ($startMinutes < $endMinutes) {
            return $currentMinutes >= $startMinutes && $currentMinutes < $endMinutes;
        }
        return $currentMinutes >= $startMinutes || $currentMinutes < $endMinutes;
    }

    private function calculateQuietHoursResumeLocal(DateTimeImmutable $localTime, string $start, string $end): DateTimeImmutable {
        $currentMinutes = ((int) $localTime->format('G')) * 60 + (int) $localTime->format('i');
        $startMinutes = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
        $endMinutes = ((int) substr($end, 0, 2)) * 60 + (int) substr($end, 3, 2);
        $endHour = (int) substr($end, 0, 2);
        $endMinute = (int) substr($end, 3, 2);

        if ($startMinutes < $endMinutes) {
            return $localTime->setTime($endHour, $endMinute, 0);
        }

        if ($currentMinutes >= $startMinutes) {
            return $localTime->modify('+1 day')->setTime($endHour, $endMinute, 0);
        }

        return $localTime->setTime($endHour, $endMinute, 0);
    }

    private function isInQuietHoursFromSettings(array $settings): bool {
        $quietHours = $settings['quiet_hours'] ?? [];
        if (empty($quietHours['enabled'])) {
            return false;
        }

        $timezone = (string) ($settings['timezone'] ?? 'Europe/Moscow');
        try {
            $now = new DateTime('now', new DateTimeZone($timezone));
        } catch (Exception $e) {
            $now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
        }

        $start = $this->normalizeTime($quietHours['start'] ?? '22:00', '22:00');
        $end = $this->normalizeTime($quietHours['end'] ?? '07:00', '07:00');

        return $this->isInQuietHoursAtTime($now instanceof DateTimeImmutable ? $now : DateTimeImmutable::createFromMutable($now), $start, $end);
    }
}
