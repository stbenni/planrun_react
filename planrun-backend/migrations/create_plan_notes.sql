-- Заметки к дню тренировки (коммуникация тренер ↔ атлет)
CREATE TABLE IF NOT EXISTS plan_day_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,           -- чей календарь
  author_id INT NOT NULL,         -- кто написал (тренер или атлет)
  date DATE NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_date (user_id, date),
  KEY idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заметки к неделе тренировок
CREATE TABLE IF NOT EXISTS plan_week_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,           -- чей календарь
  author_id INT NOT NULL,         -- кто написал
  week_start DATE NOT NULL,       -- понедельник недели
  content TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_week (user_id, week_start),
  KEY idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
