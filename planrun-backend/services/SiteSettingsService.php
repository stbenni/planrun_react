<?php
/**
 * Сервис для управления настройками сайта (site_settings).
 */

require_once __DIR__ . '/BaseService.php';

class SiteSettingsService extends BaseService {

    private const ON_OFF_KEYS = ['maintenance_mode', 'registration_enabled'];

    private const DEFAULTS = [
        'site_name' => 'PlanRun',
        'site_description' => 'Персональный план беговых тренировок',
        'maintenance_mode' => '0',
        'registration_enabled' => '1',
        'contact_email' => '',
    ];

    /**
     * Загрузить все настройки (DB + defaults).
     */
    public function getAll(): array {
        $settings = self::DEFAULTS;

        $tableExists = $this->db->query("SHOW TABLES LIKE 'site_settings'");
        if ($tableExists && $tableExists->num_rows > 0) {
            $res = $this->db->query("SELECT `key`, value FROM site_settings");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $settings[$row['key']] = $row['value'];
                }
            }
        }

        foreach (self::ON_OFF_KEYS as $k) {
            if (isset($settings[$k])) {
                $v = $settings[$k];
                $settings[$k] = ($v === true || $v === '1' || $v === 1) ? '1' : '0';
            }
        }

        return $settings;
    }

    /**
     * Обновить настройки. Принимает ассоциативный массив key => value.
     */
    public function update(array $settings): void {
        $allowed = array_keys(self::DEFAULTS);

        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            if (in_array($key, self::ON_OFF_KEYS, true)) {
                $value = ($value === true || $value === '1' || $value === 1) ? '1' : '0';
            } else {
                $value = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
            }

            $stmt = $this->db->prepare(
                "INSERT INTO site_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?"
            );
            $stmt->bind_param('sss', $key, $value, $value);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Получить список допустимых ключей.
     */
    public function getAllowedKeys(): array {
        return array_keys(self::DEFAULTS);
    }
}
