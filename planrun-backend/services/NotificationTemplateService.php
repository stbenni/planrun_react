<?php

require_once __DIR__ . '/BaseService.php';

class NotificationTemplateService extends BaseService {
    private static bool $schemaEnsured = false;
    private ?array $overrideCache = null;

    public function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS notification_template_overrides (
            event_key VARCHAR(96) NOT NULL PRIMARY KEY,
            title_template VARCHAR(255) NULL DEFAULT NULL,
            body_template TEXT NULL,
            link_template VARCHAR(512) NULL DEFAULT NULL,
            email_action_label_template VARCHAR(128) NULL DEFAULT NULL,
            updated_by INT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_notification_template_overrides_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$this->db->query($sql)) {
            $this->throwException('Не удалось подготовить таблицу шаблонов уведомлений', 500, [
                'error' => $this->db->error,
            ]);
        }

        self::$schemaEnsured = true;
    }

    public function prepare(string $eventKey, string $title, string $body, array $options = []): array {
        $this->ensureSchema();

        $eventKey = trim($eventKey);
        $title = trim($title);
        $body = trim($body);

        $template = $this->getDefaultRuntimeTemplate($eventKey, $options);
        $context = $this->buildTemplateContext($eventKey, $title, $body, $template, $options);
        $override = $this->getOverride($eventKey);

        if (!empty($override['title_template'])) {
            $title = $this->renderTemplate((string) $override['title_template'], $context);
        } elseif ($this->shouldReplaceTitle($eventKey, $title, $template['title'] ?? '')) {
            $title = (string) ($template['title'] ?? '');
        }

        if (!empty($override['body_template'])) {
            $body = $this->renderTemplate((string) $override['body_template'], $context);
        } elseif ($body === '' && !empty($template['body'])) {
            $body = (string) $template['body'];
        }

        $link = trim((string) ($options['link'] ?? ''));
        if ($link === '' && !empty($template['link'])) {
            $link = (string) $template['link'];
        }
        if (!empty($override['link_template'])) {
            $link = $this->renderTemplate((string) $override['link_template'], $context);
        }

        $emailActionLabel = trim((string) ($options['email_action_label'] ?? ''));
        if ($emailActionLabel === '' && !empty($template['email_action_label'])) {
            $emailActionLabel = (string) $template['email_action_label'];
        }
        if (!empty($override['email_action_label_template'])) {
            $emailActionLabel = $this->renderTemplate((string) $override['email_action_label_template'], $context);
        }

        $pushData = is_array($options['push_data'] ?? null) ? $options['push_data'] : [];
        $pushDefaults = is_array($template['push_data'] ?? null) ? $template['push_data'] : [];
        $pushData = array_replace($pushDefaults, $pushData);
        if ($link !== '' && empty($pushData['link'])) {
            $pushData['link'] = $link;
        }

        $nextOptions = $options;
        $nextOptions['link'] = $link;
        $nextOptions['push_data'] = $pushData;
        if ($emailActionLabel !== '') {
            $nextOptions['email_action_label'] = $emailActionLabel;
        }

        return [
            'title' => trim($title),
            'body' => trim($body),
            'options' => $nextOptions,
        ];
    }

    public function getAdminTemplateCatalog(): array {
        $this->ensureSchema();

        require_once __DIR__ . '/NotificationSettingsService.php';

        $editableDefinitions = self::getEditableDefinitionMap();
        $overrides = $this->getAllOverrides();
        $groups = [];

        foreach (NotificationSettingsService::getEventCatalog() as $group) {
            $events = [];
            foreach (($group['events'] ?? []) as $event) {
                $eventKey = (string) ($event['event_key'] ?? '');
                if ($eventKey === '' || !isset($editableDefinitions[$eventKey])) {
                    continue;
                }

                $definition = $editableDefinitions[$eventKey];
                $override = $overrides[$eventKey] ?? [];

                $events[] = [
                    'event_key' => $eventKey,
                    'label' => (string) ($event['label'] ?? $eventKey),
                    'description' => (string) ($event['description'] ?? ''),
                    'placeholders' => $definition['placeholders'],
                    'defaults' => [
                        'title_template' => (string) ($definition['title_template'] ?? ''),
                        'body_template' => (string) ($definition['body_template'] ?? ''),
                        'link_template' => (string) ($definition['link_template'] ?? ''),
                        'email_action_label_template' => (string) ($definition['email_action_label_template'] ?? ''),
                    ],
                    'overrides' => [
                        'title_template' => (string) ($override['title_template'] ?? ''),
                        'body_template' => (string) ($override['body_template'] ?? ''),
                        'link_template' => (string) ($override['link_template'] ?? ''),
                        'email_action_label_template' => (string) ($override['email_action_label_template'] ?? ''),
                    ],
                    'has_override' => !empty($override),
                    'updated_at' => (string) ($override['updated_at'] ?? ''),
                    'updated_by' => isset($override['updated_by']) ? (int) $override['updated_by'] : null,
                ];
            }

            if (!empty($events)) {
                $groups[] = [
                    'key' => (string) ($group['key'] ?? ''),
                    'label' => (string) ($group['label'] ?? ''),
                    'description' => (string) ($group['description'] ?? ''),
                    'events' => $events,
                ];
            }
        }

        return $groups;
    }

    public function saveOverride(string $eventKey, array $payload, int $adminUserId): array {
        $this->ensureSchema();

        $eventKey = trim($eventKey);
        $definitions = self::getEditableDefinitionMap();
        if ($eventKey === '' || !isset($definitions[$eventKey])) {
            $this->throwValidationException('Неизвестный event_key', ['event_key' => 'unsupported']);
        }

        $titleTemplate = $this->sanitizeNullableTemplate($payload['title_template'] ?? null, 255);
        $bodyTemplate = $this->sanitizeNullableTemplate($payload['body_template'] ?? null, 8000);
        $linkTemplate = $this->sanitizeNullableTemplate($payload['link_template'] ?? null, 512);
        $emailActionLabelTemplate = $this->sanitizeNullableTemplate($payload['email_action_label_template'] ?? null, 128);

        if ($titleTemplate === null && $bodyTemplate === null && $linkTemplate === null && $emailActionLabelTemplate === null) {
            $this->resetOverride($eventKey);
            return $this->getTemplateConfigByEventKey($eventKey);
        }

        $stmt = $this->db->prepare("INSERT INTO notification_template_overrides (
                event_key,
                title_template,
                body_template,
                link_template,
                email_action_label_template,
                updated_by
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title_template = VALUES(title_template),
                body_template = VALUES(body_template),
                link_template = VALUES(link_template),
                email_action_label_template = VALUES(email_action_label_template),
                updated_by = VALUES(updated_by)");
        if (!$stmt) {
            $this->throwException('Не удалось сохранить шаблон уведомления', 500, ['error' => $this->db->error]);
        }

        $stmt->bind_param(
            'sssssi',
            $eventKey,
            $titleTemplate,
            $bodyTemplate,
            $linkTemplate,
            $emailActionLabelTemplate,
            $adminUserId
        );
        $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        if ($error) {
            $this->throwException('Не удалось сохранить шаблон уведомления', 500, ['error' => $error]);
        }

        $this->overrideCache = null;
        return $this->getTemplateConfigByEventKey($eventKey);
    }

    public function resetOverride(string $eventKey): void {
        $this->ensureSchema();

        $eventKey = trim($eventKey);
        if ($eventKey === '') {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM notification_template_overrides WHERE event_key = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $eventKey);
        $stmt->execute();
        $stmt->close();

        $this->overrideCache = null;
    }

    public function getTemplateConfigByEventKey(string $eventKey): array {
        $eventKey = trim($eventKey);
        foreach ($this->getAdminTemplateCatalog() as $group) {
            foreach (($group['events'] ?? []) as $event) {
                if (($event['event_key'] ?? '') === $eventKey) {
                    return $event;
                }
            }
        }

        $this->throwNotFoundException('Шаблон уведомления не найден');
    }

    private function getDefaultRuntimeTemplate(string $eventKey, array $options): array {
        $planDate = $this->contextValue($options, 'plan_date');
        $athleteSlug = $this->contextValue($options, 'athlete_slug');
        $athleteName = $this->contextValue($options, 'athlete_name');
        $planAction = $this->contextValue($options, 'plan_action');
        $sourceType = $this->contextValue($options, 'source_type');

        return match ($eventKey) {
            'workout.reminder.today' => [
                'title' => 'Сегодня тренировка',
                'link' => $this->buildCalendarLink($this->contextValue($options, 'workout_date')),
                'email_action_label' => 'Открыть тренировку',
                'push_data' => ['type' => 'workout'],
            ],
            'workout.reminder.tomorrow' => [
                'title' => 'Завтра: тренировка',
                'link' => $this->buildCalendarLink($this->contextValue($options, 'workout_date')),
                'email_action_label' => 'Открыть тренировку',
                'push_data' => ['type' => 'workout'],
            ],
            'chat.admin_message' => [
                'title' => 'Новое сообщение от администрации',
                'link' => '/chat',
                'email_action_label' => 'Открыть чат',
                'push_data' => ['type' => 'chat'],
            ],
            'chat.direct_message' => [
                'title' => 'Новое сообщение от пользователя',
                'link' => '/chat',
                'email_action_label' => 'Открыть чат',
                'push_data' => ['type' => 'chat'],
            ],
            'chat.ai_message' => [
                'title' => 'Новое сообщение от AI-тренера',
                'link' => '/chat',
                'email_action_label' => 'Открыть чат',
                'push_data' => ['type' => 'chat'],
            ],
            'admin.new_user_message' => [
                'title' => 'Новое сообщение в admin-чате',
                'link' => '/chat',
                'email_action_label' => 'Открыть чат',
                'push_data' => ['type' => 'chat'],
            ],
            'plan.coach_updated' => [
                'title' => $this->buildPlanUpdateTitle($planAction),
                'link' => $this->buildCalendarLink($planDate),
                'email_action_label' => 'Открыть план',
                'push_data' => ['type' => 'plan'],
            ],
            'plan.coach_note_added' => [
                'title' => 'Тренер оставил заметку',
                'link' => $this->buildCalendarLink($planDate),
                'email_action_label' => 'Открыть план',
                'push_data' => ['type' => 'plan'],
            ],
            'coach.athlete_result_logged' => [
                'title' => $this->buildAthleteResultTitle($athleteName),
                'link' => $this->buildCalendarLink($planDate, $athleteSlug),
                'email_action_label' => $athleteSlug !== '' ? 'Открыть календарь атлета' : 'Открыть календарь',
                'push_data' => ['type' => 'plan'],
            ],
            'plan.weekly_review' => [
                'title' => 'Еженедельный обзор готов',
                'link' => '/chat',
                'email_action_label' => 'Открыть обзор',
                'push_data' => ['type' => 'chat'],
            ],
            'plan.weekly_adaptation' => [
                'title' => 'Недельная адаптация готова',
                'link' => '/chat',
                'email_action_label' => 'Открыть обзор',
                'push_data' => ['type' => 'chat'],
            ],
            'plan.generated' => [
                'title' => 'План сгенерирован',
                'link' => '/chat',
                'email_action_label' => 'Открыть чат',
                'push_data' => ['type' => 'chat'],
            ],
            'plan.recalculated' => [
                'title' => 'План пересчитан',
                'link' => '/chat',
                'email_action_label' => 'Открыть чат',
                'push_data' => ['type' => 'chat'],
            ],
            'plan.next_generated' => [
                'title' => 'Следующий план готов',
                'link' => '/chat',
                'email_action_label' => 'Открыть чат',
                'push_data' => ['type' => 'chat'],
            ],
            'performance.vdot_updated' => [
                'title' => $this->buildVdotTitle($sourceType),
                'link' => '/chat',
                'email_action_label' => 'Открыть чат',
                'push_data' => ['type' => 'chat'],
            ],
            default => [
                'title' => trim((string) ($options['title'] ?? '')),
                'link' => trim((string) ($options['link'] ?? '')),
                'email_action_label' => trim((string) ($options['email_action_label'] ?? 'Открыть в PlanRun')),
                'push_data' => is_array($options['push_data'] ?? null) ? $options['push_data'] : [],
            ],
        };
    }

    private static function getEditableDefinitionMap(): array {
        return [
            'workout.reminder.today' => [
                'title_template' => 'Сегодня тренировка',
                'body_template' => '{{body}}',
                'link_template' => '/calendar?date={{workout_date}}',
                'email_action_label_template' => 'Открыть тренировку',
                'placeholders' => ['body', 'workout_date'],
            ],
            'workout.reminder.tomorrow' => [
                'title_template' => 'Завтра: тренировка',
                'body_template' => '{{body}}',
                'link_template' => '/calendar?date={{workout_date}}',
                'email_action_label_template' => 'Открыть тренировку',
                'placeholders' => ['body', 'workout_date'],
            ],
            'chat.admin_message' => [
                'title_template' => 'Новое сообщение от администрации',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть чат',
                'placeholders' => ['body'],
            ],
            'chat.direct_message' => [
                'title_template' => 'Новое сообщение от {{sender_name}}',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть чат',
                'placeholders' => ['body', 'sender_name'],
            ],
            'chat.ai_message' => [
                'title_template' => 'Новое сообщение от AI-тренера',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть чат',
                'placeholders' => ['body'],
            ],
            'admin.new_user_message' => [
                'title_template' => 'Новое сообщение от {{sender_name}}',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть чат',
                'placeholders' => ['body', 'sender_name'],
            ],
            'plan.coach_updated' => [
                'title_template' => '{{plan_update_title}}',
                'body_template' => '{{body}}',
                'link_template' => '/calendar?date={{plan_date}}',
                'email_action_label_template' => 'Открыть план',
                'placeholders' => ['body', 'plan_action', 'plan_date', 'plan_update_title'],
            ],
            'plan.coach_note_added' => [
                'title_template' => 'Тренер оставил заметку',
                'body_template' => '{{body}}',
                'link_template' => '/calendar?date={{plan_date}}',
                'email_action_label_template' => 'Открыть план',
                'placeholders' => ['body', 'plan_date'],
            ],
            'coach.athlete_result_logged' => [
                'title_template' => '{{athlete_result_title}}',
                'body_template' => '{{body}}',
                'link_template' => '/calendar?athlete={{athlete_slug}}&date={{plan_date}}',
                'email_action_label_template' => 'Открыть календарь атлета',
                'placeholders' => ['body', 'athlete_name', 'athlete_slug', 'plan_date', 'athlete_result_title'],
            ],
            'plan.weekly_review' => [
                'title_template' => 'Еженедельный обзор готов',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть обзор',
                'placeholders' => ['body'],
            ],
            'plan.weekly_adaptation' => [
                'title_template' => 'Недельная адаптация готова',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть обзор',
                'placeholders' => ['body'],
            ],
            'plan.generated' => [
                'title_template' => 'План сгенерирован',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть чат',
                'placeholders' => ['body'],
            ],
            'plan.recalculated' => [
                'title_template' => 'План пересчитан',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть чат',
                'placeholders' => ['body'],
            ],
            'plan.next_generated' => [
                'title_template' => 'Следующий план готов',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть чат',
                'placeholders' => ['body'],
            ],
            'performance.vdot_updated' => [
                'title_template' => '{{vdot_title}}',
                'body_template' => '{{body}}',
                'link_template' => '/chat',
                'email_action_label_template' => 'Открыть чат',
                'placeholders' => ['body', 'source_type', 'vdot_title'],
            ],
        ];
    }

    private function shouldReplaceTitle(string $eventKey, string $currentTitle, string $templateTitle): bool {
        if ($templateTitle === '') {
            return false;
        }

        if ($currentTitle === '') {
            return true;
        }

        $normalized = mb_strtolower(trim($currentTitle));
        if ($normalized === '') {
            return true;
        }

        $genericTitles = match ($eventKey) {
            'plan.coach_updated', 'plan.coach_note_added', 'coach.athlete_result_logged' => [
                'обновление плана',
            ],
            'plan.generated', 'plan.recalculated', 'plan.next_generated', 'plan.weekly_review', 'plan.weekly_adaptation', 'performance.vdot_updated' => [
                'новое сообщение от ai-тренера',
                'vdot обновлён',
            ],
            'workout.reminder.tomorrow' => [
                'завтра: тренировка',
            ],
            default => [],
        };

        return in_array($normalized, $genericTitles, true);
    }

    private function buildTemplateContext(string $eventKey, string $title, string $body, array $template, array $options): array {
        $planAction = $this->contextValue($options, 'plan_action');
        $athleteName = $this->contextValue($options, 'athlete_name');
        $sourceType = $this->contextValue($options, 'source_type');

        return [
            'app_name' => 'PlanRun',
            'event_key' => $eventKey,
            'title' => $title !== '' ? $title : (string) ($template['title'] ?? ''),
            'body' => $body,
            'link' => trim((string) ($options['link'] ?? ($template['link'] ?? ''))),
            'sender_name' => $this->contextValue($options, 'sender_name') ?: 'пользователя',
            'plan_action' => $planAction,
            'plan_date' => $this->contextValue($options, 'plan_date'),
            'workout_date' => $this->contextValue($options, 'workout_date'),
            'athlete_name' => $athleteName,
            'athlete_slug' => $this->contextValue($options, 'athlete_slug'),
            'source_type' => $sourceType,
            'plan_update_title' => $this->buildPlanUpdateTitle($planAction),
            'athlete_result_title' => $this->buildAthleteResultTitle($athleteName),
            'vdot_title' => $this->buildVdotTitle($sourceType),
        ];
    }

    private function buildPlanUpdateTitle(string $planAction): string {
        return match ($planAction) {
            'add' => 'Тренер добавил тренировку',
            'delete' => 'Тренер удалил тренировку',
            'copy' => 'Тренер скопировал тренировку',
            default => 'Тренер обновил план',
        };
    }

    private function buildAthleteResultTitle(string $athleteName): string {
        return $athleteName !== '' ? ($athleteName . ': новый результат') : 'Атлет внёс результат';
    }

    private function buildVdotTitle(string $sourceType): string {
        return match ($sourceType) {
            'race' => 'VDOT обновлён после забега',
            'control' => 'VDOT обновлён после контрольной',
            default => 'VDOT обновлён',
        };
    }

    private function buildCalendarLink(string $date = '', string $athleteSlug = ''): string {
        $params = [];
        if ($athleteSlug !== '') {
            $params['athlete'] = $athleteSlug;
        }
        if ($date !== '') {
            $params['date'] = $date;
        }

        return '/calendar' . (!empty($params) ? ('?' . http_build_query($params)) : '');
    }

    private function contextValue(array $options, string $key): string {
        $templateContext = is_array($options['template_context'] ?? null) ? $options['template_context'] : [];
        $value = $templateContext[$key] ?? ($options[$key] ?? '');
        return trim((string) $value);
    }

    private function renderTemplate(string $template, array $context): string {
        return trim((string) preg_replace_callback('/{{\s*([a-z0-9_]+)\s*}}/i', static function (array $matches) use ($context) {
            $key = (string) ($matches[1] ?? '');
            return array_key_exists($key, $context) ? (string) $context[$key] : '';
        }, $template));
    }

    private function getOverride(string $eventKey): array {
        $overrides = $this->getAllOverrides();
        return $overrides[$eventKey] ?? [];
    }

    private function getAllOverrides(): array {
        $this->ensureSchema();

        if ($this->overrideCache !== null) {
            return $this->overrideCache;
        }

        $result = $this->db->query("SELECT
                event_key,
                title_template,
                body_template,
                link_template,
                email_action_label_template,
                updated_by,
                updated_at
            FROM notification_template_overrides");
        if (!$result) {
            $this->overrideCache = [];
            return $this->overrideCache;
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $eventKey = (string) ($row['event_key'] ?? '');
            if ($eventKey === '') {
                continue;
            }

            $rows[$eventKey] = [
                'title_template' => $row['title_template'] !== null ? (string) $row['title_template'] : null,
                'body_template' => $row['body_template'] !== null ? (string) $row['body_template'] : null,
                'link_template' => $row['link_template'] !== null ? (string) $row['link_template'] : null,
                'email_action_label_template' => $row['email_action_label_template'] !== null ? (string) $row['email_action_label_template'] : null,
                'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        $this->overrideCache = $rows;
        return $this->overrideCache;
    }

    private function sanitizeNullableTemplate($value, int $maxLength): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, max(1, $maxLength));
    }
}
