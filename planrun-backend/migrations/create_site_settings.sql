-- Настройки сайта для админки (key-value)
CREATE TABLE IF NOT EXISTS site_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(128) NOT NULL,
    value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Примеры настроек (можно вставить при первом запуске)
-- INSERT INTO site_settings (`key`, value) VALUES ('site_name', 'PlanRun'), ('maintenance_mode', '0'), ('registration_enabled', '1') ON DUPLICATE KEY UPDATE value = value;
