-- Миграция: таблицы для раздела «Тренеры»
-- Дата: 2026-03-04

-- Запросы от атлетов к тренерам
CREATE TABLE IF NOT EXISTS coach_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  coach_id INT NOT NULL,
  status ENUM('pending','accepted','rejected') DEFAULT 'pending',
  message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  responded_at TIMESTAMP NULL,
  KEY idx_coach_status (coach_id, status),
  KEY idx_user_coach (user_id, coach_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заявки «Стать тренером»
CREATE TABLE IF NOT EXISTS coach_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  coach_specialization JSON,
  coach_bio TEXT,
  coach_philosophy VARCHAR(500) NULL,
  coach_experience_years TINYINT UNSIGNED NULL,
  coach_runner_achievements TEXT NULL,
  coach_athlete_achievements TEXT NULL,
  coach_certifications TEXT NULL,
  coach_contacts_extra VARCHAR(255) NULL,
  coach_accepts_new TINYINT(1) DEFAULT 1,
  coach_prices_on_request TINYINT(1) DEFAULT 0,
  coach_pricing_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_at TIMESTAMP NULL,
  reviewed_by INT NULL,
  KEY idx_status (status),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Стоимость услуг тренера
CREATE TABLE IF NOT EXISTS coach_pricing (
  id INT AUTO_INCREMENT PRIMARY KEY,
  coach_id INT NOT NULL,
  type ENUM('individual','group','consultation','custom') NOT NULL,
  label VARCHAR(255) NOT NULL,
  price DECIMAL(10,2) NULL,
  currency VARCHAR(3) DEFAULT 'RUB',
  period ENUM('month','week','one_time','custom') DEFAULT 'month',
  sort_order INT DEFAULT 0,
  KEY idx_coach (coach_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Новые поля в users для тренера
-- Выполнять по одному, чтобы не ломалось при повторном запуске
ALTER TABLE users ADD COLUMN coach_bio TEXT NULL;
ALTER TABLE users ADD COLUMN coach_specialization JSON NULL;
ALTER TABLE users ADD COLUMN coach_accepts TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN coach_prices_on_request TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN coach_experience_years TINYINT UNSIGNED NULL;
ALTER TABLE users ADD COLUMN coach_philosophy VARCHAR(500) NULL;
